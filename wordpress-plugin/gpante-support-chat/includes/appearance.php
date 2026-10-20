<?php
/**
 * Public presentation settings, independent of private connection settings.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define allowed text fields.
 *
 * @return array Label, default and maximum character count per key.
 */
function gpante_support_appearance_texts() {
	return array(
		'title'       => array( __( 'عنوان دستیار', 'gpante-support-chat' ), __( 'دستیار پانته', 'gpante-support-chat' ), 80 ),
		'subtitle'    => array( __( 'زیرعنوان', 'gpante-support-chat' ), __( 'راهنمای طرح و فایل، خرید و دانلود', 'gpante-support-chat' ), 160 ),
		'launcher'    => array( __( 'متن دکمهٔ بازکردن چت', 'gpante-support-chat' ), __( 'از پانته بپرسید', 'gpante-support-chat' ), 60 ),
		'welcome'     => array( __( 'پیام خوشامدگویی', 'gpante-support-chat' ), __( 'سلام! دنبال چه طرح یا فایلی هستید؟ موضوع، متریال، ضخامت و فرمت دلخواهتان را بنویسید تا دقیق‌تر راهنمایی‌تان کنم.', 'gpante-support-chat' ), 1000 ),
		'input_label' => array( __( 'عنوان کادر پرسش', 'gpante-support-chat' ), __( 'پرسش شما', 'gpante-support-chat' ), 80 ),
		'placeholder' => array( __( 'راهنمای داخل کادر پرسش', 'gpante-support-chat' ), __( 'مثلاً باکس MDF با ضخامت ۲٫۷ و فایل DXF می‌خواهم…', 'gpante-support-chat' ), 250 ),
		'send'        => array( __( 'متن دکمهٔ ارسال', 'gpante-support-chat' ), __( 'ارسال پیام', 'gpante-support-chat' ), 40 ),
		'clear'       => array( __( 'متن دکمهٔ گفتگوی تازه', 'gpante-support-chat' ), __( 'پاک‌کردن و گفت‌وگوی تازه', 'gpante-support-chat' ), 100 ),
	);
}

/**
 * Define allowed colors.
 *
 * @return array Label and default six-digit RGB per key.
 */
function gpante_support_appearance_colors() {
	return array(
		'primary'    => array( __( 'پس‌زمینهٔ سربرگ و دکمه‌ها', 'gpante-support-chat' ), '#365e28' ),
		'on_primary' => array( __( 'متن سربرگ و دکمه‌ها', 'gpante-support-chat' ), '#ffffff' ),
		'surface'    => array( __( 'پس‌زمینهٔ پنجره و کادر پرسش', 'gpante-support-chat' ), '#ffffff' ),
		'text'       => array( __( 'رنگ متن‌ها', 'gpante-support-chat' ), '#263023' ),
		'messages'   => array( __( 'پس‌زمینهٔ بخش پیام‌ها', 'gpante-support-chat' ), '#f7f9f4' ),
		'user'       => array( __( 'پس‌زمینهٔ پیام کاربر', 'gpante-support-chat' ), '#e9f0e2' ),
		'assistant'  => array( __( 'پس‌زمینهٔ پیام دستیار', 'gpante-support-chat' ), '#ffffff' ),
		'accent'     => array( __( 'نوار رنگی بالای پنجره', 'gpante-support-chat' ), '#fbbc34' ),
	);
}

/**
 * Build safe defaults with an independent presentation schema.
 *
 * @return array
 */
function gpante_support_appearance_defaults() {
	$config = array(
		'schema_version' => 1,
		'texts'          => array(),
		'colors'         => array(),
	);
	foreach ( gpante_support_appearance_texts() as $key => $field ) {
		$config['texts'][ $key ] = $field[1];
	}
	foreach ( gpante_support_appearance_colors() as $key => $field ) {
		$config['colors'][ $key ] = $field[1];
	}
	return $config;
}

/** Create the owned option on updates as well as activation; never downgrade it. */
function gpante_support_appearance_upgrade() {
	if ( false === get_option( 'gpante_support_appearance', false ) ) {
		add_option( 'gpante_support_appearance', gpante_support_appearance_defaults(), '', false );
	}
	wp_set_option_autoload( 'gpante_support_appearance', false );
}
add_action( 'init', 'gpante_support_appearance_upgrade' );

/**
 * Compute the WCAG relative-luminance contrast ratio of validated RGB colors.
 *
 * @param string $first First six-digit RGB.
 * @param string $second Second six-digit RGB.
 * @return float
 */
