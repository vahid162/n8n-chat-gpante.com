<?php
/**
 * Delete owned settings and public FAQ cache. n8n history is independent.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$gpante_support_uninstall_site = static function () {
	delete_option( 'gpante_support_runtime' );
	delete_option( 'gpante_support_appearance' );
	delete_transient( 'gpante_support_faq_cache' );
	delete_option( 'gpante_support_search_schema' );
	delete_option( 'gpante_support_search_state' );
	delete_option( 'gpante_support_search_lease' );
	delete_option( 'gpante_support_search_dirty' );
	wp_unschedule_hook( 'gpante_support_search_index_batch' );
	wp_unschedule_hook( 'gpante_support_search_index_update' );
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed plugin-owned table only, on explicit uninstall.
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}gpante_support_search" );
};

if ( ! is_multisite() ) {
	$gpante_support_uninstall_site();
	return;
}

$gpante_support_offset = 0;
do {
	$gpante_support_sites = get_sites(
		array(
			'fields'  => 'ids',
			'number'  => 100,
			'offset'  => $gpante_support_offset,
			'orderby' => 'id',
			'order'   => 'ASC',
		)
	);
	foreach ( $gpante_support_sites as $gpante_support_site_id ) {
		switch_to_blog( (int) $gpante_support_site_id );
		try {
			$gpante_support_uninstall_site();
		} finally {
			restore_current_blog();
		}
	}
	$gpante_support_site_count = count( $gpante_support_sites );
	$gpante_support_offset    += $gpante_support_site_count;
} while ( 100 === $gpante_support_site_count );
