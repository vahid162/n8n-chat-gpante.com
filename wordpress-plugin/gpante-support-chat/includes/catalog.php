<?php
/**
 * Public catalog reads for support tools; no credentials or maintained index.
 *
 * @package GPanteSupportChat
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/faq.php';

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
 * Group literal terms; article intent words are not extra content requirements.
 *
 * @param string $search Normalized search.
 * @param bool   $articles Use the small public-article vocabulary.
 * @return array
 */
function gpante_support_catalog_search_groups( $search, $articles = false ) {
	$tokens = '' === $search ? array() : explode( ' ', $search );
	if ( $articles ) {
		$meaningful = array_values( array_diff( $tokens, array( 'مقاله', 'مقالات', 'آموزش', 'آموزشی', 'درباره', 'راجع', 'به', 'در', 'مورد', 'برای', 'از', 'و', 'را', 'رو' ) ) );
		$tokens     = $meaningful ? $meaningful : $tokens;
	}
	$groups = array();
	foreach ( $tokens as $token ) {
		$aliases = array( $token );
		foreach ( $articles ? array( array( 'کورل', 'coreldraw', 'corel' ), array( 'تبدیل', 'تغییر' ) ) : array( array( 'باکس', 'جعبه' ) ) as $group ) {
			if ( in_array( $token, $group, true ) ) {
				$aliases = $group;
				break;
			}
		}
		$groups[] = $aliases;
	}
	return $groups;
}

/**
 * Literal AND search, with controlled aliases and normalized title relevance.
 * All values use esc_like + prepare. The filter applies only to this query object.
 * It never drops a token or an attribute, and is removed before any other read.
 *
 * @param array  $args WordPress query arguments.
 * @param string $search Normalized literal search terms.
 * @param string $sort Apply verified WooCommerce ordering when requested.
 * @return WP_Query
 * @throws RuntimeException When the public SQL query fails.
 */
function gpante_support_catalog_run_query( $args, $search = '', $sort = 'relevance' ) {
	global $wpdb;
	if ( 'best_selling' === $sort ) {
		// WooCommerce's direct sales updates do not invalidate WordPress query caches.
		$args['cache_results'] = false;
	}
	$query         = new WP_Query();
	$articles      = 'post' === $args['post_type'];
	$products      = 'product' === $args['post_type'];
	$title_order   = '';
	$search_filter = static function ( $sql, $current ) use ( $query, $search, $wpdb, $articles, $products, &$title_order ) {
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
		$scores = array();
		$words  = array();
		foreach ( gpante_support_catalog_search_groups( $search, $articles ) as $aliases ) {
			$matches = array();
			$titles  = array();
			$whole   = array();
			foreach ( $aliases as $alias ) {
				foreach ( $columns as $column_index => $column ) {
					// $column contains only fixed identifiers and already-prepared normalization.
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$matches[] = $wpdb->prepare( "$column LIKE %s", '%' . $wpdb->esc_like( $alias ) . '%' );
					if ( ( $articles || $products ) && 0 === $column_index ) {
						$titles[] = end( $matches );
						if ( $products ) {
							// Whole subject words outrank substrings such as اسب inside مناسب.
							// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
							$whole[] = $wpdb->prepare( "$column REGEXP %s", '(^|[^[:alnum:]_])' . preg_quote( $alias, '/' ) . '([^[:alnum:]_]|$)' );
						}
					}
				}
			}
			$groups[] = '(' . implode( ' OR ', $matches ) . ')';
			if ( $titles ) {
				$scores[] = '(CASE WHEN (' . implode( ' OR ', $titles ) . ') THEN 1 ELSE 0 END)';
			}
			if ( $whole ) {
				$words[] = '(CASE WHEN (' . implode( ' OR ', $whole ) . ') THEN 1 ELSE 0 END)';
			}
		}
		$title_order = $scores ? '(' . implode( ' + ', $scores ) . ') DESC' : '';
		if ( $words ) {
			$title_order = '(' . implode( ' + ', $words ) . ') DESC, ' . $title_order;
		}
		return ' AND (' . implode( ' AND ', $groups ) . ') ';
	};
	$sort_filter   = static function ( $clauses, $current ) use ( $query, $sort, $wpdb, &$title_order ) {
		if ( $current !== $query ) {
			return $clauses;
		}
		if ( 'price_asc' === $sort ) {
			return WC()->query->order_by_price_asc_post_clauses( $clauses );
		}
		if ( 'best_selling' === $sort ) {
			$clauses           = WC()->query->order_by_popularity_post_clauses( $clauses );
			$clauses['where'] .= ' AND wc_product_meta_lookup.total_sales > 0';
		} elseif ( 'newest' === $sort ) {
			$clauses['orderby'] = "$wpdb->posts.post_date_gmt DESC, $wpdb->posts.ID DESC";
		} elseif ( '' !== $title_order ) {
			$clauses['orderby'] = $title_order . ', ' . $clauses['orderby'];
		}
		return $clauses;
	};
	add_filter( 'posts_search', $search_filter, 20, 2 );
	add_filter( 'posts_clauses', $sort_filter, 20, 2 );
	try {
		$query->query( $args );
		if ( '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'Public catalog query failed.' );
		}
	} finally {
		remove_filter( 'posts_search', $search_filter, 20 );
		remove_filter( 'posts_clauses', $sort_filter, 20 );
	}
	// Core skips FOUND_ROWS on an empty out-of-range page; preserve the filtered total.
	if ( ! $query->posts && $args['paged'] > 1 ) {
		$count_args                   = $args;
		$count_args['paged']          = 1;
		$count_args['posts_per_page'] = 1;
		$count_args['fields']         = 'ids';
		$count_query                  = gpante_support_catalog_run_query( $count_args, $search, $sort );
		$query->found_posts           = $count_query->found_posts;
	}
	return $query;
}

