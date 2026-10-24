<?php
/**
 * Incrementally maintained public-static discovery shared by chat and native search.
 *
 * @package GPanteSupportChat
 */

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- This plugin-owned index requires current atomic reads/writes.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Interpolated identifiers are only fixed WP table names; all query values are prepared.

/** Return the current site's fixed public index table name. */
function gpante_support_search_index_table() {
	global $wpdb;
	return $wpdb->prefix . 'gpante_support_search';
}

/** Install once; existing sites bootstrap in resumable background batches. */
function gpante_support_search_index_upgrade() {
	if ( 1 === (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		return;
	}
	$lease = gpante_support_search_index_lease();
	if ( ! $lease ) {
		return;
	}
	global $wpdb;
	try {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = gpante_support_search_index_table();
		$collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE $table (
			post_id bigint(20) unsigned NOT NULL,
			content_type varchar(20) NOT NULL,
			title text NOT NULL,
			body longtext NOT NULL,
			invalid tinyint(1) unsigned NOT NULL DEFAULT 0,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (post_id),
			KEY invalid (invalid)
			) $collate;"
		);
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
			return;
		}
		update_option(
			'gpante_support_search_state',
			array(
				'cursor'       => 0,
				'ready'        => false,
				'reconciledAt' => null,
			),
			false
		);
		update_option( 'gpante_support_search_schema', 1, false );
		wp_schedule_single_event( time() + 1, 'gpante_support_search_index_batch' );
	} finally {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'gpante_support_search_lease', $lease ) );
		wp_cache_delete( 'gpante_support_search_lease', 'options' );
	}
}

/** Reactivation must reconcile edits made while this plugin was inactive. */
function gpante_support_search_index_activate() {
	gpante_support_search_index_upgrade();
	if ( 1 === (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		update_option( 'gpante_support_search_dirty', wp_generate_uuid4(), false );
		$state           = get_option( 'gpante_support_search_state', array() );
		$state['cursor'] = 0;
		$state['ready']  = false;
		update_option( 'gpante_support_search_state', $state, false );
		wp_schedule_single_event( time() + 1, 'gpante_support_search_index_batch' );
	}
}

/** Acquire an expiring compare-and-delete lease; return its private token or false. */
function gpante_support_search_index_lease() {
	global $wpdb;
	$name = 'gpante_support_search_lease';
	$old  = get_option( $name, '' );
	if ( is_string( $old ) && $old && (int) substr( $old, 0, 10 ) < time() ) {
		// The exact old value prevents a late worker from deleting a renewed lease.
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $name, $old ) );
		wp_cache_delete( $name, 'options' );
	}
	$token = ( time() + 120 ) . ':' . wp_generate_uuid4();
	return add_option( $name, $token, '', false ) ? $token : false;
}

/**
 * Reconcile at most 100 IDs; an unfinished batch never claims complete coverage.
 *
 * @throws RuntimeException Internal traversal failures are caught and rescheduled.
 */
