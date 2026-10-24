<?php
/**
 * Automatic public site discovery and bounded static content reads.
 *
 * @package GPanteSupportChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Strip executable, navigation and visitor-dependent markup without rendering.
 *
 * @param string $html Stored public markup.
 * @return string
 */
function gpante_support_site_text( $html ) {
	$html = strip_shortcodes( $html );
	$dom  = new DOMDocument();
	if ( ! $dom->loadHTML( '<?xml encoding="UTF-8"><div>' . $html . '</div>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
		return '';
	}
	$xpath = new DOMXPath( $dom );
	foreach ( $xpath->query( '//script|//style|//template|//iframe|//object|//form|//nav|//header|//footer|//noscript|//input|//textarea|//select|//*[@hidden or @aria-hidden="true"]' ) as $unsafe ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM.
		$unsafe->parentNode->removeChild( $unsafe );
	}
	$html = $dom->saveHTML();
	$html = preg_replace( '~</(?:p|div|li|h[1-6]|section|article)>|<br\s*/?>~i', "\n", $html );
	$text = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	return trim( preg_replace( '/[\t ]+/u', ' ', preg_replace( '/\n\s*\n/u', "\n", $text ) ) );
}

/**
 * Read only known static Elementor text fields; never render a widget/template.
 *
 * @param array $elements Native Elementor elements.
 * @return string
 * @throws UnexpectedValueException If the authoritative document is malformed.
 */
function gpante_support_site_elementor_text( $elements ) {
	if ( ! array_is_list( $elements ) ) {
		throw new UnexpectedValueException( 'Invalid Elementor document.' );
	}
	$text   = array();
	$fields = array(
		'heading'     => array( 'title' ),
		'e-heading'   => array( 'title' ),
		'e-paragraph' => array( 'paragraph' ),
		'e-button'    => array( 'text' ),
		'text-editor' => array( 'editor' ),
		'html'        => array( 'html' ),
		'button'      => array( 'text' ),
		'icon-box'    => array( 'title_text', 'description_text' ),
		'image-box'   => array(
			'title_text',
			'description_text',
		),
	);
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) || ! is_string( $element['elType'] ?? null ) ) {
			throw new UnexpectedValueException( 'Invalid Elementor element.' );
		}
		$settings = $element['settings'] ?? array();
		if ( ! is_array( $settings ) ) {
			throw new UnexpectedValueException( 'Invalid Elementor settings.' );
		}
		// Visibility/dynamic settings can depend on a visitor; skip the entire subtree.
		$unsafe = false;
		foreach ( $settings as $key => $value ) {
			if ( $value && ( str_starts_with( $key, 'hide_' ) || preg_match( '/(?:condition|visibility|private|password)/i', $key ) ) ) {
				$unsafe = true;
			}
		}
		if ( $unsafe ) {
			continue;
		}
		foreach ( $fields[ $element['widgetType'] ?? $element['elType'] ?? '' ] ?? array() as $field ) {
			$value = $settings[ $field ] ?? null;
			// Elementor 4 wraps static text; unknown/dynamic property types stay unreadable.
			if ( is_array( $value ) && 'escaped-html' === ( $value['$$type'] ?? '' ) && is_string( $value['value'] ?? null ) ) {
				$value = html_entity_decode( $value['value'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
			if ( empty( $settings['__dynamic__'][ $field ] ) && is_string( $value ) ) {
				$text[] = gpante_support_site_text( $value );
			}
		}
		if ( isset( $element['elements'] ) ) {
			if ( ! is_array( $element['elements'] ) ) {
				throw new UnexpectedValueException( 'Invalid Elementor children.' );
			}
			$text[] = gpante_support_site_elementor_text( $element['elements'] );
		}
	}
	return implode( "\n", array_filter( $text ) );
}

/**
 * Determine pages that are safe destinations, never a source of customer data.
 *
 * @param WP_Post $post Public post.
 * @return bool
 */
function gpante_support_site_navigation_only( $post ) {
	$ids = array();
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'myaccount', 'cart', 'checkout' ) as $name ) {
			$ids[] = wc_get_page_id( $name );
		}
	}
	return in_array( (int) $post->ID, $ids, true )
		|| (bool) preg_match( '/\[(?:woocommerce_(?:my_account|cart|checkout)|wp_login_form)\b/i', $post->post_content );
}