/**
 * Public visibility, shared by product discovery and category discovery.
 *
 * @return array
 */
function gpante_support_catalog_visibility() {
	return array(
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
	);
}

/**
 * Check each native term read before a later query can clear its SQL error.
 *
 * @param callable $read Fixed WordPress term reader.
 * @param mixed    ...$args Native reader arguments.
 * @return mixed
 * @throws RuntimeException When the term read fails.
 */
function gpante_support_catalog_read_terms( $read, ...$args ) {
	global $wpdb;
	$wpdb->last_error = '';
	$result           = $read( ...$args );
	if ( is_wp_error( $result ) || '' !== $wpdb->last_error ) {
		throw new RuntimeException( 'Public categories unavailable.' );
	}
	return $result;
}

/**
 * Read assigned category IDs plus their real ancestors for independent checks.
 *
 * @param int    $post_id Public post ID.
 * @param string $taxonomy Fixed category taxonomy.
 * @return array
 * @throws RuntimeException When term reads fail.
 */
function gpante_support_catalog_category_ids( $post_id, $taxonomy ) {
	$ids = gpante_support_catalog_read_terms(
		'wp_get_object_terms',
		$post_id,
		$taxonomy,
		array(
			'fields'        => 'ids',
			'cache_results' => false,
		)
	);
	$all = $ids;
	foreach ( $ids as $id ) {
		$all = array_merge( $all, gpante_support_catalog_read_terms( 'get_ancestors', $id, $taxonomy, 'taxonomy' ) );
	}
	return array_values( array_unique( array_map( 'intval', $all ) ) );
}

/**
 * Validate and append a public category constraint, including descendants.
 *
 * @param array  $params Validated request parameters.
 * @param string $taxonomy Fixed category taxonomy.
 * @param array  $args Query arguments, updated in place.
 * @return bool
 * @throws RuntimeException When category validation cannot read terms.
 */