function gpante_support_search_index_batch() {
	if ( 1 !== (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		return;
	}
	$lease = gpante_support_search_index_lease();
	if ( ! $lease ) {
		wp_schedule_single_event( time() + 30, 'gpante_support_search_index_batch' );
		return;
	}
	global $wpdb;
	try {
		$state = get_option( 'gpante_support_search_state', array() );
		$dirty = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'gpante_support_search_dirty' ) );
		if ( ( $state['dirtyRevision'] ?? '' ) !== $dirty ) {
			$state['cursor']        = 0;
			$state['ready']         = false;
			$state['dirtyRevision'] = $dirty;
		}
		$cursor = (int) ( $state['cursor'] ?? 0 );
		$ids    = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE ID>%d AND post_type IN ('page','post','product') ORDER BY ID ASC LIMIT 100", $cursor ) );
		if ( $wpdb->last_error ) {
			throw new RuntimeException( 'Public index traversal failed.' );
		}
		foreach ( $ids as $id ) {
			if ( ! gpante_support_search_index_update( (int) $id ) ) {
				throw new RuntimeException( 'Public index update delayed.' );
			}
			$state['cursor'] = (int) $id;
		}
		if ( count( $ids ) < 100 ) {
			$table = gpante_support_search_index_table();
			// Also remove orphans left by out-of-band deletions; normal deletions use lifecycle hooks.
			$wpdb->query( "DELETE i FROM $table i LEFT JOIN $wpdb->posts p ON p.ID=i.post_id WHERE p.ID IS NULL OR p.post_type NOT IN ('page','post','product')" );
			if ( $wpdb->last_error ) {
				throw new RuntimeException( 'Public index cleanup failed.' );
			}
			$state['cursor']       = 0;
			$state['ready']        = true;
			$state['reconciledAt'] = gmdate( 'c' );
		} else {
			wp_schedule_single_event( time() + 1, 'gpante_support_search_index_batch' );
		}
		if ( $lease !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'gpante_support_search_lease' ) ) ) {
			throw new RuntimeException( 'Public index lease expired.' );
		}
		$current_dirty = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'gpante_support_search_dirty' ) );
		if ( $current_dirty !== $dirty ) {
			$state['cursor']        = 0;
			$state['ready']         = false;
			$state['dirtyRevision'] = $current_dirty;
			wp_schedule_single_event( time() + 5, 'gpante_support_search_index_batch' );
		} elseif ( ! empty( $state['ready'] ) && $dirty ) {
			// Never clear a marker created by another writer after this cycle's snapshot.
			$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'gpante_support_search_dirty', $dirty ) );
			wp_cache_delete( 'gpante_support_search_dirty', 'options' );
			$state['dirtyRevision'] = '';
		}
		update_option( 'gpante_support_search_state', $state, false );
	} catch ( Throwable $error ) {
		wp_schedule_single_event( time() + 30, 'gpante_support_search_index_batch' );
	} finally {
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", 'gpante_support_search_lease', $lease ) );
		wp_cache_delete( 'gpante_support_search_lease', 'options' );
	}
}

/**
 * A delayed lifecycle write explicitly makes coverage incomplete until reconciliation.
 *
 * @param int $post_id Current post ID.
 */
function gpante_support_search_index_changed( $post_id ) {
	if ( 1 === (int) get_option( 'gpante_support_search_schema', 0 ) && ! gpante_support_search_index_update( $post_id ) ) {
		update_option( 'gpante_support_search_dirty', wp_generate_uuid4(), false );
		$state          = get_option( 'gpante_support_search_state', array() );
		$state['ready'] = false;
		update_option( 'gpante_support_search_state', $state, false );
		wp_schedule_single_event( time() + 5, 'gpante_support_search_index_batch' );
	}
}

/**
 * Check eligibility without rendering content or copying customer/private metadata.
 *
 * @param WP_Post|null $post Current post.
 */
function gpante_support_search_index_eligible( $post ) {
	if ( ! $post || ! in_array( $post->post_type, array( 'page', 'post', 'product' ), true ) || 'publish' !== $post->post_status || '' !== $post->post_password ) {
		return false;
	}
	if ( 'product' === $post->post_type ) {
		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $post->ID ) : false;
		return $product && 'publish' === $product->get_status() && 'variation' !== $product->get_type()
			&& in_array( $product->get_catalog_visibility(), array( 'visible', 'catalog', 'search' ), true );
	}
	return true;
}

/**
 * Update one current document under a connection-scoped per-document lock.
 *
 * @param int $post_id Current post ID.
 */
