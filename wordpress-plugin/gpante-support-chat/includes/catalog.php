<?php
/**
 * Public catalog reads for support tools; no credentials or maintained index.
 *
 * @package GPanteSupportChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize public search text and Persian variants.
 *
 * @param string $value Source text.
 * @return string
 */
function gpante_support_catalog_normalize( $value ) {
	if ( class_exists( 'Normalizer' ) ) {
		$value = Normalizer::normalize( $value, Normalizer::FORM_KC );
	}
	$value = strtr(
		$value,
		array(
			'۰' => '0',
			'۱' => '1',
			'۲' => '2',
			'۳' => '3',
			'۴' => '4',
			'۵' => '5',
			'۶' => '6',
			'۷' => '7',
			'۸' => '8',
			'۹' => '9',
			'٠' => '0',
			'١' => '1',
			'٢' => '2',
			'٣' => '3',
			'٤' => '4',
			'٥' => '5',
			'٦' => '6',
			'٧' => '7',
			'٨' => '8',
			'٩' => '9',
			'ي' => 'ی',
			'ى' => 'ی',
			'ك' => 'ک',
			'٫' => '.',
		)
	);
	$value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $value );
	$value = preg_replace( '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', ' ', $value );
	return strtolower( trim( preg_replace( '/\s+/u', ' ', $value ) ) );
}

/**
 * Create a bounded public catalog error.
 *
 * @param string $code Error code.
 * @param int    $status HTTP status.
 * @return WP_Error
 */
function gpante_support_catalog_error( $code = 'invalid_query', $status = 400 ) {
	return new WP_Error( 'gpante_support_' . $code, 'Public catalog request unavailable.', array( 'status' => $status ) );
}

/**
 * Parse a positive bounded integer.
 *
 * @param mixed $value Input value.
 * @return int|false
 */
function gpante_support_catalog_integer( $value ) {
	if ( ! is_string( $value ) && ! is_int( $value ) ) {
		return false;
	}
	$value = gpante_support_catalog_normalize( (string) $value );
	return preg_match( '/^[1-9][0-9]{0,9}$/D', $value ) && (float) $value <= 2147483647 ? (int) $value : false;
}

/**
 * Validate bounded literal catalog search text.
 *
 * @param mixed $value Input value.
 * @return string|false
 */
function gpante_support_catalog_search_text( $value ) {
	if ( ! is_string( $value ) || strlen( $value ) > 1280 || preg_match( '/[\x00-\x1f\x7f]/', $value ) ) {
		return false;
	}
	$value = gpante_support_catalog_normalize( $value );
	if ( ! preg_match( '/^[\p{L}\p{N} ._-]*$/uD', $value )
		|| preg_match_all( '/./us', $value ) > 160
		|| ( '' !== $value && count( explode( ' ', $value ) ) > 8 ) ) {
		return false;
	}
	return $value;
}

/**
 * Check the request contains only supported query arguments.
 *
 * @param WP_REST_Request $request Incoming request.
 * @param array           $allowed Allowed parameter names.
 * @return bool
 */
function gpante_support_catalog_query_params( $request, $allowed ) {
	$params = $request->get_query_params();
	if ( array_diff( array_keys( $params ), $allowed ) || $request->get_body_params() ) {
		return gpante_support_catalog_error();
	}
	return $params;
}

/**
 * Literal AND search, with only the controlled باکس/جعبه synonym group.
 * All values use esc_like + prepare. The filter applies only to this query object.
 * It never drops a token or an attribute, and is removed before any other read.
 *
 * @param array  $args WordPress query arguments.
 * @param string $search Normalized literal search terms.
 * @param bool   $price_asc Apply WooCommerce numeric price ordering.
 * @return WP_Query
 * @throws RuntimeException When the public SQL query fails.
 */