/**
 * Read versioned static text after eligibility; Elementor meta changes invalidate.
 *
 * @param WP_Post $post Public post.
 * @return string
 * @throws UnexpectedValueException If the authoritative document shape is invalid.
 */
function gpante_support_site_post_text( $post ) {
	$has_elementor = metadata_exists( 'post', $post->ID, '_elementor_data' );
	$elementor     = get_post_meta( $post->ID, '_elementor_data', true );
	$version       = md5( 'static-text-v2|' . (int) $has_elementor . '|' . $post->post_content . "\n" . $post->post_excerpt . "\n" . ( is_string( $elementor ) ? $elementor : wp_json_encode( $elementor ) ) );
	$key           = 'gpante_site_' . $post->ID;
	$cached        = get_transient( $key );
	if ( is_array( $cached ) && ( $cached['version'] ?? '' ) === $version ) {
		return $cached['text'];
	}
	// Elementor content is authoritative when present; post_content may retain stale rendered HTML.
	if ( $has_elementor ) {
		$elements = '' === $elementor ? array() : $elementor;
		if ( is_string( $elements ) ) {
			if ( ! str_starts_with( ltrim( $elements ), '[' ) ) {
				throw new UnexpectedValueException( 'Invalid Elementor document.' );
			}
			$elements = json_decode( $elements, true, 512, JSON_THROW_ON_ERROR );
		}
		if ( ! is_array( $elements ) ) {
			throw new UnexpectedValueException( 'Invalid Elementor document.' );
		}
		$text = gpante_support_site_elementor_text( $elements );
	} else {
		$text = trim( gpante_support_site_text( $post->post_excerpt ) . "\n" . gpante_support_site_text( $post->post_content ) );
	}
	set_transient(
		$key,
		array(
			'version' => $version,
			'text'    => $text,
		),
		300
	);
	return $text;
}

/**
 * Combine the existing article and product vocabularies without dropping terms.
 *
 * @param string $search Literal normalized query.
 * @return array
 */
function gpante_support_site_search_groups( $search ) {
	$groups = gpante_support_catalog_search_groups( gpante_support_catalog_normalize( $search ), true );
	foreach ( $groups as &$group ) {
		if ( array_intersect( $group, array( 'باکس', 'جعبه' ) ) ) {
			$group = array( 'باکس', 'جعبه' );
		}
	}
	unset( $group );
	return $groups;
}

/**
 * Preserve literal AND terms and controlled aliases.
 *
 * @param string $text Searchable text.
 * @param array  $groups Explicit search groups.
 * @return bool
 */
function gpante_support_site_matches( $text, $groups ) {
	$text = gpante_support_catalog_normalize( $text );
	foreach ( $groups as $aliases ) {
		$hit = false;
		foreach ( $aliases as $alias ) {
			$hit = $hit || false !== strpos( $text, $alias );
		}
		if ( ! $hit ) {
			return false;
		}
	}
	return true;
}

/**
 * Rank title relevance and purpose, with deterministic ties applied by caller.
 *
 * @param string $title Public title.
 * @param string $type Content type.
 * @param string $intent Explicit search purpose.
 * @param string $search Literal query.
 * @return int
 */
function gpante_support_site_score( $title, $type, $intent, $search ) {
	$title = gpante_support_catalog_normalize( $title );
	$score = 0;
	foreach ( gpante_support_site_search_groups( $search ) as $group ) {
		foreach ( $group as $alias ) {
			if ( gpante_support_faq_whole_match( $title, $alias ) ) {
				$score += 20;
				break;
			}
			if ( false !== strpos( $title, $alias ) ) {
				$score += 10;
				break;
			}
		}
	}
	$install = (bool) preg_match( '/نصب|فعال\s*سازی|install|activation/u', $title );
	$learn   = (bool) preg_match( '/[اآ]موزش|دوره|یادگیری|training|course|tutorial/u', $title );
	if ( 'learning' === $intent && $learn && ! $install ) {
		$score += 40 + ( 'page' === $type || 'product' === $type ? 5 : 0 );
	} elseif ( 'installation' === $intent && $install ) {
		$score += 45;
	}
	return $score;
}

