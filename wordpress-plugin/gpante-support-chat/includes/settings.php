<?php
/**
 * Private settings and idempotent option upgrades.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upgrade on activation and normal requests; activation is not an update hook.
 *
 * @param bool|null $network_wide Supplied only by WordPress activation.
 */
function gpante_support_upgrade( $network_wide = null ) {
	$config = get_option( 'gpante_support_runtime', false );
	if ( false === $config ) {
		add_option(
			'gpante_support_runtime',
			array(
				'schema_version' => GPANTE_SUPPORT_SCHEMA_VERSION,
				'enabled'        => false,
				'backend_url'    => GPANTE_SUPPORT_BACKEND,
				'shared_key'     => '',
				'admin_key'      => '',
			),
			'',
			false
		);
		return;
	}
	if ( is_array( $config ) && (int) ( $config['schema_version'] ?? 0 ) < GPANTE_SUPPORT_SCHEMA_VERSION ) {
		// Preserve legacy values and future extension fields, including a disabled state.
		$config['schema_version'] = GPANTE_SUPPORT_SCHEMA_VERSION;
		$config['admin_key']      = $config['admin_key'] ?? '';
		update_option( 'gpante_support_runtime', $config, false );
	}
	if ( is_bool( $network_wide ) && is_array( $config ) && (int) ( $config['schema_version'] ?? 0 ) <= GPANTE_SUPPORT_SCHEMA_VERSION ) {
		wp_set_option_autoload( 'gpante_support_runtime', false );
	}
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
	$admin_key = $input['admin_key'] ?? '';
	if ( '' !== $admin_key && ! gpante_support_valid_key( $admin_key ) ) {
		add_settings_error( 'gpante_support_runtime', 'invalid_admin_key', __( 'کلید مدیریت معتبر نیست؛ تنظیمات ذخیره نشد.', 'gpante-support-chat' ) );
		return $old;
	}
	$next               = $old;
	$next['admin_key']  = '' === $admin_key ? ( $old['admin_key'] ?? '' ) : $admin_key;
	$next['shared_key'] = '' === $key ? ( $old['shared_key'] ?? '' ) : $key;
	$next['enabled']    = isset( $input['enabled'] ) && in_array( $input['enabled'], array( '1', true ), true );
	if ( $next['enabled'] && ! gpante_support_valid_key( $next['shared_key'] ) ) {
		add_settings_error( 'gpante_support_runtime', 'missing_key', __( 'برای روشن‌کردن چت، کلید معتبر لازم است.', 'gpante-support-chat' ) );
		return $old;
	}
	$next['backend_url']    = GPANTE_SUPPORT_BACKEND;
	$next['schema_version'] = GPANTE_SUPPORT_SCHEMA_VERSION;
	wp_set_option_autoload( 'gpante_support_runtime', false );
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
	<div class="wrap gpante-admin">
		<header class="gpante-admin-header">
			<h1><?php esc_html_e( 'اتصال چت پانته', 'gpante-support-chat' ); ?></h1>
			<p class="gpante-admin-description"><?php esc_html_e( 'نمایش عمومی و کلیدهای خصوصی اتصال را مدیریت کنید. پیش از روشن‌کردن چت، سرویس و سقف هزینهٔ آن باید آماده باشند.', 'gpante-support-chat' ); ?></p>
		</header>
		<?php settings_errors( 'gpante_support_runtime' ); ?>
		<section class="gpante-admin-card" aria-labelledby="gpante-connection-status-heading">
			<h2 id="gpante-connection-status-heading"><?php esc_html_e( 'وضعیت تنظیم کلیدها', 'gpante-support-chat' ); ?></h2>
			<dl class="gpante-admin-key-status">
				<div class="gpante-admin-key-status__item">
					<dt><?php esc_html_e( 'کلید اتصال سرور', 'gpante-support-chat' ); ?></dt>
					<dd>
					<?php
					if ( gpante_support_valid_key( $config['shared_key'] ?? '' ) ) :
						?>
						<span class="gpante-admin-badge is-configured"><?php esc_html_e( 'تنظیم شده', 'gpante-support-chat' ); ?></span>
						<?php
else :
	?>
						<span class="gpante-admin-badge is-unconfigured"><?php esc_html_e( 'تنظیم نشده', 'gpante-support-chat' ); ?></span><?php endif; ?></dd>
				</div>
				<div class="gpante-admin-key-status__item">
					<dt><?php esc_html_e( 'کلید خصوصی مدیریت گفتگو', 'gpante-support-chat' ); ?></dt>
					<dd>
					<?php
					if ( gpante_support_valid_key( $config['admin_key'] ?? '' ) ) :
						?>
						<span class="gpante-admin-badge is-configured"><?php esc_html_e( 'تنظیم شده', 'gpante-support-chat' ); ?></span>
						<?php
else :
	?>
						<span class="gpante-admin-badge is-unconfigured"><?php esc_html_e( 'تنظیم نشده', 'gpante-support-chat' ); ?></span><?php endif; ?></dd>
				</div>
			</dl>
			<p class="description"><?php esc_html_e( 'این وضعیت فقط وجود کلید معتبر ذخیره‌شده را نشان می‌دهد. پاسخ موفق سرویس، سلامت اتصال را مشخص می‌کند.', 'gpante-support-chat' ); ?></p>
		</section>
		<form method="post" action="options.php">
			<?php settings_fields( 'gpante_support' ); ?>
			<section class="gpante-admin-card">
				<h2><?php esc_html_e( 'نمایش چت', 'gpante-support-chat' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'نمایش عمومی', 'gpante-support-chat' ); ?></th><td><label><input type="checkbox" name="gpante_support_runtime[enabled]" value="1" <?php checked( true, ( $config['enabled'] ?? false ) ); ?>> <?php esc_html_e( 'چت در همهٔ صفحات نمایش داده شود', 'gpante-support-chat' ); ?></label></td></tr>
			</table>
			</section>
			<details class="gpante-admin-card gpante-admin-key-fields">
				<summary><?php esc_html_e( 'تنظیم یا جایگزینی کلیدهای خصوصی', 'gpante-support-chat' ); ?></summary>
				<p><?php esc_html_e( 'برای حفظ هر کلید ذخیره‌شده، ورودی همان کلید را خالی بگذارید. مقدارهای ذخیره‌شده نمایش داده نمی‌شوند؛ فقط هنگام جایگزینی، کلید تازه را از مسیر خصوصی وارد و ذخیره کنید.', 'gpante-support-chat' ); ?></p>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="gpante-chat-key"><?php esc_html_e( 'کلید اتصال سرور', 'gpante-support-chat' ); ?></label></th><td>
					<input id="gpante-chat-key" type="password" name="gpante_support_runtime[shared_key]" value="" autocomplete="new-password" minlength="32" maxlength="256" class="regular-text" dir="ltr">
					<p class="description"><?php echo esc_html( gpante_support_valid_key( $config['shared_key'] ?? '' ) ? __( 'کلید ذخیره شده است؛ برای حفظ آن، این ورودی را خالی بگذارید.', 'gpante-support-chat' ) : __( 'کلید هنوز تنظیم نشده است. کلید را فقط از مسیر خصوصی دریافت کنید.', 'gpante-support-chat' ) ); ?></p>
				</td></tr>
				<tr><th scope="row"><label for="gpante-admin-key"><?php esc_html_e( 'کلید خصوصی مدیریت گفتگو', 'gpante-support-chat' ); ?></label></th><td>
					<input id="gpante-admin-key" type="password" name="gpante_support_runtime[admin_key]" value="" autocomplete="new-password" minlength="32" maxlength="256" class="regular-text" dir="ltr">
					<p class="description"><?php esc_html_e( 'این کلید مستقل از اتصال عمومی است. خالی‌گذاشتن، کلید قبلی را حفظ می‌کند؛ مقدار ذخیره‌شده نمایش داده نمی‌شود.', 'gpante-support-chat' ); ?></p>
				</td></tr>
			</table>
			</details>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
