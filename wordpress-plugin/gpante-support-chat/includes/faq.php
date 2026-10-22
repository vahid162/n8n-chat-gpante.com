<?php
/**
 * Read the approved public Elementor FAQ without executing its markup.
 *
 * @package GPanteSupportChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Allow public references and the verified design-support destination.
 *
 * @param mixed $url Source link.
 * @return bool
 */
function gpante_support_faq_link( $url ) {
	if ( ! is_string( $url ) || strlen( $url ) > 512 ) {
		return false;
	}
	if ( 'https://t.me/gpante_designer' === $url || preg_match( '~^https://gpante\.com/faq-ai/\#faq-[a-z0-9][a-z0-9-]{0,79}$~D', $url ) ) {
		return true;
	}
	return 'https://gpante.com/' !== $url && gpante_support_reply_url( $url, '' )
		&& ! preg_match( '~^https://gpante\.com/(?:wp-json|wp-content|wp-includes|wp-admin|wp-login\.php|wp-signup\.php|xmlrpc\.php|download|downloads|wc-api|my-account|checkout|cart)(?:/|$)~i', $url )
		&& ! preg_match( '/\.[a-z\d]{1,8}$/i', $url );
}

/**
 * Extract plain public text, keeping block boundaries and discarding embeds.
 *
 * @param DOMNode $node Public question or answer.
 * @return string
 */
