<?php
/**
 * Plugin Name: GPante Support Chat
 * Plugin URI: https://github.com/vahid162/n8n-chat-gpante.com
 * Description: دستیار فارسی محصولات عمومی جی‌پانته با اتصال امن به n8n.
 * Version: 0.4.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Author: vahid162
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: gpante-support-chat
 * Update URI: https://github.com/vahid162/n8n-chat-gpante.com
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Also prevents duplicate loading through the old reference bridge path.
if ( defined( 'GPANTE_SUPPORT_VERSION' ) ) {
	return;
}
define( 'GPANTE_SUPPORT_VERSION', '0.4.0' );
define( 'GPANTE_SUPPORT_SCHEMA_VERSION', 2 );
define( 'GPANTE_SUPPORT_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-v1' );
define( 'GPANTE_SUPPORT_ADMIN_BACKEND', 'https://n8n.gpantex.com/webhook/gpante-ai-support-admin-v1' );
define( 'GPANTE_SUPPORT_COOKIE', '__Host-gpante_support' );
define( 'GPANTE_SUPPORT_DIR', plugin_dir_path( __FILE__ ) );
define( 'GPANTE_SUPPORT_URL', plugin_dir_url( __FILE__ ) );

require_once GPANTE_SUPPORT_DIR . 'includes/settings.php';
require_once GPANTE_SUPPORT_DIR . 'includes/appearance.php';
require_once GPANTE_SUPPORT_DIR . 'includes/bridge.php';
require_once GPANTE_SUPPORT_DIR . 'includes/admin.php';
register_activation_hook( __FILE__, 'gpante_support_upgrade' );
register_activation_hook( __FILE__, 'gpante_support_appearance_upgrade' );
add_action( 'init', 'gpante_support_upgrade' );
