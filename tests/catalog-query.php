<?php
// Execute the public callbacks with explicit WordPress/WooCommerce query doubles.
// No WordPress install, SQL connection, HTTP requests or product index is used.
namespace Automattic\WooCommerce\StoreApi\Schemas\V1 {
    class ProductSchema {}
}
namespace Automattic\WooCommerce\StoreApi {
    class SchemaController {
        public function get($name) {
            if ($name !== 'product') throw new \RuntimeException('Unexpected schema request');
            return new Schemas\V1\ProductSchema();
        }
    }
    class StoreApi {
        public static function container() {
            return new class {
                public function get($name) {
                    if ($name !== SchemaController::class) throw new \RuntimeException('Unexpected container request');
                    return new SchemaController();
                }
            };
        }
    }
}
namespace {
    define('ABSPATH', __DIR__);
    $wpdb = (object) array('posts' => 'wp_posts', 'last_error' => '');
    $query_results = array();
    $query_calls = array();
    $filters = array();

    function add_filter($tag, $callback, $priority, $accepted_args) {
        $GLOBALS['filters'][$tag][$priority][spl_object_id($callback)] = $callback;
    }
    function remove_filter($tag, $callback, $priority) {
        unset($GLOBALS['filters'][$tag][$priority][spl_object_id($callback)]);
    }
    function active_filters() {
        $count = 0;
        foreach ($GLOBALS['filters'] as $priorities) {
            foreach ($priorities as $callbacks) $count += count($callbacks);
        }
        return $count;
    }
    function is_wp_error($value) { return $value instanceof WP_Error; }
    function taxonomy_exists($name) { return $name === 'product_visibility'; }
    function wc_get_product($id) { throw new RuntimeException('Empty query must not serialize a product'); }

    class WP_Error {
        public function __construct(public $code, public $message, public $data) {}
        public function get_error_code() { return $this->code; }
        public function get_error_data() { return $this->data; }
    }
    class WP_REST_Response {
        public $headers = array();
        public function __construct(public $data, public $status = 200) {}
        public function header($name, $value) { $this->headers[$name] = $value; }
    }
    class WP_REST_Request {
        public function __construct(private $params) {}
        public function get_query_params() { return $this->params; }
        public function get_body_params() { return array(); }
    }
    class WP_Query {
        public $posts = array();
        public $found_posts = 0;
        public function query($args) {
            $GLOBALS['query_calls'][] = array('args' => $args, 'filters' => active_filters());
            if (!$GLOBALS['query_results']) throw new RuntimeException('Unexpected query');
            $result = array_shift($GLOBALS['query_results']);
            $GLOBALS['wpdb']->last_error = $result['error'] ?? '';
            $this->posts = $result['posts'] ?? array();
            $this->found_posts = $result['total'] ?? 0;
            return $this->posts;
        }
    }

    require __DIR__ . '/../integration/wordpress/catalog.php';

    function check($condition, $message) {
        if (!$condition) throw new RuntimeException($message);
    }
    function run_callback($callback, $params, $results) {
        check(active_filters() === 0, 'Previous request left query filters installed');
        $GLOBALS['query_results'] = $results;
        $GLOBALS['query_calls'] = array();
        $GLOBALS['wpdb']->last_error = '';
        $response = $callback(new WP_REST_Request($params));
        check(active_filters() === 0, 'Request left query filters installed after completion/failure');
        check(!$GLOBALS['query_results'], 'Expected query did not run');
        foreach ($GLOBALS['query_calls'] as $call) {
            check($call['filters'] === 2, 'Each query needs exactly its own search and price filters');
        }
        return $response;
    }
    function check_unavailable($response) {
        check($response instanceof WP_Error, 'SQL failure became a successful empty catalog');
        check($response->get_error_code() === 'gpante_support_catalog_unavailable', 'SQL failure needs the existing public error code');
        check($response->get_error_data()['status'] === 503, 'SQL failure must return HTTP 503');
        check(!str_contains(json_encode($response), 'fixture-private-sql'), 'Public error exposed a database error');
    }

    $cases = array();
    foreach (array('products' => 5, 'knowledge' => 3) as $resource => $per_page) {
        $callback = 'gpante_support_catalog_' . $resource;
        $cases[$resource . ': silent primary SQL failure'] = static function () use ($callback) {
            $response = run_callback($callback, array(), array(array('error' => 'fixture-private-sql failure')));
            check_unavailable($response);
            check(count($GLOBALS['query_calls']) === 1, 'Primary SQL failure must stop before an out-of-range fallback');
        };
        $cases[$resource . ': healthy empty first page'] = static function () use ($callback) {
            $response = run_callback($callback, array(), array(array()));
            check($response instanceof WP_REST_Response && $response->status === 200, 'Healthy empty query must remain successful');
            check($response->data === array(), 'Healthy empty query must return an empty list');
            check($response->headers['X-WP-Total'] === 0 && $response->headers['X-WP-TotalPages'] === 0, 'Healthy empty query needs zero totals');
        };
        $cases[$resource . ': silent out-of-range fallback SQL failure'] = static function () use ($callback) {
            $response = run_callback($callback, array('page' => '4'), array(array(), array('error' => 'fixture-private-sql count failure')));
            check_unavailable($response);
            check(count($GLOBALS['query_calls']) === 2, 'Out-of-range total fallback must run once');
            check($GLOBALS['query_calls'][1]['args']['fields'] === 'ids' && $GLOBALS['query_calls'][1]['args']['paged'] === 1, 'Fallback must count the first filtered page');
        };
        $cases[$resource . ': healthy out-of-range total fallback'] = static function () use ($callback, $per_page) {
            $response = run_callback($callback, array('page' => '6'), array(array(), array('total' => 12)));
            check($response instanceof WP_REST_Response && $response->status === 200 && $response->data === array(), 'Healthy out-of-range query remains an empty success');
            check($response->headers['X-WP-Total'] === 12 && $response->headers['X-WP-TotalPages'] === (int) ceil(12 / $per_page), 'Healthy fallback must preserve filtered pagination totals');
        };
    }
    $failures = 0;
    foreach ($cases as $name => $case) {
        try {
            $case();
            fwrite(STDOUT, "PASS $name\n");
        } catch (\Throwable $error) {
            $failures++;
            fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
        }
    }
    if ($failures) exit(1);
    fwrite(STDOUT, count($cases) . " public catalog query checks passed.\n");
}
