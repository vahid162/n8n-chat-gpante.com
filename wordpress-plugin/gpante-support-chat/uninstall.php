<?php
/**
 * Delete owned settings and public FAQ cache. n8n history is independent.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

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
