<?php
// Execute the public callbacks with explicit WordPress/WooCommerce query doubles.
// No WordPress install, SQL connection, HTTP requests or product index is used.
namespace Automattic\WooCommerce\StoreApi\Schemas\V1 {
    class ProductSchema {
        public function get_item_response($product) { return $GLOBALS['schema_data']; }
    }
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
    $wpdb = new class {
        public $posts = 'wp_posts';
        public $last_error = '';
        public function esc_like($value) { return addcslashes($value, '_%\\'); }
        public function prepare($query, ...$values) {
            foreach ($values as $value) $query = preg_replace('/%s/', "'" . str_replace("'", "''", $value) . "'", $query, 1);
            return $query;
        }
    };
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
    function taxonomy_exists($name) { return in_array($name, ['product_visibility', 'product_cat', 'category'], true); }
    function term_read($name, $result, $args) {
        $GLOBALS['term_calls'][] = ['name' => $name, 'args' => $args];
        $GLOBALS['wpdb']->last_error = $GLOBALS['term_errors'][$name] ?? '';
        return $GLOBALS['wpdb']->last_error === '' ? $result : [];
    }
    function term_exists($id, $taxonomy) { return term_read(__FUNCTION__, $id !== 999 ? $id : null, func_get_args()); }
    function wp_get_object_terms($ids, $taxonomy, $args) { return term_read(__FUNCTION__, $GLOBALS['term_ids'] ?? [12], func_get_args()); }
    function get_ancestors($id, $taxonomy, $type) { return term_read(__FUNCTION__, $id === 12 ? [10] : [], func_get_args()); }
    function get_post_time($format, $gmt, $post) { return '2026-10-21T10:00:00Z'; }
    function get_terms($args) { return term_read(__FUNCTION__, $GLOBALS['term_records'] ?? [], func_get_args()); }
    function get_term_link($term) { return 'https://gpante.com/product-category/cat-' . $term->term_id . '/'; }
    function WC() {
        return (object) ['query' => new class {
            public function order_by_price_asc_post_clauses($clauses) { $clauses['orderby'] = 'price ASC, product_id ASC'; return $clauses; }
            public function order_by_popularity_post_clauses($clauses) { $clauses['orderby'] = 'wc_product_meta_lookup.total_sales DESC, product_id DESC'; return $clauses; }
        }];
    }
    function apply_query_filters($tag, $value, $query) {
        foreach ($GLOBALS['filters'][$tag] ?? [] as $callbacks) {
            foreach ($callbacks as $callback) $value = $callback($value, $query);
        }
        return $value;
    }
    function wc_get_product($id) {
        return new class {
            public function get_status() { return 'publish'; }
            public function get_type() { return 'simple'; }
            public function get_catalog_visibility() { return 'visible'; }
        };
    }

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
            $search = apply_query_filters('posts_search', 'unfiltered-search', $this);
            $clauses = apply_query_filters('posts_clauses', ['where' => 'public-scope', 'orderby' => 'post_date DESC, ID DESC'], $this);
            $other = new WP_Query();
            check(apply_query_filters('posts_search', 'unrelated-search', $other) === 'unrelated-search', 'Search filter affected another query');
            check(apply_query_filters('posts_clauses', ['orderby' => 'unrelated-order'], $other) === ['orderby' => 'unrelated-order'], 'Sort filter affected another query');
            $GLOBALS['query_calls'][] = array('args' => $args, 'filters' => active_filters(), 'search' => $search, 'clauses' => $clauses);
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
        $GLOBALS['term_calls'] = array();
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
    foreach (array('array', 'stdClass') as $shape) {
        $cases['products: public nested schema as ' . $shape] = static function () use ($shape) {
            $wrap = static fn($value) => $shape === 'stdClass' ? (object) $value : $value;
            $GLOBALS['schema_data'] = array(
                'id' => 42, 'name' => 'باکس', 'is_password_protected' => false,
                'prices' => $wrap(array('price' => '0', 'currency_code' => 'IRT', 'currency_minor_unit' => 0, 'private' => 'fixture-private')),
                'attributes' => array($wrap(array('name' => 'فرمت', 'taxonomy' => 'pa_file-format', 'private' => 'fixture-private',
                    'terms' => array($wrap(array('name' => 'DXF', 'slug' => 'dxf', 'private' => 'fixture-private')))))),
                'images' => array($wrap(array('src' => 'https://gpante.com/image.png', 'alt' => 'باکس', 'private' => 'fixture-private'))),
            );
            $post = (object) array('ID' => 42, 'post_type' => 'product', 'post_status' => 'publish', 'post_password' => '');
            $response = run_callback('gpante_support_catalog_products', array(), array(array('posts' => array($post), 'total' => 1)));
            check($response instanceof WP_REST_Response && $response->status === 200, 'Valid WooCommerce nested schema must return products');
            $item = $response->data[0];
            check($item['prices']['price'] === '0', 'Schema normalization must preserve zero price');
            check($item['attributes'][0]['terms'][0] === array('name' => 'DXF', 'slug' => 'dxf'), 'Schema normalization must preserve public attribute terms');
            check($item['images'][0] === array('src' => 'https://gpante.com/image.png', 'alt' => 'باکس'), 'Schema normalization must preserve public images');
            check(!str_contains(json_encode($item), 'fixture-private'), 'Schema normalization must retain the nested public allowlists');
        };
    }
    $cases['articles: Corel variants rank by normalized title before date'] = static function () {
        run_callback('gpante_support_catalog_knowledge', ['search' => 'مقاله درباره کورل'], [[]]);
        $query = $GLOBALS['query_calls'][0];
        check(str_contains($query['search'], '%coreldraw%') && str_contains($query['search'], '%کورل%'), 'Corel variants must match the same topic');
        check(!str_contains($query['search'], '%مقاله%') && !str_contains($query['search'], '%درباره%'), 'Article request words must not hide a relevant article');
        check(str_contains($query['clauses']['orderby'], 'CASE WHEN') && str_ends_with($query['clauses']['orderby'], 'post_date DESC, ID DESC'), 'Article title relevance must precede deterministic date ordering');
    };
    $cases['products: bestseller qualification and ordering survive total fallback'] = static function () {
        $response = run_callback('gpante_support_catalog_products', ['sort' => 'best_selling', 'page' => '9', 'categoryId' => '12'], [[], ['total' => 2]]);
        check($response instanceof WP_REST_Response, 'Bestseller lookup failed');
        foreach ($GLOBALS['query_calls'] as $call) {
            check(str_contains($call['clauses']['where'], 'total_sales > 0'), 'Zero-sales products entered the bestseller set');
            check(str_contains($call['clauses']['orderby'], 'total_sales DESC'), 'Bestsellers need native sales ordering');
            check(($call['args']['cache_results'] ?? true) === false, 'Bestseller pages and count fallback must bypass stale WordPress query caches');
            check($call['args']['tax_query'][1]['terms'] === [12] && $call['args']['tax_query'][1]['include_children'], 'Category and children must be filtered before pagination');
        }
        check($response->headers['X-WP-Total'] === 2 && $response->headers['X-GPante-Sales-Period'] === 'all_time', 'Bestseller totals/period must describe the same filtered scope');
    };
    $cases['products: whole subject titles rank before substring and date matches'] = static function () {
        run_callback('gpante_support_catalog_products', ['search' => 'اسب'], [[]]);
        $query = $GLOBALS['query_calls'][0];
        $order = $query['clauses']['orderby'];
        check(str_contains($order, ' REGEXP ') && str_contains($order, '(^|[^[:alnum:]_])اسب([^[:alnum:]_]|$)'), 'Whole subject words must receive title priority');
        check(strpos($order, ' REGEXP ') < strpos($order, ' LIKE '), 'Whole title words must precede partial title matches');
        check(str_ends_with($order, 'post_date DESC, ID DESC'), 'Relevance must precede the deterministic date tie-break');
        check(str_contains($query['search'], '%اسب%'), 'Ranking must retain all literal candidates');
    };
    $cases['products: explicit sort overrides subject relevance'] = static function () {
        foreach (['price_asc' => 'price ASC', 'best_selling' => 'total_sales DESC', 'newest' => 'post_date_gmt DESC'] as $sort => $expected) {
            run_callback('gpante_support_catalog_products', ['search' => 'اسب', 'sort' => $sort], [[]]);
            $order = $GLOBALS['query_calls'][0]['clauses']['orderby'];
            check(str_contains($order, $expected) && !str_contains($order, ' REGEXP '), 'Explicit sort was replaced by relevance: ' . $sort);
        }
    };
    $cases['products: newest uses the serialized GMT publication clock before pagination'] = static function () {
        run_callback('gpante_support_catalog_products', ['sort' => 'newest', 'page' => '3'], [[], ['total' => 8]]);
        foreach ($GLOBALS['query_calls'] as $call) {
            check($call['clauses']['orderby'] === 'wp_posts.post_date_gmt DESC, wp_posts.ID DESC', 'Newest must sort by UTC publication and ID on both the page and total fallback');
        }
    };
    $cases['products: ordinary price ordering retains native cache behavior'] = static function () {
        run_callback('gpante_support_catalog_products', ['sort' => 'price_asc'], [[]]);
        $call = $GLOBALS['query_calls'][0];
        check(($call['args']['cache_results'] ?? true) === true && $call['clauses']['orderby'] === 'price ASC, product_id ASC', 'Price sorting must retain native CRUD invalidation and ordering');
    };
    $cases['categories: eligible descendants keep ancestors and exclude unrelated terms'] = static function () {
        $GLOBALS['term_ids'] = [12];
        $GLOBALS['term_records'] = [
            (object) ['term_id' => 10, 'name' => 'باکس', 'slug' => 'box', 'parent' => 0],
            (object) ['term_id' => 12, 'name' => 'آجیل', 'slug' => 'nuts', 'parent' => 10],
            (object) ['term_id' => 14, 'name' => 'private-only', 'slug' => 'hidden', 'parent' => 0],
        ];
        $response = run_callback('gpante_support_catalog_categories', ['parentId' => 0], [['posts' => [42]]]);
        check($response instanceof WP_REST_Response && array_column($response->data, 'id') === [10], 'Category roots must include eligible descendant ancestors only');
        check($response->headers['X-WP-Total'] === 1, 'Category total must exclude private-only categories');
        $args = $GLOBALS['query_calls'][0]['args'];
        check($args['post_status'] === 'publish' && $args['has_password'] === false && $args['fields'] === 'ids' && $args['posts_per_page'] === -1, 'Category discovery must read only eligible IDs across the full catalog');
        check($args['tax_query']['relation'] === 'OR', 'Category eligibility must include catalog-only and search-only products');
        unset($GLOBALS['term_ids'], $GLOBALS['term_records']);
    };
    $cases['categories: term read failure stays unavailable'] = static function () {
        $GLOBALS['term_ids'] = new WP_Error('private_sql', 'fixture-private-sql', []);
        check_unavailable(run_callback('gpante_support_catalog_categories', [], [['posts' => [42]]]));
        unset($GLOBALS['term_ids']);
    };
    foreach (['wp_get_object_terms', 'get_terms'] as $read) {
        $cases['categories: empty SQL failure from ' . $read . ' stays unavailable'] = static function () use ($read) {
            $GLOBALS['term_errors'] = [$read => 'fixture-private-sql term read'];
            check_unavailable(run_callback('gpante_support_catalog_categories', [], [['posts' => [42]]]));
            check(end($GLOBALS['term_calls'])['name'] === $read, 'Failed term read must stop before another read clears its SQL error');
        };
    }
    foreach (['wp_get_object_terms', 'get_ancestors'] as $read) {
        $cases['products: category metadata SQL failure from ' . $read . ' stays unavailable'] = static function () use ($read) {
            $GLOBALS['term_errors'] = [$read => 'fixture-private-sql category metadata'];
            $post = (object) ['ID' => 42, 'post_type' => 'product', 'post_status' => 'publish', 'post_password' => ''];
            check_unavailable(run_callback('gpante_support_catalog_products', [], [['posts' => [$post], 'total' => 1]]));
            check(end($GLOBALS['term_calls'])['name'] === $read, 'Failed category metadata read must stop immediately');
        };
    }
    foreach (['products', 'knowledge', 'categories'] as $kind) {
        $cases[$kind . ': category validation SQL failure stays unavailable'] = static function () use ($kind) {
            $GLOBALS['term_errors'] = ['term_exists' => 'fixture-private-sql category validation'];
            check_unavailable(run_callback('gpante_support_catalog_' . $kind, [$kind === 'categories' ? 'parentId' : 'categoryId' => 12], []));
        };
    }
    $cases['categories: list queries avoid caching empty SQL failures'] = static function () {
        run_callback('gpante_support_catalog_categories', [], [['posts' => [42]]]);
        foreach ($GLOBALS['term_calls'] as $read) {
            $args = $read['args'][$read['name'] === 'wp_get_object_terms' ? 2 : 0];
            check(($args['cache_results'] ?? true) === false, 'New term lists must not cache a failed empty query');
        }
    };
    $failures = 0;
    foreach ($cases as $name => $case) {
        try {
            $case();
            fwrite(STDOUT, "PASS $name\n");
        } catch (\Throwable $error) {
            $failures++;
            fwrite(STDERR, "FAIL $name: {$error->getMessage()}\n");
        } finally {
            unset($GLOBALS['term_errors'], $GLOBALS['term_ids'], $GLOBALS['term_records']);
        }
    }
    if ($failures) exit(1);
    fwrite(STDOUT, count($cases) . " public catalog query checks passed.\n");
}
