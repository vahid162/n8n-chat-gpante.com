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