function gpante_support_catalog_filter_category( $params, $taxonomy, &$args ) {
	if ( ! isset( $params['categoryId'] ) ) {
		return true;
	}
	$id = gpante_support_catalog_integer( $params['categoryId'] );
	if ( false === $id || ! taxonomy_exists( $taxonomy ) || ! gpante_support_catalog_read_terms( 'term_exists', $id, $taxonomy ) ) {
		return false;
	}
	// The taxonomy and ID come from fixed names and the validated integer above.
	// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
	$args['tax_query'][] = array(
		'taxonomy'         => $taxonomy,
		'field'            => 'term_id',
		'terms'            => array( $id ),
		'include_children' => true,
	);
	return true;
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
 * Validate the fixed public page or image path before URL normalization.
 *
 * @param mixed  $url Public URL.
 * @param string $prefix Fixed allowed path prefix.
 * @return bool
 */
function gpante_support_reply_url( $url, $prefix ) {
	if ( ! is_string( $url ) || strlen( $url ) > 2048 || ! str_starts_with( $url, 'https://gpante.com/' . $prefix ) ) {
		return false;
	}
	$path = rawurldecode( substr( $url, strlen( 'https://gpante.com' ) ) );
	return ! preg_match( '/[\\\\\x00-\x20\x7f%?#]/', $path ) && ! str_contains( $path, '//' ) && ! preg_match( '~(?:^|/)\.{1,2}(?:/|$)~', $path );
}

/**
 * Enrich reply links with current public product thumbnails; never fetch a URL.
 *
 * @param string $text Already bounded assistant reply.
 * @param array  $cache Per-request lookups, including misses.
 * @return array
 */
function gpante_support_reply_products( $text, &$cache ) {
	$items = array();
	if ( ! function_exists( 'wc_get_product' ) ) {
		return $items;
	}
	preg_match_all( '~https://gpante\.com/product/[^\s<>()\[\]]+~u', $text, $matches );
	foreach ( array_unique( $matches[0] ) as $address ) {
		$address = preg_replace( '/[.,،؛]+$/u', '', $address );
		if ( ! gpante_support_reply_url( $address, 'product/' ) ) {
			continue;
		}
		if ( ! array_key_exists( $address, $cache ) ) {
			if ( count( $cache ) >= 30 ) {
				continue;
			}
			$cache[ $address ] = null;
			try {
				$id      = url_to_postid( $address );
				$post    = $id ? get_post( $id ) : null;
				$product = $post && 'product' === $post->post_type && 'publish' === $post->post_status && '' === $post->post_password ? wc_get_product( $id ) : false;
				if ( ! $product || 'publish' !== $product->get_status() || 'variation' === $product->get_type()
					|| ! in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true )
					|| rawurldecode( $address ) !== rawurldecode( get_permalink( $id ) ) ) {
					continue;
				}
				$image = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
				if ( ! gpante_support_reply_url( $image, 'wp-content/uploads/' ) || ! preg_match( '/\.(?:jpe?g|png|webp|avif|gif)$/Di', $image ) ) {
					continue;
				}
				$cache[ $address ] = array(
					'url'       => $address,
					'title'     => wp_html_excerpt( html_entity_decode( wp_strip_all_tags( get_the_title( $id ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 200, '' ),
					'thumbnail' => $image,
				);
			} catch ( Throwable $error ) {
				// Optional presentation must not turn a valid reply into a failed send.
				continue;
			}
		}
		if ( is_array( $cache[ $address ] ) ) {
			$items[] = $cache[ $address ];
		}
	}
	return $items;
}

/**
 * Retrieve only eligible public WooCommerce products.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_products( $request ) {
	$params = gpante_support_catalog_query_params( $request, array( 'search', 'format', 'thickness', 'material', 'page', 'sort', 'productId', 'categoryId' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	$sort   = $params['sort'] ?? 'relevance';
	if ( false === $page || false === $search || ! in_array( $sort, array( 'relevance', 'price_asc', 'newest', 'best_selling' ), true ) ) {
		return gpante_support_catalog_error();
	}
	if ( ! function_exists( 'wc_get_product' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\StoreApi' )
		|| ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\ProductSchema' )
		|| ! class_exists( '\Automattic\WooCommerce\StoreApi\SchemaController' )
		|| ( in_array( $sort, array( 'price_asc', 'best_selling' ), true ) && ( ! function_exists( 'WC' ) || ! WC()->query
			|| ! method_exists( WC()->query, 'price_asc' === $sort ? 'order_by_price_asc_post_clauses' : 'order_by_popularity_post_clauses' ) ) ) ) {
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
			gpante_support_catalog_visibility(),
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
		if ( ! gpante_support_catalog_filter_category( $params, 'product_cat', $args ) ) {
			return gpante_support_catalog_error( 'unsupported_category', 400 );
		}
		$query = gpante_support_catalog_run_query( $args, $search, $sort );
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
				if ( $attribute instanceof stdClass ) {
					$attribute = get_object_vars( $attribute );
				}
				$public_attribute          = array_intersect_key( $attribute, array_flip( array( 'name', 'taxonomy' ) ) );
				$public_attribute['terms'] = array();
				foreach ( $attribute['terms'] ?? array() as $term ) {
					if ( $term instanceof stdClass ) {
						$term = get_object_vars( $term );
					}
					$public_attribute['terms'][] = array_intersect_key( $term, array_flip( array( 'name', 'slug' ) ) );
				}
				$item['attributes'][] = $public_attribute;
			}
			$item['prices'] = array_intersect_key( $data['prices'], array_flip( array( 'price', 'regular_price', 'sale_price', 'currency_code', 'currency_minor_unit' ) ) );
			$item['images'] = array();
			foreach ( $data['images'] ?? array() as $image ) {
				if ( $image instanceof stdClass ) {
					$image = get_object_vars( $image );
				}
				$item['images'][] = array_intersect_key( $image, array_flip( array( 'src', 'alt' ) ) );
			}
			$item['status']                = 'publish';
			$item['type']                  = $product->get_type();
			$item['catalog_visibility']    = $product->get_catalog_visibility();
			$item['is_password_protected'] = false;
			$item['category_ids']          = gpante_support_catalog_category_ids( $post->ID, 'product_cat' );
			$item['published_at']          = get_post_time( 'Y-m-d\TH:i:s\Z', true, $post );
			$items[]                       = $item;
		}
		$response = gpante_support_catalog_response( $items, $query, $details ? 1 : 5 );
		$response->header( 'X-GPante-Sort', $sort );
		if ( 'best_selling' === $sort ) {
			$response->header( 'X-GPante-Sales-Period', 'all_time' );
		}
		return $response;
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
	$params = gpante_support_catalog_query_params( $request, array( 'search', 'source', 'page', 'categoryId' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$source = $params['source'] ?? 'articles';
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	if ( ! in_array( $source, array( 'articles', 'guides', 'faq' ), true ) || false === $page || false === $search
		|| ( 'articles' !== $source && isset( $params['categoryId'] ) ) ) {
		return gpante_support_catalog_error();
	}
	if ( 'faq' === $source ) {
		return gpante_support_catalog_faq( $search, $page );
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
		if ( ! gpante_support_catalog_filter_category( $params, 'category', $args ) ) {
			return gpante_support_catalog_error( 'unsupported_category', 400 );
		}
		$query = gpante_support_catalog_run_query( $args, $search );
		$items = array();
		foreach ( $query->posts as $post ) {
			if ( 'publish' !== $post->post_status || '' !== $post->post_password || $post->post_type !== $args['post_type']
				|| ( 'guides' === $source && ! in_array( $post->ID, array( 4752, 4743 ), true ) ) ) {
				return gpante_support_catalog_error( 'catalog_changed', 503 );
			}
			// Raw public text avoids executing arbitrary shortcodes or customer-dependent content filters.
			$item                 = array(
				'id'                    => $post->ID,
				'status'                => 'publish',
				'is_password_protected' => false,
				'link'                  => get_permalink( $post ),
				'title'                 => array( 'rendered' => wp_kses_post( $post->post_title ) ),
			);
			$field                = 'guides' === $source ? 'content' : 'excerpt';
			$text                 = 'guides' === $source ? $post->post_content : ( '' !== $post->post_excerpt ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 100, '…' ) );
			$item[ $field ]       = array(
				'rendered'  => wp_kses_post( strip_shortcodes( $text ) ),
				'protected' => false,
				'truncated' => 'articles' === $source,
			);
			$item['category_ids'] = 'articles' === $source ? gpante_support_catalog_category_ids( $post->ID, 'category' ) : array();
			$items[]              = $item;
		}
		return gpante_support_catalog_response( $items, $query, 3 );
	} catch ( Throwable $error ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
}

/**
 * Discover actual categories containing eligible public content, plus ancestors.
 * Only IDs are read for coverage; product bodies and sales never enter this path.
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_categories( $request ) {
	$params = gpante_support_catalog_query_params( $request, array( 'source', 'search', 'parentId', 'page' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$source = $params['source'] ?? 'products';
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	$parent = null;
	if ( isset( $params['parentId'] ) ) {
		$value  = $params['parentId'];
		$parent = ( is_int( $value ) || is_string( $value ) ) && '0' === gpante_support_catalog_normalize( (string) $value ) ? 0 : gpante_support_catalog_integer( $value );
	}
	if ( ! in_array( $source, array( 'products', 'articles' ), true ) || false === $page || false === $search || false === $parent ) {
		return gpante_support_catalog_error();
	}
	$taxonomy = 'products' === $source ? 'product_cat' : 'category';
	if ( ! taxonomy_exists( $taxonomy ) || ( 'products' === $source && ! taxonomy_exists( 'product_visibility' ) ) ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
	try {
		if ( $parent && ! gpante_support_catalog_read_terms( 'term_exists', $parent, $taxonomy ) ) {
			return gpante_support_catalog_error( 'unsupported_category', 400 );
		}
		$args = array(
			'post_type'              => 'products' === $source ? 'product' : 'post',
			'post_status'            => 'publish',
			'has_password'           => false,
			'posts_per_page'         => -1,
			'paged'                  => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( 'products' === $source ) {
			// This full IDs query shares exactly the product eligibility rules.
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'] = gpante_support_catalog_visibility();
		}
		$query    = gpante_support_catalog_run_query( $args );
		$assigned = $query->posts ? gpante_support_catalog_read_terms(
			'wp_get_object_terms',
			$query->posts,
			$taxonomy,
			array(
				'fields'        => 'ids',
				'cache_results' => false,
			)
		) : array();
		$terms    = gpante_support_catalog_read_terms(
			'get_terms',
			array(
				'taxonomy'      => $taxonomy,
				'hide_empty'    => false,
				'orderby'       => 'term_id',
				'order'         => 'ASC',
				'cache_results' => false,
			)
		);
		$by_id    = array();
		foreach ( $terms as $term ) {
			$by_id[ $term->term_id ] = $term;
		}
		$eligible = array();
		foreach ( $assigned as $id ) {
			while ( isset( $by_id[ $id ] ) && ! isset( $eligible[ $id ] ) ) {
				$eligible[ $id ] = true;
				$id              = $by_id[ $id ]->parent;
			}
		}
		$matches       = array();
		$search_groups = gpante_support_catalog_search_groups( $search, 'articles' === $source );
		foreach ( $terms as $term ) {
			if ( ! isset( $eligible[ $term->term_id ] ) || ( null !== $parent && (int) $term->parent !== $parent ) ) {
				continue;
			}
			$text  = gpante_support_catalog_normalize( $term->name . ' ' . urldecode( $term->slug ) );
			$match = true;
			foreach ( $search_groups as $aliases ) {
				$hit = false;
				foreach ( $aliases as $alias ) {
					$hit = $hit || false !== strpos( $text, $alias );
				}
				$match = $match && $hit;
			}
			if ( $match ) {
				$matches[] = $term;
			}
		}
		$items = array();
		foreach ( array_slice( $matches, ( $page - 1 ) * 5, 5 ) as $term ) {
			$link = get_term_link( $term );
			if ( is_wp_error( $link ) ) {
				return gpante_support_catalog_error( 'catalog_unavailable', 503 );
			}
			$items[] = array(
				'id'     => (int) $term->term_id,
				'name'   => $term->name,
				'parent' => (int) $term->parent,
				'link'   => $link,
				'source' => $source,
			);
		}
		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', count( $matches ) );
		$response->header( 'X-WP-TotalPages', (int) ceil( count( $matches ) / 5 ) );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
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
		'/categories',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gpante_support_catalog_categories',
			'permission_callback' => '__return_true',
		)
	);
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