function gpante_support_faq_text( $node ) {
	$copy = $node->cloneNode( true );
	// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
	$document = $node->ownerDocument;
	$xpath    = new DOMXPath( $document );
	foreach ( $xpath->query( './/script|.//style|.//template|.//iframe|.//object|.//form|.//noscript', $copy ) as $unsafe ) {
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Native DOM property.
		$unsafe->parentNode->removeChild( $unsafe );
	}
	$html = $document->saveHTML( $copy );
	$html = preg_replace( '~</(?:p|div|li|h[1-6])>|<br\s*/?>~i', ' ', $html );
	$text = wp_strip_all_tags( html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
	return trim( preg_replace( '/\s+/u', ' ', $text ) );
}

/**
 * Pair every published question with its own answer before search/pagination.
 *
 * @param string $html Anonymous public page HTML.
 * @return array|WP_Error
 */
function gpante_support_faq_parse( $html ) {
	if ( ! class_exists( 'DOMDocument' ) || ! is_string( $html ) || '' === $html || strlen( $html ) >= 1048576 ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	$end = strripos( $html, '</html>' );
	// The site cache appends a comment after the complete HTML document.
	if ( false === $end || '' !== trim( preg_replace( '~<!--.*?-->~s', '', substr( $html, $end + 7 ) ) ) ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	$dom = new DOMDocument();
	if ( ! $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING ) ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	$xpath = new DOMXPath( $dom );
	$roots = $xpath->query( '//*[@data-elementor-id="51415" and @data-elementor-type="wp-page"]' );
	if ( 1 !== $roots->length ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	$nodes = $xpath->query( './/details[@data-faq-item or @data-element_type="e-accordion-item"]', $roots->item( 0 ) );
	if ( 0 === $nodes->length || $nodes->length > 1000 ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	$items = array();
	foreach ( $nodes as $node ) {
		$questions = $xpath->query( './summary', $node );
		$answers   = $xpath->query( './/*[@data-faq-answer or @data-element_type="e-accordion-item-content"]', $node );
		if ( 1 !== $questions->length || 1 !== $answers->length ) {
			return gpante_support_catalog_error( 'faq_unavailable', 503 );
		}
		$id   = $node->getAttribute( 'id' );
		$link = 'https://gpante.com/faq-ai/';
		if ( preg_match( '/^faq-[a-z0-9][a-z0-9-]{0,79}$/D', $id ) ) {
			$link .= '#' . $id;
		} else {
			// New native items remain readable before an optional friendly anchor is added.
			$element_id = $node->getAttribute( 'data-id' );
			if ( ! preg_match( '/^[a-zA-Z0-9_-]{1,64}$/D', $element_id ) ) {
				return gpante_support_catalog_error( 'faq_unavailable', 503 );
			}
			$id = 'elementor-' . $element_id;
		}
		$question = gpante_support_faq_text( $questions->item( 0 ) );
		$answer   = gpante_support_faq_text( $answers->item( 0 ) );
		if ( isset( $items[ $id ] ) || '' === $question || '' === $answer ) {
			return gpante_support_catalog_error( 'faq_unavailable', 503 );
		}
		$links = array();
		foreach ( $xpath->query( './/a[@href]', $answers->item( 0 ) ) as $anchor ) {
			$url = trim( $anchor->getAttribute( 'href' ) );
			if ( strlen( $url ) <= 512 && gpante_support_faq_link( $url ) && count( $links ) < 3 ) {
				$links[ $url ] = array(
					'title' => mb_substr( gpante_support_faq_text( $anchor ), 0, 100 ),
					'url'   => $url,
				);
			}
		}
		$items[ $id ] = array(
			'id'                    => $id,
			'page_id'               => 51415,
			'status'                => 'publish',
			'is_password_protected' => false,
			'link'                  => $link,
			'title'                 => array( 'rendered' => mb_substr( $question, 0, 300 ) ),
			'content'               => array(
				'rendered'  => mb_substr( $answer, 0, 2400 ),
				'protected' => false,
				'truncated' => mb_strlen( $question ) > 300 || mb_strlen( $answer ) > 2400,
			),
			'links'                 => array_values( $links ),
			// Full text, rather than the response snippet, determines candidate coverage.
			'_question'             => gpante_support_faq_search_normalize( $question ),
			'_text'                 => gpante_support_faq_search_normalize( $question . ' ' . $answer ),
		);
	}
	return array_values( $items );
}

/**
 * Normalize only known FAQ phrases; keep every other term and explicit condition.
 *
 * @param string $value Public query or FAQ text.
 * @return string
 */
function gpante_support_faq_search_normalize( $value ) {
	$value = gpante_support_catalog_normalize( $value );
	$forms = array(
		'شماره (?:تلفن همراه|همراه|موبایل)'                => 'شماره تلفن',
		'جا (?:نمی ?ره|نمیره|نمی ?رود|نمی ?روند|نشود|نشد)' => 'جا نشدن',
		'باز (?:نمی ?شه|نمیشه|نمی ?شود|نشود|نشد)'          => 'باز نشدن',
		'باز(?:کردن| کنم| کردن)'                           => 'باز کردن',
		'ای میل'                                           => 'ایمیل',
	);
	foreach ( $forms as $form => $replacement ) {
		$value = preg_replace( '~(?<![\p{L}\p{N}])(?:' . $form . ')(?![\p{L}\p{N}])~u', $replacement, $value );
	}
	return $value;
}

/**
 * Prefer whole phrases/words without excluding existing literal substring matches.
 *
 * @param string $text Normalized public text.
 * @param string $term Literal normalized term or phrase.
 * @return bool
 */
function gpante_support_faq_whole_match( $text, $term ) {
	return '' !== $term && 1 === preg_match( '~(?<![\p{L}\p{N}_])' . preg_quote( $term, '~' ) . '(?![\p{L}\p{N}_])~u', $text );
}

/**
 * Read only the approved, currently public FAQ and rank complete Q/A records.
 *
 * @param string $search Validated search.
 * @param int    $page Validated page.
 * @return WP_REST_Response|WP_Error
 */
function gpante_support_catalog_faq( $search, $page ) {
	$post = get_post( 51415 );
	if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return gpante_support_catalog_error( 'faq_unavailable', 503 );
	}
	// Elementor can save meta without advancing post_modified; include its current data.
	$component_version = defined( 'GPANTE_SUPPORT_VERSION' ) ? GPANTE_SUPPORT_VERSION : 'candidate';
	$version           = md5( $component_version . '|' . $post->post_modified_gmt . $post->post_content . maybe_serialize( get_post_meta( 51415, '_elementor_data', true ) ) );
	$cached            = get_transient( 'gpante_support_faq_cache' );
	if ( is_array( $cached ) && ( $cached['version'] ?? null ) === $version && is_array( $cached['items'] ?? null ) && ! empty( $cached['items'] ) && count( $cached['items'] ) <= 1000 ) {
		$items = $cached['items'];
	} else {
		$url  = add_query_arg( 'gpante_faq_source', $version, 'https://gpante.com/faq-ai/' );
		$read = wp_remote_get(
			$url,
			array(
				'timeout'             => 8,
				'redirection'         => 0,
				'sslverify'           => true,
				'limit_response_size' => 1048576,
				'cookies'             => array(),
				'headers'             => array(
					'Accept'        => 'text/html',
					'Cache-Control' => 'no-cache',
				),
			)
		);
		if ( is_wp_error( $read ) || 200 !== wp_remote_retrieve_response_code( $read ) || 0 !== stripos( (string) wp_remote_retrieve_header( $read, 'content-type' ), 'text/html' ) ) {
			return gpante_support_catalog_error( 'faq_unavailable', 503 );
		}
		$items = gpante_support_faq_parse( wp_remote_retrieve_body( $read ) );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		set_transient(
			'gpante_support_faq_cache',
			array(
				'version' => $version,
				'items'   => $items,
			),
			300
		);
	}
	$search = gpante_support_faq_search_normalize( $search );
	$groups = gpante_support_catalog_search_groups( $search, true );
	foreach ( $groups as &$aliases ) {
		// A mobile query may concern a phone field; literal device titles still rank first.
		if ( array( 'موبایل' ) === $aliases ) {
			$aliases[] = 'تلفن';
		} elseif ( in_array( $aliases[0], array( 'ایمیل', 'email' ), true ) ) {
			$aliases = array( 'ایمیل', 'email' );
		}
	}
	unset( $aliases );
	$matches = array();
	foreach ( $items as $item ) {
		$question   = $item['_question'];
		$text       = $item['_text'];
		$title_hits = 0;
		$whole_hits = 0;
		foreach ( $groups as $aliases ) {
			$found     = false;
			$title_hit = false;
			$whole_hit = false;
			foreach ( $aliases as $alias ) {
				if ( str_contains( $text, $alias ) ) {
					$found = true;
				}
				if ( str_contains( $question, $alias ) ) {
					$title_hit = true;
					$whole_hit = $whole_hit || gpante_support_faq_whole_match( $question, $alias );
				}
			}
			if ( ! $found ) {
				continue 2;
			}
			$title_hits += (int) $title_hit;
			$whole_hits += (int) $whole_hit;
		}
		unset( $item['_question'], $item['_text'] );
		$item['_score'] = array( (int) gpante_support_faq_whole_match( $question, $search ), $title_hits, $whole_hits );
		$matches[]      = $item;
	}
	usort(
		$matches,
		static function ( $a, $b ) {
			$score = $b['_score'] <=> $a['_score'];
			return 0 !== $score ? $score : strcmp( $a['id'], $b['id'] );
		}
	);
	$total = count( $matches );
	$items = array_slice( $matches, ( $page - 1 ) * 3, 3 );
	foreach ( $items as &$item ) {
		unset( $item['_score'] );
	}
	unset( $item );
	$response = new WP_REST_Response( $items, 200 );
	$response->header( 'X-WP-Total', $total );
	$response->header( 'X-WP-TotalPages', (int) ceil( $total / 3 ) );
	$response->header( 'Cache-Control', 'no-store' );
	return $response;
}