function gpante_support_catalog_run_query( $args, $search = '', $price_asc = false ) {
	global $wpdb;
	$query         = new WP_Query();
	$search_filter = static function ( $sql, $current ) use ( $query, $search, $wpdb ) {
		if ( $current !== $query || '' === $search ) {
			return $sql;
		}
		$columns = array( "$wpdb->posts.post_title", "$wpdb->posts.post_excerpt", "$wpdb->posts.post_content" );
		// Normalize the stored Arabic/Persian variants too, without changing content.
		foreach ( $columns as &$column ) {
			foreach ( array(
				'ي' => 'ی',
				'ى' => 'ی',
				'ك' => 'ک',
				'‌' => ' ',
				'٫' => '.',
				'۰' => '0',
				'۱' => '1',
				'۲' => '2',
				'۳' => '3',
				'۴' => '4',
				'۵' => '5',
				'۶' => '6',
				'۷' => '7',
				'۸' => '8',
				'۹' => '9',
				'٠' => '0',
				'١' => '1',
				'٢' => '2',
				'٣' => '3',
				'٤' => '4',
				'٥' => '5',
				'٦' => '6',
				'٧' => '7',
				'٨' => '8',
				'٩' => '9',
			) as $from => $to ) {
				// Only fixed post columns and prepared REPLACE expressions enter $column.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$column = $wpdb->prepare( "REPLACE($column, %s, %s)", $from, $to );
			}
		}
		unset( $column );
		$groups = array();
		foreach ( explode( ' ', $search ) as $token ) {
			$aliases = in_array( $token, array( 'باکس', 'جعبه' ), true ) ? array( 'باکس', 'جعبه' ) : array( $token );
			$matches = array();
			foreach ( $aliases as $alias ) {
				foreach ( $columns as $column ) {
					// $column contains only fixed identifiers and already-prepared normalization.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$matches[] = $wpdb->prepare( "$column LIKE %s", '%' . $wpdb->esc_like( $alias ) . '%' );
				}
			}
			$groups[] = '(' . implode( ' OR ', $matches ) . ')';
		}
		return ' AND (' . implode( ' AND ', $groups ) . ') ';
	};
	$price_filter  = static function ( $clauses, $current ) use ( $query, $price_asc ) {
		// Use WooCommerce's own numeric lookup ordering and deterministic ID tie break.
		return $current === $query && $price_asc ? WC()->query->order_by_price_asc_post_clauses( $clauses ) : $clauses;
	};
	add_filter( 'posts_search', $search_filter, 20, 2 );
	add_filter( 'posts_clauses', $price_filter, 20, 2 );
	try {
		$query->query( $args );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Public catalog query failed.' );
		}
	} finally {
		remove_filter( 'posts_search', $search_filter, 20 );
		remove_filter( 'posts_clauses', $price_filter, 20 );
	}
	// Core skips FOUND_ROWS on an empty out-of-range page; preserve the filtered total.
	if ( ! $query->posts && $args['paged'] > 1 ) {
		$count_args                   = $args;
		$count_args['paged']          = 1;
		$count_args['posts_per_page'] = 1;
		$count_args['fields']         = 'ids';
		$count_query                  = gpante_support_catalog_run_query( $count_args, $search );
		$query->found_posts           = $count_query->found_posts;
	}
	return $query;
}

/**
 * Return public items and complete pagination metadata.
 *
 * @param array    $items Public items.
 * @param WP_Query $query Completed source query.
 * @param int      $per_page Response page size.
 * @return WP_REST_Response
 */