/**
 * Return a bounded relevant section, marking any omitted text explicitly.
 *
 * @param string $text Complete static text.
 * @param string $search Literal query.
 * @param int    $limit Character bound.
 * @return array
 */
function gpante_support_site_section( $text, $search, $limit ) {
	$length = mb_strlen( $text );
	$start  = 0;
	if ( $length > $limit && '' !== $search ) {
		$best = -1;
		foreach ( preg_split( '/\n/u', $text, -1, PREG_SPLIT_OFFSET_CAPTURE ) as $section ) {
			$score = gpante_support_site_score( $section[0], 'post', 'general', $search );
			if ( $score > $best && $score > 0 ) {
				$best  = $score;
				$start = max( 0, mb_strlen( substr( $text, 0, $section[1] ) ) - 200 );
			}
		}
		// A long single paragraph still needs a query-focused window.
		if ( 0 === $start ) {
			foreach ( gpante_support_site_search_groups( $search ) as $group ) {
				foreach ( $group as $alias ) {
					$offset = mb_stripos( $text, $alias );
					if ( false !== $offset && $offset >= $limit ) {
						$start = max( 0, $offset - 200 );
						break 2;
					}
				}
			}
		}
	}
	return array(
		'rendered'  => mb_substr( $text, $start, $limit ),
		'protected' => false,
		'truncated' => $length > $limit,
	);
}

/**
 * Recheck current public eligibility and build one bounded static record.
 *
 * @param WP_Post $post Current source.
 * @param string  $search Literal query.
 * @param bool    $detail Detailed read.
 * @return array|false
 */
function gpante_support_site_record( $post, $search, $detail = false ) {
	if ( ! $post || ! in_array( $post->post_type, array( 'post', 'page' ), true ) || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return false;
	}
	$link = get_permalink( $post );
	if ( ! is_string( $link ) || ! gpante_support_reply_url( $link, '' ) ) {
		return false;
	}
	$navigation = gpante_support_site_navigation_only( $post );
	if ( $detail && $navigation ) {
		return false;
	}
	$item = array(
		'id'                    => (int) $post->ID,
		'status'                => 'publish',
		'is_password_protected' => false,
		'content_type'          => $post->post_type,
		'navigation_only'       => $navigation,
		'link'                  => $link,
		'title'                 => array( 'rendered' => gpante_support_site_text( $post->post_title ) ),
	);
	if ( ! $navigation ) {
		$item[ $detail ? 'content' : 'excerpt' ] = gpante_support_site_section( gpante_support_site_post_text( $post ), $search, $detail ? 6000 : 900 );
	}
	return $item;
}