function gpante_support_search_index_update( $post_id ) {
	if ( 1 !== (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		return false;
	}
	global $wpdb;
	$table = gpante_support_search_index_table();
	$lock  = 'gpante_si_' . md5( DB_NAME . ':' . $wpdb->prefix . ':' . (int) $post_id );
	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
		wp_schedule_single_event( time() + 5, 'gpante_support_search_index_update', array( (int) $post_id ) );
		return false;
	}
	try {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! gpante_support_search_index_eligible( $post ) ) {
			return false !== $wpdb->delete( $table, array( 'post_id' => (int) $post_id ), array( '%d' ) );
		}
		$invalid = 0;
		try {
			$body = gpante_support_site_navigation_only( $post ) ? '' : gpante_support_site_post_text( $post );
		} catch ( Throwable $error ) {
			$body    = '';
			$invalid = 1;
		}
		return false !== $wpdb->replace(
			$table,
			array(
				'post_id'      => (int) $post_id,
				'content_type' => $post->post_type,
				'title'        => gpante_support_site_normalize( gpante_support_site_text( $post->post_title ) ),
				'body'         => gpante_support_site_normalize( $body ),
				'invalid'      => $invalid,
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	} finally {
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}
}

/**
 * Relevant meta writes can occur without changing post_modified.
 *
 * @param int    $meta_id Changed metadata ID.
 * @param int    $post_id Current post ID.
 * @param string $key Changed metadata key.
 */
function gpante_support_search_index_meta( $meta_id, $post_id, $key ) {
	if ( in_array( $key, array( '_elementor_data', '_elementor_edit_mode', '_product_attributes', '_price', '_regular_price', '_sale_price', '_stock_status' ), true ) ) {
		gpante_support_search_index_changed( $post_id );
	}
}

/**
 * Visibility and verified attributes can change independently of the post row.
 *
 * @param int    $post_id Current post ID.
 * @param array  $terms Requested terms.
 * @param array  $term_taxonomy_ids Assigned term taxonomy IDs.
 * @param string $taxonomy Changed taxonomy.
 */
function gpante_support_search_index_terms( $post_id, $terms, $term_taxonomy_ids, $taxonomy ) {
	if ( 'product_visibility' === $taxonomy || str_starts_with( $taxonomy, 'pa_' ) ) {
		gpante_support_search_index_changed( $post_id );
	}
}

/** Return public aggregate coverage plus bounded administrator source-health details. */
function gpante_support_search_index_health() {
	global $wpdb;
	$state        = get_option( 'gpante_support_search_state', array() );
	$dirty        = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name=%s", 'gpante_support_search_dirty' ) );
	$initializing = (bool) $wpdb->last_error || 1 !== (int) get_option( 'gpante_support_search_schema', 0 ) || empty( $state['ready'] ) || (bool) $dirty;
	$rows         = array();
	$invalid      = 0;
	$documents    = 0;
	if ( 1 === (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		$table     = gpante_support_search_index_table();
		$aggregate = $wpdb->get_row( "SELECT COUNT(*) AS documents, COALESCE(SUM(invalid),0) AS invalid FROM $table", ARRAY_A );
		if ( $wpdb->last_error || ! is_array( $aggregate ) ) {
			$initializing = true;
			$exists       = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			if ( ! $wpdb->last_error && $exists !== $table ) {
				delete_option( 'gpante_support_search_schema' );
			}
		} else {
			$invalid   = (int) $aggregate['invalid'];
			$documents = (int) $aggregate['documents'];
			if ( $invalid ) {
				$rows = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM $table WHERE invalid=1 ORDER BY post_id ASC LIMIT 20" ) );
			}
		}
	}
	return array(
		'complete'         => ! $initializing && 0 === $invalid,
		'invalidDocuments' => $invalid,
		'initializing'     => $initializing,
		'documents'        => $documents,
		'invalidIds'       => $rows,
		'reconciledAt'     => $state['reconciledAt'] ?? null,
	);
}

/**
 * Derive a bounded native topic query from a visitor's full question.
 *
 * @param mixed $value Native search text.
 * @return string|null Normalized terms, or null for an explicitly rejected query.
 */
function gpante_support_search_index_native_terms( $value ) {
	if ( ! is_string( $value ) || mb_strlen( $value ) > 4000 ) {
		return null;
	}
	$value = gpante_support_site_normalize( $value );
	$value = trim( preg_replace( '/[^\p{L}\p{N}._-]+/u', ' ', $value ) );
	$words = array_values( array_unique( preg_split( '/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ) );
	// Request grammar is source-independent; topic, version, free and manufacturing terms stay required.
	$grammar = array( 'سلام', 'لطفا', 'خواهش', 'ایا', 'من', 'ما', 'به', 'برای', 'را', 'رو', 'از', 'در', 'و', 'یک', 'یه', 'چند', 'این', 'اون', 'ان', 'هم', 'بهم', 'برام', 'دارید', 'دارین', 'داره', 'هست', 'است', 'هستند', 'کجاست', 'کجا', 'چیست', 'چیه', 'چه', 'چطور', 'چگونه', 'معرفی', 'کن', 'کنید', 'بده', 'بدید', 'بفرست', 'پیدا', 'نشان', 'نمایش', 'میخوام', 'میخواهم', 'می', 'خوام', 'خواهم', 'توانید', 'میتونید', 'میشه', 'شود', 'کنم', 'کنیم', 'شما', 'please', 'where', 'is', 'are', 'do', 'you', 'have', 'show', 'me', 'find', 'i', 'want', 'a', 'an', 'the', 'for', 'and', 'of' );
	$words   = array_values( array_diff( $words, $grammar ) );
	$search  = implode( ' ', $words );
	return $search && count( $words ) <= 16 && mb_strlen( $search ) <= 320 ? $search : null;
}

/**
 * Mark only normal front-end main search; theme AJAX/private queries keep their behavior.
 *
 * @param WP_Query $query Native WordPress query.
 */
function gpante_support_search_index_native( $query ) {
	if ( ! is_admin() && $query->is_main_query() && $query->is_search() ) {
		$search = gpante_support_search_index_native_terms( $query->get( 's' ) );
		$query->set( 'gpante_site_index', true );
		if ( null !== $search ) {
			$query->set( 'gpante_index_search', $search );
			$intent = preg_match( '/نصب|فعال\s*سازی|install|activation/u', $search ) ? 'installation'
				: ( preg_match( '/اموزش|دوره|یادگیری|training|course|tutorial/u', $search ) ? 'learning' : 'general' );
			$query->set( 'gpante_index_intent', $intent );
			$query->set( 'post_status', 'publish' );
			$query->set( 'has_password', false );
		} else {
			$query->set( 'gpante_index_invalid_query', true );
		}
	}
}

/**
 * Replace native fulltext matching only for the explicitly scoped shared selector.
 *
 * @param string   $search Existing native search SQL.
 * @param WP_Query $query Current query.
 */
function gpante_support_search_index_search_clause( $search, $query ) {
	return $query->get( 'gpante_site_index' ) ? '' : $search;
}

/** Current public product visibility, also checked for spelling suggestions. */
function gpante_support_search_index_visibility_sql() {
	global $wpdb;
	return " AND ($wpdb->posts.post_type<>'product' OR NOT EXISTS (SELECT 1 FROM $wpdb->term_relationships gpante_tr INNER JOIN $wpdb->term_taxonomy gpante_tt ON gpante_tt.term_taxonomy_id=gpante_tr.term_taxonomy_id INNER JOIN $wpdb->terms gpante_t ON gpante_t.term_id=gpante_tt.term_id WHERE gpante_tr.object_id=$wpdb->posts.ID AND gpante_tt.taxonomy='product_visibility' AND gpante_t.slug IN ('exclude-from-catalog','exclude-from-search') GROUP BY gpante_tr.object_id HAVING COUNT(DISTINCT gpante_t.slug)=2))";
}

/**
 * Apply literal AND matching and shared title/purpose relevance before pagination.
 *
 * @param array    $clauses Existing SQL clauses.
 * @param WP_Query $query Current query.
 */
function gpante_support_search_index_clauses( $clauses, $query ) {
	if ( ! $query->get( 'gpante_site_index' ) ) {
		return $clauses;
	}
	global $wpdb;
	if ( $query->get( 'gpante_index_invalid_query' ) ) {
		$clauses['where'] .= ' AND 1=0';
		return $clauses;
	}
	if ( 1 !== (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		$clauses['where'] .= ' AND 1=0';
		return $clauses;
	}
	$table             = gpante_support_search_index_table();
	$clauses['join']  .= " INNER JOIN $table gpante_si ON gpante_si.post_id=$wpdb->posts.ID";
	$clauses['where'] .= " AND gpante_si.invalid=0 AND $wpdb->posts.post_status='publish' AND $wpdb->posts.post_password='' AND $wpdb->posts.post_type IN ('page','post','product')";
	// Both exclusions mean hidden; catalog-only and search-only remain eligible.
	$clauses['where'] .= gpante_support_search_index_visibility_sql();
	$scores            = array();
	foreach ( gpante_support_site_search_groups( $query->get( 'gpante_index_search' ) ) as $aliases ) {
		$matches = array();
		$titles  = array();
		$whole   = array();
		foreach ( $aliases as $alias ) {
			$pattern   = '%' . $wpdb->esc_like( $alias ) . '%';
			$matches[] = $wpdb->prepare( '(gpante_si.title LIKE %s OR gpante_si.body LIKE %s)', $pattern, $pattern );
			$titles[]  = $wpdb->prepare( 'gpante_si.title LIKE %s', $pattern );
			$whole[]   = $wpdb->prepare( 'gpante_si.title REGEXP %s', '(^|[^[:alnum:]_])' . preg_quote( $alias, '/' ) . '([^[:alnum:]_]|$)' );
		}
		$clauses['where'] .= ' AND (' . implode( ' OR ', $matches ) . ')';
		$scores[]          = '(CASE WHEN (' . implode( ' OR ', $whole ) . ') THEN 20 WHEN (' . implode( ' OR ', $titles ) . ') THEN 10 ELSE 0 END)';
	}
	$intent = $query->get( 'gpante_index_intent' );
	if ( 'learning' === $intent ) {
		$scores[] = "(CASE WHEN gpante_si.title REGEXP 'اموزش|دوره|یادگیری|training|course|tutorial' AND gpante_si.title NOT REGEXP 'نصب|فعال[[:space:]]*سازی|install|activation' THEN 40+(CASE WHEN gpante_si.content_type IN ('page','product') THEN 5 ELSE 0 END) ELSE 0 END)";
	} elseif ( 'installation' === $intent ) {
		$scores[] = "(CASE WHEN gpante_si.title REGEXP 'نصب|فعال[[:space:]]*سازی|install|activation' THEN 45 ELSE 0 END)";
	}
	$clauses['orderby'] = ( $scores ? '(' . implode( '+', $scores ) . ') DESC, ' : '' ) . "$wpdb->posts.post_date_gmt DESC, $wpdb->posts.ID DESC";
	return $clauses;
}

/**
 * Return the same safe bounded cards as the AI's fixed site provider.
 *
 * @param WP_REST_Request $request Public bounded request.
 */
function gpante_support_search_index_endpoint( $request ) {
	$params = gpante_support_catalog_query_params( $request, array( 'search', 'page', 'intent' ) );
	if ( is_wp_error( $params ) ) {
		return $params;
	}
	$search = isset( $params['search'] ) ? gpante_support_catalog_search_text( $params['search'] ) : '';
	$page   = isset( $params['page'] ) ? gpante_support_catalog_integer( $params['page'] ) : 1;
	return false === $search || false === $page ? gpante_support_catalog_error() : gpante_support_catalog_site( $params, $search, $page );
}

/**
 * True only for one Unicode insertion, deletion, or substitution.
 *
 * @param string $left Normalized topic token.
 * @param string $right Normalized public title token.
 */
function gpante_support_search_index_edit_one( $left, $right ) {
	$left  = preg_split( '//u', $left, -1, PREG_SPLIT_NO_EMPTY );
	$right = preg_split( '//u', $right, -1, PREG_SPLIT_NO_EMPTY );
	if ( abs( count( $left ) - count( $right ) ) > 1 ) {
		return false;
	}
	$left_count  = count( $left );
	$right_count = count( $right );
	$i           = 0;
	$j           = 0;
	$n           = 0;
	while ( $i < $left_count && $j < $right_count ) {
		if ( $left[ $i ] === $right[ $j ] ) {
			++$i;
			++$j;
			continue;
		}
		if ( ++$n > 1 ) {
			return false;
		}
		if ( $left_count >= $right_count ) {
			++$i;
		}
		if ( $right_count >= $left_count ) {
			++$j;
		}
	}
	return 1 === $n + ( $left_count - $i ) + ( $right_count - $j );
}

/**
 * Suggest one unambiguous topic spelling; never alter constraints or execute it silently.
 *
 * @param string $search Validated literal query.
 */
function gpante_support_search_index_suggestion( $search ) {
	if ( 1 !== (int) get_option( 'gpante_support_search_schema', 0 ) ) {
		return null;
	}
	global $wpdb;
	$table     = gpante_support_search_index_table();
	$tokens    = explode( ' ', gpante_support_site_normalize( $search ) );
	$protected = array( 'رایگان', 'مجانی', 'پولی', 'پلکسی', 'چوب', 'طلق', 'فلز', 'ضخامت', 'متریال', 'میلیمتر', 'میلی', 'متر', 'فرمت', 'اموزش', 'نسخه', 'متصل', 'پیوسته', 'جدا' );
	$proposals = array();
	foreach ( $tokens as $position => $token ) {
		if ( ! preg_match( '/^[\p{Arabic}]{5,24}$/uD', $token ) || in_array( $token, $protected, true ) ) {
			continue;
		}
		// A bounded title vocabulary gives suggestions, not an alternative claim of catalog completeness.
		$prefix = '%' . $wpdb->esc_like( mb_substr( $token, 0, 1 ) ) . '%';
		$public = gpante_support_search_index_visibility_sql();
		$titles = $wpdb->get_col( $wpdb->prepare( "SELECT gpante_si.title FROM $table gpante_si INNER JOIN $wpdb->posts ON $wpdb->posts.ID=gpante_si.post_id WHERE gpante_si.invalid=0 AND $wpdb->posts.post_status='publish' AND $wpdb->posts.post_password='' AND $wpdb->posts.post_type IN ('page','post','product') $public AND gpante_si.title LIKE %s ORDER BY $wpdb->posts.ID DESC LIMIT 100", $prefix ) );
		if ( $wpdb->last_error ) {
			return null;
		}
		$words = array();
		foreach ( $titles as $title ) {
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY ) as $word ) {
				if ( ! in_array( $word, $protected, true ) && gpante_support_search_index_edit_one( $token, $word ) ) {
					$words[ $word ] = true;
				}
			}
		}
		if ( 1 === count( $words ) ) {
			$proposal              = $tokens;
			$proposal[ $position ] = array_key_first( $words );
			$proposals[]           = implode( ' ', $proposal );
		}
	}
	return 1 === count( $proposals ) ? $proposals[0] : null;
}

/** Show an explicit coverage notice on native search, never an incomplete "no matches" claim. */
function gpante_support_search_index_notice() {
	global $wp_query;
	if ( ! $wp_query || ! $wp_query->get( 'gpante_site_index' ) ) {
		return;
	}
	if ( $wp_query->get( 'gpante_index_invalid_query' ) ) {
		echo '<aside class="gpante-search-query-limit" role="status">' . esc_html__( 'لطفاً جست‌وجو را با حداکثر ۱۶ واژهٔ موضوعی کوتاه‌تر کنید؛ نسخه، رایگان‌بودن و مشخصات موردنیاز را نگه دارید.', 'gpante-support-chat' ) . '</aside>';
		return;
	}
	$health = gpante_support_search_index_health();
	if ( ! $health['complete'] ) {
		echo '<aside class="gpante-search-coverage" role="status">' . esc_html__( 'جست‌وجو فعلاً همهٔ منابع را پوشش نمی‌دهد؛ بخشی از محتوا در حال آماده‌سازی است یا نیاز به بررسی دارد.', 'gpante-support-chat' ) . '</aside>';
	}
	if ( 0 === (int) $wp_query->found_posts ) {
		$suggestion = gpante_support_search_index_suggestion( $wp_query->get( 'gpante_index_search' ) );
		if ( $suggestion ) {
			// translators: %s is an explicit suggested search phrase.
			echo '<aside class="gpante-search-suggestion"><a href="' . esc_url( add_query_arg( 's', $suggestion, home_url( '/' ) ) ) . '">' . esc_html( sprintf( __( 'آیا منظورتان «%s» بود؟', 'gpante-support-chat' ), $suggestion ) ) . '</a></aside>';
		}
	}
}

/** Register lifecycle updates, a bounded periodic reconciliation, and scoped native search. */
function gpante_support_search_index_register() {
	add_action( 'save_post', 'gpante_support_search_index_changed', 30, 1 );
	add_action( 'deleted_post', 'gpante_support_search_index_changed', 30, 1 );
	add_action( 'woocommerce_update_product', 'gpante_support_search_index_changed', 30, 1 );
	add_action( 'woocommerce_new_product', 'gpante_support_search_index_changed', 30, 1 );
	add_action( 'deleted_term_relationships', 'gpante_support_search_index_changed', 30, 1 );
	foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
		add_action( $hook, 'gpante_support_search_index_meta', 30, 3 );
	}
	add_action( 'set_object_terms', 'gpante_support_search_index_terms', 30, 4 );
	add_action( 'gpante_support_search_index_batch', 'gpante_support_search_index_batch' );
	add_action( 'gpante_support_search_index_update', 'gpante_support_search_index_update' );
	add_action( 'pre_get_posts', 'gpante_support_search_index_native', 30 );
	add_filter( 'posts_search', 'gpante_support_search_index_search_clause', 30, 2 );
	add_filter( 'posts_clauses', 'gpante_support_search_index_clauses', 30, 2 );
	add_action( 'wp_footer', 'gpante_support_search_index_notice' );
	if ( ! wp_next_scheduled( 'gpante_support_search_index_batch' ) ) {
		wp_schedule_event( time() + 3600, 'hourly', 'gpante_support_search_index_batch' );
	}
	$state = get_option( 'gpante_support_search_state', array() );
	if ( empty( $state['ready'] ) && wp_next_scheduled( 'gpante_support_search_index_batch' ) > time() + 60 ) {
		wp_schedule_single_event( time() + 5, 'gpante_support_search_index_batch' );
	}
}