function gpante_support_color_contrast( $first, $second ) {
	$luminance = array();
	foreach ( array( $first, $second ) as $color ) {
		$channels = array();
		foreach ( array( 1, 3, 5 ) as $offset ) {
			$value      = hexdec( substr( $color, $offset, 2 ) ) / 255;
			$channels[] = $value <= 0.04045 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
		}
		$luminance[] = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}
	return ( max( $luminance ) + 0.05 ) / ( min( $luminance ) + 0.05 );
}

/**
 * Validate complete presentation values. No HTML, CSS or secret fields are accepted.
 *
 * @param mixed $config Candidate settings.
 * @return bool
 */
function gpante_support_valid_appearance( $config ) {
	if ( ! is_array( $config ) || 1 !== ( $config['schema_version'] ?? null ) || ! is_array( $config['texts'] ?? null ) || ! is_array( $config['colors'] ?? null ) ) {
		return false;
	}
	foreach ( gpante_support_appearance_texts() as $key => $field ) {
		$value = $config['texts'][ $key ] ?? null;
		if ( ! is_string( $value ) || '' === trim( $value ) || false === preg_match_all( '/./us', $value, $matches ) || count( $matches[0] ) > $field[2] || ( 'welcome' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value ) ) !== $value ) {
			return false;
		}
	}
	foreach ( gpante_support_appearance_colors() as $key => $field ) {
		if ( ! is_string( $config['colors'][ $key ] ?? null ) || 1 !== preg_match( '/^#[0-9a-f]{6}$/iD', $config['colors'][ $key ] ) ) {
			return false;
		}
	}
	$colors = $config['colors'];
	if ( gpante_support_color_contrast( $colors['primary'], $colors['on_primary'] ) < 4.5 ) {
		return false;
	}
	foreach ( array( 'surface', 'messages', 'user', 'assistant' ) as $background ) {
		if ( gpante_support_color_contrast( $colors['text'], $colors[ $background ] ) < 4.5 ) {
			return false;
		}
	}
	return true;
}

/**
 * Read only known safe fields, falling back when encountering a future schema.
 *
 * @return array
 */
function gpante_support_get_appearance() {
	$defaults = gpante_support_appearance_defaults();
	$stored   = get_option( 'gpante_support_appearance', $defaults );
	if ( ! gpante_support_valid_appearance( $stored ) ) {
		return $defaults;
	}
	foreach ( array( 'texts', 'colors' ) as $group ) {
		foreach ( $defaults[ $group ] as $key => $value ) {
			$defaults[ $group ][ $key ] = $stored[ $group ][ $key ];
		}
	}
	return $defaults;
}

/**
 * Native Settings API sanitizer; repeated calls have the same result.
 *
 * @param mixed $input Submitted option.
 * @return mixed Existing or sanitized settings.
 */
function gpante_support_sanitize_appearance( $input ) {
	$old = get_option( 'gpante_support_appearance', gpante_support_appearance_defaults() );
	if ( ! current_user_can( 'manage_options' ) ) {
		return $old;
	}
	if ( ! is_array( $input ) || ! is_array( $old ) || (int) ( $old['schema_version'] ?? 0 ) > 1 ) {
		add_settings_error( 'gpante_support_appearance', 'invalid_version', __( 'ظاهر ذخیره نشد. نسخهٔ افزونه و دادهٔ ورودی را بررسی کنید.', 'gpante-support-chat' ) );
		return $old;
	}
	if ( '1' === ( $input['restore_defaults'] ?? null ) ) {
		return gpante_support_appearance_defaults();
	}
	$next = gpante_support_appearance_defaults();
	foreach ( gpante_support_appearance_texts() as $key => $field ) {
		$value = $input['texts'][ $key ] ?? null;
		if ( ! is_string( $value ) ) {
			$next['texts'][ $key ] = '';
			continue;
		}
		$next['texts'][ $key ] = 'welcome' === $key ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
	}
	foreach ( gpante_support_appearance_colors() as $key => $field ) {
		$value                  = $input['colors'][ $key ] ?? null;
		$next['colors'][ $key ] = is_string( $value ) ? strtolower( $value ) : '';
	}
	if ( ! gpante_support_valid_appearance( $next ) ) {
		add_settings_error( 'gpante_support_appearance', 'invalid_appearance', __( 'ظاهر ذخیره نشد. متن‌ها را خالی نگذارید و از سقف طول عبور نکنید. رنگ‌ها باید شش‌رقمی باشند؛ نسبت خوانایی متن روی پس‌زمینه‌های انتخابی باید حداقل ۴٫۵ باشد.', 'gpante-support-chat' ) );
		return $old;
	}
	return $next;
}

