<?php
/**
 * Private settings and idempotent option upgrades.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Upgrade on activation and normal requests; activation is not an update hook. */
function gpante_support_upgrade() {
	$config = get_option( 'gpante_support_runtime', false );
	if ( false === $config ) {
		add_option(
			'gpante_support_runtime',
			array(
				'schema_version' => GPANTE_SUPPORT_SCHEMA_VERSION,
				'enabled'        => false,
				'backend_url'    => GPANTE_SUPPORT_BACKEND,
				'shared_key'     => '',
			),
			'',
			false
		);
		return;
	}
	if ( is_array( $config ) && (int) ( $config['schema_version'] ?? 0 ) < GPANTE_SUPPORT_SCHEMA_VERSION ) {
		// Preserve legacy values and future extension fields, including a disabled state.
		$config['schema_version'] = GPANTE_SUPPORT_SCHEMA_VERSION;
		update_option( 'gpante_support_runtime', $config, false );
	}
	wp_set_option_autoload( 'gpante_support_runtime', false );
}

/**
 * Validate a private key without altering its bytes.
 *
 * @param mixed $key Candidate key.
 * @return bool
 */
function gpante_support_valid_key( $key ) {
	return is_string( $key ) && 1 === preg_match( '/^[\x21-\x7e]{32,256}$/D', $key );
}

/**
 * Validate a Settings API submission. Blank key preserves the stored value.
 *
 * @param mixed $input Submitted option.
 * @return mixed Stored or validated option.
 */
function gpante_support_sanitize_settings( $input ) {
	$old = get_option( 'gpante_support_runtime', array() );
	if ( ! current_user_can( 'manage_options' ) ) {
		return $old;
	}
	if ( ! is_array( $input ) || ! is_array( $old ) || (int) ( $old['schema_version'] ?? 0 ) > GPANTE_SUPPORT_SCHEMA_VERSION ) {
		add_settings_error( 'gpante_support_runtime', 'invalid_config', __( 'تنظیمات ذخیره نشد. نسخهٔ افزونه و اطلاعات ورودی را بررسی کنید.', 'gpante-support-chat' ) );
		return $old;
	}
	$key = $input['shared_key'] ?? '';
	if ( '' !== $key && ! gpante_support_valid_key( $key ) ) {
		add_settings_error( 'gpante_support_runtime', 'invalid_key', __( 'کلید باید ۳۲ تا ۲۵۶ نویسهٔ ASCII بدون فاصله باشد.', 'gpante-support-chat' ) );
		return $old;
	}
	$next               = $old;
	$next['shared_key'] = '' === $key ? ( $old['shared_key'] ?? '' ) : $key;
	$next['enabled']    = isset( $input['enabled'] ) && in_array( $input['enabled'], array( '1', true ), true );
	if ( $next['enabled'] && ! gpante_support_valid_key( $next['shared_key'] ) ) {
		add_settings_error( 'gpante_support_runtime', 'missing_key', __( 'برای روشن‌کردن چت، کلید معتبر لازم است.', 'gpante-support-chat' ) );
		return $old;
	}
	$next['backend_url']    = GPANTE_SUPPORT_BACKEND;
	$next['schema_version'] = GPANTE_SUPPORT_SCHEMA_VERSION;
	return $next;
}

/** Register a private option; WordPress options.php checks capability and nonce. */
function gpante_support_register_settings() {
	register_setting(
		'gpante_support',
		'gpante_support_runtime',
		array(
			'type'              => 'array',
			'show_in_rest'      => false,
			'sanitize_callback' => 'gpante_support_sanitize_settings',
		)
	);
}
add_action( 'admin_init', 'gpante_support_register_settings' );

/** Add the native Settings page. */
function gpante_support_settings_menu() {
	add_options_page( __( 'چت جی‌پانته', 'gpante-support-chat' ), __( 'چت جی‌پانته', 'gpante-support-chat' ), 'manage_options', 'gpante-support-chat', 'gpante_support_settings_page' );
}
add_action( 'admin_menu', 'gpante_support_settings_menu' );

/** Render settings without ever putting the stored key in HTML. */
function gpante_support_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$config = get_option( 'gpante_support_runtime', array() );
	$config = is_array( $config ) ? $config : array();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'چت پشتیبان جی‌پانته', 'gpante-support-chat' ); ?></h1>
		<p><?php esc_html_e( 'پیش از روشن‌کردن چت، اتصال n8n و سقف هزینهٔ آن را آماده کنید. نمایش برای همهٔ بازدیدکنندگان است.', 'gpante-support-chat' ); ?></p>
		<?php settings_errors( 'gpante_support_runtime' ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'gpante_support' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'نمایش عمومی', 'gpante-support-chat' ); ?></th><td><label><input type="checkbox" name="gpante_support_runtime[enabled]" value="1" <?php checked( true, ( $config['enabled'] ?? false ) ); ?>> <?php esc_html_e( 'چت در همهٔ صفحات نمایش داده شود', 'gpante-support-chat' ); ?></label></td></tr>
				<tr><th scope="row"><label for="gpante-chat-key"><?php esc_html_e( 'کلید اتصال سرور', 'gpante-support-chat' ); ?></label></th><td>
					<input id="gpante-chat-key" type="password" name="gpante_support_runtime[shared_key]" value="" autocomplete="new-password" minlength="32" maxlength="256" class="regular-text" dir="ltr">
					<p class="description"><?php echo esc_html( gpante_support_valid_key( $config['shared_key'] ?? '' ) ? __( 'کلید ذخیره شده است؛ برای حفظ آن، این ورودی را خالی بگذارید.', 'gpante-support-chat' ) : __( 'کلید هنوز تنظیم نشده است. کلید را فقط از مسیر خصوصی دریافت کنید.', 'gpante-support-chat' ) ); ?></p>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
