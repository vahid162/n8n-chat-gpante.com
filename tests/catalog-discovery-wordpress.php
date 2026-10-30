<?php
// wp --skip-plugins=gpante-support-chat eval-file tests/catalog-discovery-wordpress.php
// All fixtures belong to the isolated local WordPress database and are removed.
if (!defined('GPANTE_SUPPORT_LOCAL_TEST') || !GPANTE_SUPPORT_LOCAL_TEST || home_url() !== (defined('GPANTE_SUPPORT_TEST_URL') ? GPANTE_SUPPORT_TEST_URL : 'https://127.0.0.1:9443')) {
    throw new RuntimeException('Isolated local test site required');
}
require __DIR__ . '/../wordpress-plugin/gpante-support-chat/includes/catalog.php';
function discovery_check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    echo "PASS $message\n";
}
function discovery_read($kind, $params) {
    $request = new WP_REST_Request('GET', '/gpante-support/v1/' . $kind);
    $request->set_query_params($params);
    $response = ('gpante_support_catalog_' . $kind)($request);
    discovery_check(!is_wp_error($response), "$kind returns a real native response");
    return $response;
}
$posts = []; $terms = []; $attribute_id = 0;
$tehran = static fn() => 'Asia/Tehran';
$canonical = static fn($url, $post) => $post->post_type === 'product' ? 'https://gpante.com/product/' . $post->post_name . '/' : $url;
$output_path = getenv('GPANTE_CATALOG_NATIVE_OUTPUT'); $source_pages = [];
try {
    foreach (['product_cat', 'category'] as $taxonomy) {
        $root = wp_insert_term('gpantefixture root', $taxonomy);
        if (is_wp_error($root)) throw new RuntimeException('Fixture term collision');
        $terms[$taxonomy][] = $root['term_id'];
        $child = wp_insert_term('gpantefixture child', $taxonomy, ['parent' => $root['term_id']]);
        $terms[$taxonomy][] = $child['term_id'];
        $hidden = wp_insert_term('gpantefixture protected only', $taxonomy);
        $terms[$taxonomy][] = $hidden['term_id'];
    }
    $public = []; $sold = [];
    for ($i = 0; $i < 11; $i++) {
        $p = new WC_Product_Simple();
        $p->set_name('gpantefixture product ' . $i);
        $p->set_status($i === 9 ? 'private' : 'publish');
        $p->set_catalog_visibility($i === 8 ? 'hidden' : ($i === 6 ? 'catalog' : ($i === 7 ? 'search' : 'visible')));
        $p->set_regular_price($i === 0 ? '0' : (string) ($i + 1));
        $p->set_virtual($i === 0);
        $p->set_downloadable($i === 0 || $i === 2);
        $p->set_total_sales($i < 8 ? ($i === 7 ? 0 : 100 - $i) : 1000);
        $p->set_category_ids([$i < 8 ? $terms['product_cat'][1] : $terms['product_cat'][2]]);
        $p->set_date_created(sprintf('2020-01-%02d 10:00:00', $i + 1));
        $id = $p->save(); $posts[] = $id;
        if ($i === 10) wp_update_post(['ID' => $id, 'post_password' => 'local-only']);
        if ($i < 8) $public[] = $id;
        if ($i < 7) $sold[] = $id;
    }
    $scope = ['categoryId' => $terms['product_cat'][0]];
    $first = discovery_read('products', $scope + ['sort' => 'newest']);
    $second = discovery_read('products', $scope + ['sort' => 'newest', 'page' => 2]);
    discovery_check(array_merge(array_column($first->get_data(), 'id'), array_column($second->get_data(), 'id')) === array_reverse($public), 'newest filters eligible descendants before pagination with no duplicates');
    discovery_check($first->get_headers()['X-WP-Total'] === 8, 'source totals exclude hidden/private/password products');
    foreach ([[0, true, true], [1, false, false], [2, false, true]] as [$offset, $virtual, $downloadable]) {
        $facts = discovery_read('products', ['productId' => $public[$offset]])->get_data()[0];
        discovery_check($facts['is_virtual'] === $virtual && $facts['is_downloadable'] === $downloadable, 'native digital, physical and mixed product facts survive public serialization');
        discovery_check(!isset($facts['downloads']), 'native fulfillment facts expose no protected download links');
    }
    $changed = wc_get_product($public[0]);
    $changed->set_virtual(false); $changed->set_downloadable(false); $changed->save();
    $facts = discovery_read('products', ['productId' => $public[0]])->get_data()[0];
    discovery_check($facts['is_virtual'] === false && $facts['is_downloadable'] === false, 'delivery flags reflect current WooCommerce data after a previously read product changes');

    discovery_check(in_array($terms['product_cat'][0], $first->get_data()[0]['category_ids'], true), 'product facts include the verified ancestor category');
    $first = discovery_read('products', $scope + ['sort' => 'best_selling']);
    $second = discovery_read('products', $scope + ['sort' => 'best_selling', 'page' => 2]);
    discovery_check(array_merge(array_column($first->get_data(), 'id'), array_column($second->get_data(), 'id')) === $sold, 'native all-time sales order covers all pages and excludes zero sales');
    discovery_check($first->get_data()[0]['prices']['price'] === '0', 'recorded free purchases remain valid source sales');
    discovery_check(!array_key_exists('total_sales', $first->get_data()[0]), 'raw sales counts are not exposed to the anonymous tool');
    $last = discovery_read('products', $scope + ['sort' => 'best_selling', 'page' => 99]);
    discovery_check($last->get_data() === [] && $last->get_headers()['X-WP-Total'] === 7, 'out-of-range bestseller counts retain the positive-sales filter');
    // Match order completion: mutate an existing sales row without saving a product.
    $store = WC_Data_Store::load('product');
    discovery_check(metadata_exists('post', $public[7], 'total_sales'), 'sales mutation starts with an existing zero-sales row');
    $store->update_product_sales($public[7], 200, 'set');
    $fresh = discovery_read('products', $scope + ['sort' => 'best_selling']);
    discovery_check(array_column($fresh->get_data(), 'id') === array_merge([$public[7]], array_slice($sold, 0, 4)), 'primed bestseller page reflects direct WooCommerce sales ranking and membership updates');
    discovery_check($fresh->get_headers()['X-WP-Total'] === 8, 'primed bestseller total reflects a new positive-sales member');
    $fresh_last = discovery_read('products', $scope + ['sort' => 'best_selling', 'page' => 99]);
    discovery_check($fresh_last->get_data() === [] && $fresh_last->get_headers()['X-WP-Total'] === 8, 'primed out-of-range count reflects direct sales updates');
    $store->update_product_sales($public[0], 0, 'set');
    $fresh = discovery_read('products', $scope + ['sort' => 'best_selling']);
    discovery_check(array_column($fresh->get_data(), 'id') === array_merge([$public[7]], array_slice($sold, 1, 4)) && $fresh->get_headers()['X-WP-Total'] === 7, 'direct sales reduction removes a cached bestseller and refreshes its total');
    $cats = discovery_read('categories', ['search' => 'gpantefixture', 'parentId' => 0]);
    discovery_check(array_column($cats->get_data(), 'id') === [$terms['product_cat'][0]], 'category roots include public descendants and exclude protected-only terms');
    $children = discovery_read('categories', ['search' => 'gpantefixture', 'parentId' => $terms['product_cat'][0]]);
    discovery_check(array_column($children->get_data(), 'id') === [$terms['product_cat'][1]], 'positive parent navigation returns only the immediate eligible child');
    $roots = [$terms['product_cat'][0]];
    for ($i = 0; $i < 5; $i++) {
        $term = wp_insert_term('gpantefixture page root ' . $i, 'product_cat');
        if (is_wp_error($term)) throw new RuntimeException('Page category fixture failed');
        $terms['product_cat'][] = $term['term_id']; $roots[] = $term['term_id'];
        $p = new WC_Product_Simple();
        $p->set_name('gpantefixture category page ' . $i);
        $p->set_status('publish'); $p->set_regular_price('1');
        $p->set_category_ids([$term['term_id']]);
        $posts[] = $p->save();
    }
    $category_pages = [];
    foreach ([1, 2, 99] as $page) {
        $read = discovery_read('categories', ['search' => 'gpantefixture', 'parentId' => 0, 'page' => $page]);
        $expected = $page === 99 ? [] : array_slice($roots, ($page - 1) * 5, 5);
        discovery_check(array_column($read->get_data(), 'id') === $expected && $read->get_headers()['X-WP-Total'] === 6 && $read->get_headers()['X-WP-TotalPages'] === 2, 'category page ' . $page . ' has exact eligible IDs and filtered totals despite an interspersed protected-only term');
        $category_pages = array_merge($category_pages, array_column($read->get_data(), 'id'));
    }
    discovery_check($category_pages === $roots && count(array_unique($category_pages)) === 6, 'category pagination covers every eligible root without duplicates');
    if (!taxonomy_exists('pa_file-format')) {
        $created = wc_create_attribute(['name' => 'File format', 'slug' => 'file-format', 'type' => 'select', 'order_by' => 'menu_order']);
        if (is_wp_error($created)) throw new RuntimeException('Format attribute fixture failed');
        $attribute_id = $created;
        register_taxonomy('pa_file-format', ['product'], ['hierarchical' => false]);
    }
    $format = wp_insert_term('gpantefixture DXF', 'pa_file-format', ['slug' => 'gpantefixture-dxf']);
    if (is_wp_error($format)) throw new RuntimeException('Format term fixture failed');
    $terms['pa_file-format'][] = $format['term_id'];
    foreach ([$public[0], $public[1], end($posts)] as $id) {
        $attribute = new WC_Product_Attribute();
        $attribute->set_id(wc_attribute_taxonomy_id_by_name('pa_file-format'));
        $attribute->set_name('pa_file-format'); $attribute->set_options([$format['term_id']]); $attribute->set_visible(true);
        $p = wc_get_product($id); $p->set_attributes([$attribute]); $p->save();
    }
    $intersection = discovery_read('products', $scope + ['format' => 'gpantefixture-dxf', 'sort' => 'newest']);
    discovery_check(array_column($intersection->get_data(), 'id') === [$public[1], $public[0]] && $intersection->get_headers()['X-WP-Total'] === 2, 'real format and descendant category filters intersect before pagination and exclude an out-of-category format match');
    discovery_check($intersection->get_data()[0]['attributes'][0]['terms'][0]['slug'] === 'gpantefixture-dxf', 'native format intersection retains the public attribute evidence');
    add_filter('pre_option_timezone_string', $tehran);
    foreach (['2023-01-01 00:00:00', '2023-01-01 00:00:00', '2023-01-01 00:00:00', '2023-01-01 00:00:00', '2022-09-21 19:20:00', '2022-09-21 19:40:00'] as $i => $gmt) {
        $publication = ['ID' => $public[$i], 'post_date' => get_date_from_gmt($gmt), 'post_date_gmt' => $gmt];
        if ($i === 4 || $i === 5) $publication['post_title'] = 'gpantefixture rollback ' . $i;
        $updated = wp_update_post($publication, true);
        if (is_wp_error($updated)) throw new RuntimeException('UTC publication fixture failed');
    }
    discovery_check(get_post($public[4])->post_date > get_post($public[5])->post_date && get_post($public[4])->post_date_gmt < get_post($public[5])->post_date_gmt, 'normal WordPress publications span the real Tehran 2022 clock rollback');
    $utc_order = [$public[3], $public[2], $public[1], $public[0], $public[5], $public[4], $public[7], $public[6]];
    // Adapt the isolated site's plain permalinks for optional public-link contract proof.
    if ($output_path) add_filter('post_type_link', $canonical, 10, 2);
    foreach ([1, 2] as $page) {
        $query = $scope + ['sort' => 'newest', 'page' => $page];
        $read = discovery_read('products', $query);
        discovery_check(array_column($read->get_data(), 'id') === array_slice($utc_order, ($page - 1) * 5, 5), 'UTC newest page ' . $page . ' uses exact publication and ID order across the rollback boundary');
        $source_pages[] = ['query' => $query, 'response' => ['statusCode' => $read->get_status(), 'headers' => $read->get_headers(), 'body' => $read->get_data()]];
    }
    $query = $scope + ['sort' => 'newest', 'search' => 'gpantefixture rollback'];
    $read = discovery_read('products', $query);
    discovery_check(array_column($read->get_data(), 'id') === [$public[5], $public[4]], 'same-page newest results use UTC across the rollback');
    $source_pages[] = ['query' => $query, 'response' => ['statusCode' => $read->get_status(), 'headers' => $read->get_headers(), 'body' => $read->get_data()]];
    remove_filter('post_type_link', $canonical);
    remove_filter('pre_option_timezone_string', $tehran);
    $horse_ids = [];
    $horse_names = ['طرح cnc کتابخانه اسب', 'طرح تندیس‌اسب', 'طرح كتابخانه اسب', 'طرح کتابخانه اسباب بازی', 10 => 'باکس هدیه تست'];
    for ($i = 0; $i < 14; $i++) {
        $p = new WC_Product_Simple();
        $p->set_name($horse_names[$i] ?? ($i < 11 ? 'طرح باکس هدیه مناسب تست ' . $i : 'طرح اسب محافظت شده ' . $i));
        $p->set_description($i === 10 ? 'طرح اسب برای برش لیزر' : 'مناسب برای برش لیزر');
        $p->set_status($i === 12 ? 'private' : 'publish');
        $p->set_catalog_visibility($i === 11 ? 'hidden' : 'visible');
        $p->set_regular_price((string) ($i < 3 ? 20 + $i : 1 + $i));
        $p->set_total_sales($i + 1);
        $p->set_category_ids([$terms['product_cat'][1]]);
        $p->set_date_created(sprintf('%d-01-%02d 10:00:00', $i < 3 ? 2000 + $i : 2026, $i + 1));
        $id = $p->save(); $posts[] = $id; $horse_ids[] = $id;
        if ($i === 13) wp_update_post(['ID' => $id, 'post_password' => 'local-only']);
    }
    $horse_order = [$horse_ids[2], $horse_ids[1], $horse_ids[0]];
    foreach (['اسب', 'طرح اسب'] as $search) {
        $read = discovery_read('products', $scope + ['search' => $search]);
        discovery_check(array_slice(array_column($read->get_data(), 'id'), 0, 3) === $horse_order, 'horse subject ranks real whole-word titles before newer suitable and toy substrings: ' . $search);
        discovery_check($read->get_headers()['X-WP-Total'] === 11, 'horse ranking preserves every eligible candidate and excludes protected products');
    }
    $read = discovery_read('products', $scope + ['search' => 'كتابخانه اسب']);
    discovery_check(array_slice(array_column($read->get_data(), 'id'), 0, 2) === [$horse_ids[2], $horse_ids[0]], 'normalized Arabic title and every requested topic precede a partial toy match');
    $all_horses = [];
    foreach ([1, 2, 3] as $page) {
        $read = discovery_read('products', $scope + ['search' => 'اسب', 'page' => $page]);
        $all_horses = array_merge($all_horses, array_column($read->get_data(), 'id'));
    }
    discovery_check(count($all_horses) === 11 && count(array_unique($all_horses)) === 11, 'horse relevance ranks before pagination without losing or duplicating candidates');
    discovery_check(array_search($horse_ids[3], $all_horses, true) < array_search($horse_ids[10], $all_horses, true), 'substring title match precedes a newer description-only match when whole-word title scores tie');
    foreach (['newest' => 10, 'best_selling' => 10, 'price_asc' => 3] as $sort => $index) {
        $read = discovery_read('products', $scope + ['search' => 'اسب', 'sort' => $sort]);
        discovery_check($read->get_data()[0]['id'] === $horse_ids[$index], 'explicit ' . $sort . ' remains authoritative over title relevance');
    }
    $read = discovery_read('products', $scope + ['search' => 'اسب', 'page' => 99]);
    discovery_check($read->get_data() === [] && $read->get_headers()['X-WP-Total'] === 11, 'horse ranking keeps accurate out-of-range totals');
    $article_ids = [];
    foreach ([
        ['CorelDRAW قدیمی', 'موضوع کورل', '2020-01-01 10:00:00', ''],
        ['تغییر فایل کورل به فرمت های دیگر', 'تبدیل فایل به dxf', '2020-01-02 10:00:00', ''],
        ['عنوان تازه نامرتبط', 'کورل فقط در متن آمده', '2026-01-01 10:00:00', ''],
        ['کورل خصوصی', 'تغییر فایل dxf', '2026-01-02 10:00:00', 'local-only'],
    ] as [$title, $content, $date, $password]) {
        $id = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => $content,
            'post_date' => $date, 'post_password' => $password, 'post_category' => [$password ? $terms['category'][2] : $terms['category'][1]]], true);
        if (is_wp_error($id)) throw new RuntimeException('Article fixture failed');
        $posts[] = $id; if (!$password) $article_ids[] = $id;
    }
    $article_scope = ['categoryId' => $terms['category'][0]];
    foreach (['کورل', 'CorelDRAW', 'مقاله درباره کورل'] as $search) {
        $read = discovery_read('knowledge', $article_scope + ['search' => $search]);
        discovery_check(array_column($read->get_data(), 'id') === [$article_ids[1], $article_ids[0], $article_ids[2]], 'article title relevance and Corel aliases precede unrelated recent content');
    }
    $read = discovery_read('knowledge', $article_scope + ['search' => 'تبدیل کورل به dxf']);
    discovery_check(array_column($read->get_data(), 'id') === [$article_ids[1]], 'conversion article matches meaningful terms and the controlled change/convert alias');
    $cats = discovery_read('categories', ['source' => 'articles', 'search' => 'gpantefixture', 'parentId' => 0]);
    discovery_check(array_column($cats->get_data(), 'id') === [$terms['category'][0]], 'article categories exclude password-only content independently of products');
    $request = new WP_REST_Request('GET', '/gpante-support/v1/products');
    $request->set_query_params(['categoryId' => -1]);
    discovery_check(is_wp_error(gpante_support_catalog_products($request)), 'invalid category never relaxes to the whole catalog');
    if ($output_path && file_put_contents($output_path, wp_json_encode($source_pages)) === false) throw new RuntimeException('Native contract output failed');
    echo 'Native catalog discovery integration passed. WordPress ' . get_bloginfo('version') . ' / WooCommerce ' . WC_VERSION . "\n";
} finally {
    remove_filter('pre_option_timezone_string', $tehran);
    remove_filter('post_type_link', $canonical);
    foreach ($posts as $id) wp_delete_post($id, true);
    foreach ($terms as $taxonomy => $ids) foreach (array_reverse($ids) as $id) wp_delete_term($id, $taxonomy);
    if ($attribute_id) wc_delete_attribute($attribute_id);
}