/**
 * Automatic pages/posts discovery plus eligible structured WooCommerce products.
 *
 * @param array  $params Validated query parameter names.
 * @param string $search Normalized query.
 * @param int    $page Response page.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_site( $params, $search, $page ) {
	// These spelling variants are site-search intent, never extra topic constraints.
	$search = preg_replace( '/(?<![\p{L}\p{N}_])اموزش(ی?)(?![\p{L}\p{N}_])/u', 'آموزش$1', $search );
	$intent = $params['intent'] ?? 'general';
	if ( ! in_array( $intent, array( 'general', 'learning', 'installation' ), true ) || isset( $params['categoryId'] ) ) {
		return gpante_support_catalog_error();
	}
	try {
		if ( isset( $params['contentId'] ) ) {
			$id = gpante_support_catalog_integer( $params['contentId'] );
			if ( ! $id || isset( $params['page'] ) || isset( $params['intent'] ) ) {
				return gpante_support_catalog_error();
			}
			$post = get_post( $id );
			if ( $post && 'product' === $post->post_type ) {
				return new WP_Error( 'gpante_support_product_details_required', 'Use Product_Details for products.', array( 'status' => 400 ) );
			}
			$item  = gpante_support_site_record( $post, $search, true );
			$query = (object) array( 'found_posts' => $item ? 1 : 0 );
			return gpante_support_catalog_response( $item ? array( $item ) : array(), $query, 1 );
		}
		$groups  = gpante_support_site_search_groups( $search );
		$matches = array();
		$batch   = 1;
		do {
			$query = gpante_support_catalog_run_query(
				array(
					'post_type'           => array( 'page', 'post' ),
					'post_status'         => 'publish',
					'has_password'        => false,
					'posts_per_page'      => 100,
					'paged'               => $batch,
					'ignore_sticky_posts' => true,
					'no_found_rows'       => false,
					'orderby'             => array( 'ID' => 'ASC' ),
				)
			);
			foreach ( $query->posts as $post ) {
				if ( ! $post || 'publish' !== $post->post_status || '' !== $post->post_password || ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
					continue;
				}
				$text = gpante_support_site_navigation_only( $post ) ? '' : gpante_support_site_post_text( $post );
				if ( gpante_support_site_matches( $post->post_title . ' ' . $text, $groups ) ) {
					$matches[] = array(
						'id'    => (int) $post->ID,
						'type'  => $post->post_type,
						'score' => gpante_support_site_score( $post->post_title, $post->post_type, $intent, $search ),
						'date'  => $post->post_date_gmt,
					);
				}
			}
			++$batch;
		} while ( $batch <= $query->max_num_pages );
		if ( ! function_exists( 'wc_get_product' ) || ! taxonomy_exists( 'product_visibility' ) ) {
			return gpante_support_catalog_error( 'catalog_unavailable', 503 );
		}
		$products = gpante_support_catalog_run_query(
			array(
				'post_type'          => 'product',
				'post_status'        => 'publish',
				'has_password'       => false,
				'posts_per_page'     => -1,
				'paged'              => 1,
				'fields'             => 'ids',
				'gpante_site_search' => true,
				's'                  => $search,
				'orderby'            => array( 'ID' => 'ASC' ),
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Required public visibility.
				'tax_query'          => gpante_support_catalog_visibility(),
			),
			$search
		);
		foreach ( $products->posts as $id ) {
			$post      = get_post( $id );
			$matches[] = array(
				'id'    => (int) $id,
				'type'  => 'product',
				'score' => gpante_support_site_score( $post->post_title, 'product', $intent, $search ),
				'date'  => $post->post_date_gmt,
			);
		}
		usort(
			$matches,
			static function ( $a, $b ) {
				$score = $b['score'] <=> $a['score'];
				if ( 0 !== $score ) {
					return $score;
				}
				$date = strcmp( $b['date'], $a['date'] );
				return 0 !== $date ? $date : $b['id'] <=> $a['id'];
			}
		);
		$items = array();
		foreach ( array_slice( $matches, ( $page - 1 ) * 3, 3 ) as $match ) {
			if ( 'product' === $match['type'] ) {
				$request = new WP_REST_Request( 'GET', '/gpante-support/v1/catalog' );
				$request->set_query_params( array( 'productId' => $match['id'] ) );
				$result = gpante_support_catalog_products( $request );
				if ( is_wp_error( $result ) || count( $result->get_data() ) !== 1 ) {
					return gpante_support_catalog_error( 'catalog_changed', 503 );
				}
				$item                 = $result->get_data()[0];
				$item['content_type'] = 'product';
			} else {
				$item = gpante_support_site_record( get_post( $match['id'] ), $search );
				if ( ! $item ) {
					return gpante_support_catalog_error( 'catalog_changed', 503 );
				}
			}
			$items[] = $item;
		}
		return gpante_support_catalog_response( $items, (object) array( 'found_posts' => count( $matches ) ), 3 );
	} catch ( Throwable $error ) {
		return gpante_support_catalog_error( 'catalog_unavailable', 503 );
	}
}
