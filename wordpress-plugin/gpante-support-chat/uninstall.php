<?php
/**
 * Delete this plugin's private setting only. n8n history is independent.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'gpante_support_runtime' );
delete_option( 'gpante_support_appearance' );