/** Register independent settings; options.php enforces capability and nonce. */
function gpante_support_register_appearance() {
	register_setting(
		'gpante_support_appearance',
		'gpante_support_appearance',
		array(
			'type'              => 'array',
			'show_in_rest'      => false,
			'sanitize_callback' => 'gpante_support_sanitize_appearance',
		)
	);
}
add_action( 'admin_init', 'gpante_support_register_appearance' );

/** Add an immediately visible native submenu. */
function gpante_support_appearance_menu() {
	add_submenu_page( 'gpante-support-conversations', __( 'ظاهر چت پانته', 'gpante-support-chat' ), __( 'ظاهر', 'gpante-support-chat' ), 'manage_options', 'gpante-support-appearance', 'gpante_support_appearance_page' );
}
add_action( 'admin_menu', 'gpante_support_appearance_menu', 20 );

/** Render plain-text and native color inputs; never render private runtime values. */
function gpante_support_appearance_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$config = gpante_support_get_appearance();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ظاهر چت پانته', 'gpante-support-chat' ); ?></h1>
		<p><?php esc_html_e( 'متن‌ها و رنگ‌های چت عمومی را تغییر دهید. تغییرات پس از ذخیره و بازخوانی صفحهٔ سایت نمایش داده می‌شوند. این بخش تنظیمات اتصال و تاریخچه را تغییر نمی‌دهد.', 'gpante-support-chat' ); ?></p>
		<?php settings_errors( 'gpante_support_appearance' ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'gpante_support_appearance' ); ?>
			<h2><?php esc_html_e( 'متن‌ها', 'gpante-support-chat' ); ?></h2>
			<p><?php esc_html_e( 'فقط متن ساده وارد کنید. خوشامدگویی در گفتگوی خالی نمایش داده می‌شود؛ دستور مدل را تغییر نمی‌دهد. متن اطلاع‌رسانی آرشیو و تأیید پاک‌کردن از سیاست واقعی می‌آید.', 'gpante-support-chat' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( gpante_support_appearance_texts() as $key => $field ) : ?>
				<tr><th scope="row"><label for="gpante-appearance-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th><td>
					<?php if ( 'welcome' === $key ) : ?>
						<textarea class="large-text" rows="3" required id="gpante-appearance-<?php echo esc_attr( $key ); ?>" name="gpante_support_appearance[texts][<?php echo esc_attr( $key ); ?>]" maxlength="<?php echo esc_attr( $field[2] ); ?>"><?php echo esc_textarea( $config['texts'][ $key ] ); ?></textarea>
					<?php else : ?>
						<input class="regular-text" type="text" required id="gpante-appearance-<?php echo esc_attr( $key ); ?>" name="gpante_support_appearance[texts][<?php echo esc_attr( $key ); ?>]" maxlength="<?php echo esc_attr( $field[2] ); ?>" value="<?php echo esc_attr( $config['texts'][ $key ] ); ?>">
					<?php endif; ?>
				</td></tr>
				<?php endforeach; ?>
			</table>
			<h2><?php esc_html_e( 'رنگ‌ها', 'gpante-support-chat' ); ?></h2>
			<p><?php esc_html_e( 'برای حفظ خوانایی، رنگ متن و پس‌زمینه را با اختلاف کافی انتخاب کنید؛ ترکیب ناخوانا ذخیره نمی‌شود.', 'gpante-support-chat' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( gpante_support_appearance_colors() as $key => $field ) : ?>
				<tr><th scope="row"><label for="gpante-color-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th><td><input type="color" id="gpante-color-<?php echo esc_attr( $key ); ?>" name="gpante_support_appearance[colors][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $config['colors'][ $key ] ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
			<p><button class="button" type="submit" name="gpante_support_appearance[restore_defaults]" value="1" formnovalidate><?php esc_html_e( 'بازگرداندن متن‌ها و رنگ‌های پیش‌فرض', 'gpante-support-chat' ); ?></button></p>
		</form>
	</div>
	<?php
}

/** Public no-store presentation only; no authentication, history or model request needed. */
function gpante_support_appearance_routes() {
	register_rest_route(
		'gpante-support/v1',
		'/appearance',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$config = gpante_support_get_appearance();
				return gpante_support_response(
					array(
						'version' => 1,
						'texts'   => $config['texts'],
						'colors'  => $config['colors'],
					)
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'gpante_support_appearance_routes' );