function gpante_support_catalog_response( $items, $query, $per_page ) {
	$response = new WP_REST_Response( $items );
	$response->header( 'X-WP-Total', (int) $query->found_posts );
	// Singular WP_Query (p=ID) can leave max_num_pages=0 despite one public match.
	$response->header( 'X-WP-TotalPages', (int) ceil( $query->found_posts / $per_page ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}

/**
 * Retrieve only eligible public WooCommerce products.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_products( $request ) {
	$params = gpante_support_catalog_query_params( $request, array( 'search', 'format', 'thickness', 'material', 'page', 'sort', 'productId' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	$sort   = $params['sort'] ?? 'relevance';
	if ( false === $page || false === $search || ! in_array( $sort, array( 'relevance', 'price_asc' ), true ) ) {
		return gpante_support_catalog_error();
	}
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' )
		|| ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema' )
		|| ! class_exists( '\Automattic\WooCommerce\StoreApi\SchemaController' )
		|| ( 'price_asc' === $sort && ( ! function_exists( 'WC' ) || ! WC()->query || ! method_exists( WC()->query, 'order_by_price_asc_post_clauses' ) ) ) ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
	try {
		$schema = \Automattic\WooCommerce\StoreApi\StoreApi::container()->get( \Automattic\WooCommerce\StoreApi\SchemaController::class )->get( 'product' );
		if ( ! $schema instanceof \Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema || ! taxonomy_exists( 'product_visibility' ) ) {
			return gpante_support_catalog_error( 'catalog_unavailable', 503 );
		}
		// Hidden means BOTH visibility exclusions. Catalog-only and search-only remain eligible.
		// WooCommerce ProductQuery.php uses these same product_visibility terms.
		$tax_query  = array(
			'relation' => 'AND',
			array(
				'relation' => 'OR',
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-catalog' ),
					'operator' => 'NOT IN',
				),
				array(
					'taxonomy' => 'product_visibility',
					'field'    => 'slug',
					'terms'    => array( 'exclude-from-search' ),
					'operator' => 'NOT IN',
				),
			),
		);
		$taxonomies = array(
			'format'    => 'pa_file-format',
			'thickness' => 'pa_thickness',
			'material'  => 'pa_materials-required',
		);
		foreach ( $taxonomies as $key => $taxonomy ) {
			if ( ! isset( $params[ $key ] ) ) {
				continue;
			}
			if ( ! is_string( $params[ $key ] ) || strlen( $params[ $key ] ) > 200 || ! preg_match( '/^[a-z0-9%_-]+$/Di', $params[ $key ] ) ) {
				return gpante_support_catalog_error();
			}
			$slug = strtolower( $params[ $key ] );
			if ( ! taxonomy_exists( $taxonomy ) || ! get_term_by( 'slug', $slug, $taxonomy ) ) {
				return gpante_support_catalog_error( 'unsupported_attribute', 400 );
			}
			$terms = array( $slug );
			// Expand only a verified OR term. A combined-required ("و") material is never equivalent.
			if ( 'material' === $key && in_array( $slug, array( 'mdf', 'plexiglass' ), true ) ) {
				$alternative = get_term_by( 'slug', 'mdf-plexiglass', $taxonomy );
				if ( $alternative && 'mdf یا پلکسی' === gpante_support_catalog_normalize( $alternative->name ) ) {
					$terms[] = 'mdf-plexiglass';
				}
			}
			$tax_query[] = array(
				'taxonomy'         => $taxonomy,
				'field'            => 'slug',
				'terms'            => $terms,
				'operator'         => 'IN',
				'include_children' => false,
			);
		}
		$details = isset( $params['productId'] );
		if ( $details && ( count( $params ) !== 1 || ! gpante_support_catalog_integer( $params['productId'] ) ) ) {
			return gpante_support_catalog_error();
		}
		$args = array(
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'has_password'        => false,
			'posts_per_page'      => $details ? 1 : 5,
			'paged'               => $page,
			'ignore_sticky_posts' => true,
			'no_found_rows'       => false,
			'suppress_filters'    => false,
			// Visibility and attribute filters are necessary public-discovery constraints.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			'tax_query'           => $tax_query,
			's'                   => $search,
			'orderby'             => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
		);
		if ( '' !== $search && 'relevance' === $sort ) {
			$args['orderby'] = array(
				'relevance' => 'DESC',
				'date'      => 'DESC',
				'ID'        => 'DESC',
			);
		}
		if ( $details ) {
			$args['p'] = gpante_support_catalog_integer( $params['productId'] );
		}
		$query = gpante_support_catalog_run_query( $args, $search, 'price_asc' === $sort );
		$items = array();
		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			// Recheck current eligibility before serialization; never turn source drift into partial matches.
			if ( ! $product || 'product' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password
				|| 'publish' !== $product->get_status() || 'variation' === $product->get_type()
				|| ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true ) ) {
				return gpante_support_catalog_error( 'catalog_changed', 503 );
			}
			$data = $schema->get_item_response( $product );
			// WooCommerce returns prices as stdClass before REST JSON serialization.
			if ( is_array( $data ) && ( $data['prices'] ?? null ) instanceof stdClass ) {
				$data['prices'] = get_object_vars( $data['prices'] );
			}
			if ( ! is_array( $data ) || ( $data['is_password_protected'] ?? true ) !== false
				|| ! isset( $data['prices'] ) || ! is_array( $data['prices'] ) ) {
				return gpante_support_catalog_error( 'catalog_unavailable', 503 );
			}
			$item               = array_intersect_key( $data, array_flip( array( 'id', 'name', 'permalink', 'short_description', 'attributes', 'is_in_stock', 'on_sale' ) ) );
			$item['attributes'] = array();
			foreach ( $data['attributes'] ?? array() as $attribute ) {
				$public_attribute          = array_intersect_key( $attribute, array_flip( array( 'name', 'taxonomy' ) ) );
				$public_attribute['terms'] = array();
				foreach ( $attribute['terms'] ?? array() as $term ) {
					$public_attribute['terms'][] = array_intersect_key( $term, array_flip( array( 'name', 'slug' ) ) );
				}
				$item['attributes'][] = $public_attribute;
			}
			$item['prices'] = array_intersect_key( $data['prices'], array_flip( array( 'price', 'regular_price', 'sale_price', 'currency_code', 'currency_minor_unit' ) ) );
			$item['images'] = array();
			foreach ( $data['images'] ?? array() as $image ) {
				$item['images'][] = array_intersect_key( $image, array_flip( array( 'src', 'alt' ) ) );
			}
			$item['status']                = 'publish';
			$item['type']                  = $product->get_type();
			$item['catalog_visibility']    = $product->get_catalog_visibility();
			$item['is_password_protected'] = false;
			$items[]                       = $item;
		}
		return gpante_support_catalog_response( $items, $query, $details ? 1 : 5 );
	} catch ( Throwable $error ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
}

/**
 * Retrieve approved public posts or guide pages.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_knowledge( $request ) {
	$params = gpante_support_catalog_query_params( $request, array( 'search', 'source', 'page' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$source = $params['source'] ?? 'articles';
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	if ( ! in_array( $source, array( 'articles', 'guides' ), true ) || false === $page || false === $search ) {
		return gpante_support_catalog_error();
	}
	$args = array(
		'post_type'           => 'guides' === $source ? 'page' : 'post',
		'post_status'         => 'publish',
		'has_password'        => false,
		'posts_per_page'      => 3,
		'paged'               => $page,
		'ignore_sticky_posts' => true,
		'no_found_rows'       => false,
		'suppress_filters'    => false,
		's'                   => $search,
		'orderby'             => array(
			'date' => 'DESC',
			'ID'   => 'DESC',
		),
	);
	if ( 'guides' === $source ) {
		$args['post__in'] = array( 4752, 4743 );
	}
	try {
		$query = gpante_support_catalog_run_query( $args, $search );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( 'publish' !== $post->post_status || '' !== $post->post_password || $post->post_type !== $args['post_type']
				|| ( 'guides' === $source && ! in_array( $post->ID, array( 4752, 4743 ), true ) ) ) {
				return gpante_support_catalog_error( 'catalog_changed', 503 );
			}
			// Raw public text avoids executing arbitrary shortcodes or customer-dependent content filters.
			$item           = array(
				'id'                    => $post->ID,
				'status'                => 'publish',
				'is_password_protected' => false,
				'link'                  => get_permalink( $post ),
				'title'                 => array( 'rendered' => wp_kses_post( $post->post_title ) ),
			);
			$field          = 'guides' === $source ? 'content' : 'excerpt';
			$text           = 'guides' === $source ? $post->post_content : ( '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 100, '…' ) );
			$item[ $field ] = array(
				'rendered'  => wp_kses_post( strip_shortcodes( $text ) ),
				'protected' => false,
			);
			$items[]        = $item;
		}
		return gpante_support_catalog_response( $items, $query, 3 );
	} catch ( Throwable $error ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
}

/**
 * Register bounded read-only public catalog routes.
 *
 * @return void
 */
function gpante_support_register_catalog() {
	register_rest_route(
		'gpante-support/v1',
		'/products',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gpante_support_catalog_products',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'gpante-support/v1',
		'/knowledge',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gpante_support_catalog_knowledge',
			'permission_callback' => '__return_true',
		)
	);
}
