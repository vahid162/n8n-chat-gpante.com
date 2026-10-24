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
		'input_label' => array( __( 'نام کادر پرسش برای صفحه‌خوان (نمایش داده نمی‌شود)', 'gpante-support-chat' ), __( 'پرسش شما', 'gpante-support-chat' ), 80 ),
		'placeholder' => array( __( 'راهنمای داخل کادر پرسش', 'gpante-support-chat' ), __( 'مثلاً باکس MDF با ضخامت ۲٫۷ و فایل DXF می‌خواهم…', 'gpante-support-chat' ), 250 ),
		'send'        => array( __( 'نام و راهنمای دکمهٔ ارسال', 'gpante-support-chat' ), __( 'ارسال پیام', 'gpante-support-chat' ), 40 ),
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
 * Read a bounded RGB value from public theme settings.
 *
 * @param mixed $value Theme color.
 * @return string Empty when the setting is not a solid RGB color.
 */
function gpante_support_theme_color( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/iD', $value, $match ) ) {
		return '';
	}
	$hex = strtolower( $match[1] );
	return '#' . ( 3 === strlen( $hex ) ? $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2] : $hex );
}

/**
 * Resolve the active site's palette without copying it into saved overrides.
 *
 * @return array Eight readable presentation colors.
 */
function gpante_support_site_colors() {
	$colors = array_column( gpante_support_appearance_colors(), 1 );
	$colors = array_combine( array_keys( gpante_support_appearance_colors() ), $colors );
	$source = array();
	if ( function_exists( 'wp_get_global_settings' ) ) {
		$palettes = (array) wp_get_global_settings( array( 'color', 'palette' ) );
		$roles    = array(
			'primary'    => 'primary',
			'secondary'  => 'accent',
			'accent'     => 'accent',
			'text'       => 'text',
			'foreground' => 'text',
			'contrast'   => 'text',
			'background' => 'surface',
			'base'       => 'surface',
		);
		foreach ( array( 'theme', 'custom' ) as $origin ) {
			foreach ( is_array( $palettes[ $origin ] ?? null ) ? $palettes[ $origin ] : array() as $entry ) {
				if ( is_array( $entry ) && is_string( $entry['slug'] ?? null ) && isset( $roles[ $entry['slug'] ] ) ) {
					$color = gpante_support_theme_color( $entry['color'] ?? null );
					if ( '' !== $color ) {
						$source[ $roles[ $entry['slug'] ] ] = $color;
					}
				}
			}
		}
	}
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		$kit = (array) get_post_meta( (int) get_option( 'elementor_active_kit', 0 ), '_elementor_page_settings', true );
		foreach ( is_array( $kit['system_colors'] ?? null ) ? $kit['system_colors'] : array() as $entry ) {
			$roles = array(
				'primary'   => 'primary',
				'secondary' => 'accent',
				'text'      => 'text',
			);
			if ( is_array( $entry ) && is_string( $entry['_id'] ?? null ) && isset( $roles[ $entry['_id'] ] ) ) {
				$color = gpante_support_theme_color( $entry['color'] ?? null );
				if ( '' !== $color ) {
					$source[ $roles[ $entry['_id'] ] ] = $color;
				}
			}
		}
	}
	if ( function_exists( 'woodmart_get_opt' ) ) {
		$primary    = (array) woodmart_get_opt( 'primary-color' );
		$secondary  = (array) woodmart_get_opt( 'secondary-color' );
		$text       = (array) woodmart_get_opt( 'text-font' );
		$background = (array) woodmart_get_opt( 'body-background' );
		$pages      = (array) woodmart_get_opt( 'pages-background' );
		$text       = (array) ( $text[0] ?? $text );
		$theme      = array(
			'primary'  => $primary['idle'] ?? null,
			'accent'   => $secondary['idle'] ?? null,
			'text'     => $text['color'] ?? null,
			'surface'  => $background['color'] ?? null,
			'messages' => $pages['color'] ?? null,
		);
		foreach ( $theme as $role => $value ) {
			$color = gpante_support_theme_color( $value );
			if ( '' !== $color ) {
				$source[ $role ] = $color;
			}
		}
	}
	if ( ! $source ) {
		return $colors;
	}
	$colors               = array_replace( $colors, $source );
	$colors['on_primary'] = gpante_support_color_contrast( $colors['primary'], '#ffffff' ) >= gpante_support_color_contrast( $colors['primary'], '#000000' ) ? '#ffffff' : '#000000';
	$colors['assistant']  = $colors['surface'];
	$colors['messages']   = $source['messages'] ?? $colors['surface'];
	if ( gpante_support_color_contrast( $colors['text'], $colors['surface'] ) < 4.5 ) {
		$colors['text'] = gpante_support_color_contrast( '#ffffff', $colors['surface'] ) >= gpante_support_color_contrast( '#000000', $colors['surface'] ) ? '#ffffff' : '#000000';
	}
	$colors['user'] = '#';
	foreach ( array( 1, 3, 5 ) as $offset ) {
		$channel         = (int) round( 0.1 * hexdec( substr( $colors['primary'], $offset, 2 ) ) + 0.9 * hexdec( substr( $colors['surface'], $offset, 2 ) ) );
		$colors['user'] .= str_pad( dechex( $channel ), 2, '0', STR_PAD_LEFT );
	}
	foreach ( array( 'messages', 'user' ) as $background ) {
		if ( gpante_support_color_contrast( $colors['text'], $colors[ $background ] ) < 4.5 ) {
			$colors[ $background ] = $colors['surface'];
		}
	}
	return $colors;
}

/**
 * Build safe defaults with an independent presentation schema.
 *
 * @return array
 */
function gpante_support_appearance_defaults() {
	$config = array(
		'schema_version' => 3,
		'color_mode'     => 'site',
		'texts'          => array(),
		'colors'         => array(),
		'links'          => array_fill(
			0,
			4,
			array(
				'label' => '',
				'url'   => '',
			)
		),
	);
	foreach ( gpante_support_appearance_texts() as $key => $field ) {
		$config['texts'][ $key ] = $field[1];
	}
	foreach ( gpante_support_appearance_colors() as $key => $field ) {
		$config['colors'][ $key ] = $field[1];
	}
	return $config;
}

/**
 * Create the owned option on updates as well as activation; never downgrade it.
 *
 * @param bool|null $network_wide Supplied only by WordPress activation.
 */
function gpante_support_appearance_upgrade( $network_wide = null ) {
	$config = get_option( 'gpante_support_appearance', false );
	if ( false === $config ) {
		add_option( 'gpante_support_appearance', gpante_support_appearance_defaults(), '', false );
	}
	if ( is_array( $config ) && in_array( $config['schema_version'] ?? null, array( 1, 2 ), true ) ) {
		$next                   = $config;
		$next['schema_version'] = 3;
		$next['color_mode']     = ( $next['colors'] ?? null ) === gpante_support_appearance_defaults()['colors'] ? 'site' : 'custom';
		if ( 1 === $config['schema_version'] ) {
			$next['links'] = gpante_support_appearance_defaults()['links'];
		}
		if ( gpante_support_valid_appearance( $next ) ) {
			update_option( 'gpante_support_appearance', $next, false );
		}
	}
	if ( is_bool( $network_wide ) && is_array( $config ) && (int) ( $config['schema_version'] ?? 0 ) <= 3 ) {
		wp_set_option_autoload( 'gpante_support_appearance', false );
	}
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
 * Validate administrator navigation URLs without fetching their destination.
 *
 * @param mixed $url Candidate HTTPS or site-relative URL.
 * @return bool
 */
function gpante_support_valid_shortcut_url( $url ) {
	if ( ! is_string( $url ) || strlen( $url ) > 2048 || '' === $url || str_contains( $url, '\\' ) || preg_match( '/[\x00-\x20\x7f]|%(?:0[0-9a-f]|1[0-9a-f]|7f|5c)/i', $url ) || esc_url_raw( $url, array( 'https' ) ) !== $url ) {
		return false;
	}
	if ( str_starts_with( $url, '/' ) && ! str_starts_with( $url, '//' ) ) {
		return true;
	}
	$parts = wp_parse_url( $url );
	return str_starts_with( $url, 'https://' ) && is_array( $parts ) && ! empty( $parts['host'] ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] );
}

/**
 * Validate complete presentation values. No HTML, CSS or secret fields are accepted.
 *
 * @param mixed $config Candidate settings.
 * @return bool
 */
function gpante_support_valid_appearance( $config ) {
	if ( ! is_array( $config ) || 3 !== ( $config['schema_version'] ?? null ) || ! in_array( $config['color_mode'] ?? null, array( 'site', 'custom' ), true ) || ! is_array( $config['texts'] ?? null ) || ! is_array( $config['colors'] ?? null ) ) {
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
	if ( ! is_array( $config['links'] ?? null ) || array_keys( $config['links'] ) !== array( 0, 1, 2, 3 ) ) {
		return false;
	}
	foreach ( $config['links'] as $link ) {
		if ( ! is_array( $link ) ) {
			return false;
		}
		$label = $link['label'] ?? null;
		$url   = $link['url'] ?? null;
		if ( ! is_string( $label ) || ! is_string( $url ) || sanitize_text_field( $label ) !== $label || false === preg_match_all( '/./us', $label, $matches ) || count( $matches[0] ) > 80 || ( '' !== $url && ! gpante_support_valid_shortcut_url( $url ) ) || ( '' !== $label && '' === $url ) ) {
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
		$defaults['colors'] = gpante_support_site_colors();
		return $defaults;
	}
	foreach ( array( 'texts', 'colors' ) as $group ) {
		foreach ( $defaults[ $group ] as $key => $value ) {
			$defaults[ $group ][ $key ] = $stored[ $group ][ $key ];
		}
	}
	foreach ( $stored['links'] as $index => $link ) {
		$defaults['links'][ $index ] = array(
			'label' => $link['label'],
			'url'   => $link['url'],
		);
	}
	$defaults['color_mode'] = $stored['color_mode'];
	if ( 'site' === $stored['color_mode'] ) {
		$defaults['colors'] = gpante_support_site_colors();
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
	if ( ! is_array( $input ) || ! is_array( $old ) || (int) ( $old['schema_version'] ?? 0 ) > 3 ) {
		add_settings_error( 'gpante_support_appearance', 'invalid_version', __( 'ظاهر ذخیره نشد. نسخهٔ افزونه و دادهٔ ورودی را بررسی کنید.', 'gpante-support-chat' ) );
		return $old;
	}
	if ( '1' === ( $input['restore_defaults'] ?? null ) ) {
		wp_set_option_autoload( 'gpante_support_appearance', false );
		return gpante_support_appearance_defaults();
	}
	$next               = gpante_support_appearance_defaults();
	$next['color_mode'] = $input['color_mode'] ?? 'custom';
	foreach ( gpante_support_appearance_texts() as $key => $field ) {
		$value = $input['texts'][ $key ] ?? ( 'send' === $key ? ( $old['texts']['send'] ?? $field[1] ) : null );
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
	if ( 'site' === $next['color_mode'] ) {
		$next['colors'] = gpante_support_valid_appearance( $old ) ? $old['colors'] : gpante_support_appearance_defaults()['colors'];
	}
	$links = $input['links'] ?? ( $old['links'] ?? $next['links'] );
	if ( ! is_array( $links ) || array_keys( $links ) !== array( 0, 1, 2, 3 ) ) {
		$next['links'] = array();
	} else {
		foreach ( $links as $index => $link ) {
			if ( ! is_array( $link ) ) {
				$next['links'] = array();
				break;
			}
			$label                   = $link['label'] ?? null;
			$url                     = $link['url'] ?? null;
			$next['links'][ $index ] = array(
				'label' => is_string( $label ) ? sanitize_text_field( $label ) : null,
				'url'   => is_string( $url ) ? trim( $url ) : null,
			);
		}
	}
	if ( ! gpante_support_valid_appearance( $next ) ) {
		add_settings_error( 'gpante_support_appearance', 'invalid_appearance', __( 'ظاهر ذخیره نشد. متن‌ها را خالی نگذارید و از سقف طول عبور نکنید. برای هر دکمهٔ دارای متن، لینک HTTPS یا مسیر داخلی معتبر وارد کنید (حداکثر چهار دکمه). رنگ‌ها باید شش‌رقمی باشند؛ نسبت خوانایی متن روی پس‌زمینه‌های انتخابی باید حداقل ۴٫۵ باشد.', 'gpante-support-chat' ) );
		return $old;
	}
	wp_set_option_autoload( 'gpante_support_appearance', false );
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

/**
 * Keep native color controls and their source selector in sync.
 *
 * @param string $hook Current administration page.
 */
function gpante_support_appearance_assets( $hook ) {
	if ( ! str_ends_with( $hook, '_page_gpante-support-appearance' ) ) {
		return;
	}
	wp_enqueue_script( 'common' );
	wp_add_inline_script(
		'common',
		'document.addEventListener("DOMContentLoaded",function(){const mode=document.getElementById("gpante-color-mode"),table=document.getElementById("gpante-appearance-colors");if(!mode||!table)return;const colors=JSON.parse(table.dataset.siteColors);table.querySelectorAll("input[type=color]").forEach(input=>input.addEventListener("input",()=>{mode.value="custom";}));mode.addEventListener("change",()=>{if(mode.value==="site")for(const [key,value] of Object.entries(colors)){const input=document.getElementById("gpante-color-"+key);if(input)input.value=value;}});});'
	);
}
add_action( 'admin_enqueue_scripts', 'gpante_support_appearance_assets' );

/** Render plain-text and native color inputs; never render private runtime values. */
function gpante_support_appearance_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$config = gpante_support_get_appearance();
	$fields = gpante_support_appearance_texts();
	unset( $fields['send'] );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'ظاهر چت پانته', 'gpante-support-chat' ); ?></h1>
		<p><?php esc_html_e( 'متن‌ها و رنگ‌های چت عمومی را تغییر دهید. تغییرات پس از ذخیره و بازخوانی صفحهٔ سایت نمایش داده می‌شوند. این بخش تنظیمات اتصال و تاریخچه را تغییر نمی‌دهد.', 'gpante-support-chat' ); ?></p>
		<?php settings_errors( 'gpante_support_appearance' ); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'gpante_support_appearance' ); ?>
			<h2><?php esc_html_e( 'متن‌ها', 'gpante-support-chat' ); ?></h2>
			<p><?php esc_html_e( 'فقط متن ساده وارد کنید. خوشامدگویی در گفتگوی خالی نمایش داده می‌شود؛ دستور مدل را تغییر نمی‌دهد. نام کادر پرسش فقط برای صفحه‌خوان است. مدت تاریخچه و امکان پاک‌کردن در بخش گفتگوها تنظیم می‌شوند.', 'gpante-support-chat' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $fields as $key => $field ) : ?>
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
			<p><label for="gpante-color-mode"><?php esc_html_e( 'منبع رنگ‌ها', 'gpante-support-chat' ); ?></label>
				<select id="gpante-color-mode" name="gpante_support_appearance[color_mode]">
					<option value="site" <?php selected( $config['color_mode'], 'site' ); ?>><?php esc_html_e( 'رنگ‌های سایت (پیش‌فرض)', 'gpante-support-chat' ); ?></option>
					<option value="custom" <?php selected( $config['color_mode'], 'custom' ); ?>><?php esc_html_e( 'رنگ‌های دستی', 'gpante-support-chat' ); ?></option>
				</select>
			</p>
			<p><?php esc_html_e( 'حالت پیش‌فرض از پالت فعال سایت پیروی می‌کند. با تغییر هر رنگ، حالت دستی انتخاب می‌شود؛ برای اتصال دوباره به پالت سایت، حالت پیش‌فرض را انتخاب و ذخیره کنید. ترکیب ناخوانا ذخیره نمی‌شود.', 'gpante-support-chat' ); ?></p>
			<table id="gpante-appearance-colors" class="form-table" role="presentation" data-site-colors="<?php echo esc_attr( wp_json_encode( gpante_support_site_colors() ) ); ?>">
				<?php foreach ( gpante_support_appearance_colors() as $key => $field ) : ?>
				<tr><th scope="row"><label for="gpante-color-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th><td><input type="color" id="gpante-color-<?php echo esc_attr( $key ); ?>" name="gpante_support_appearance[colors][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $config['colors'][ $key ] ); ?>"></td></tr>
				<?php endforeach; ?>
			</table>
			<h2><?php esc_html_e( 'دکمه‌های راهنما (حداکثر چهار مورد)', 'gpante-support-chat' ); ?></h2>
			<p><?php esc_html_e( 'با خالی‌گذاشتن متن، آن دکمه نمایش داده نمی‌شود. برای متنِ واردشده، نشانی HTTPS کامل یا مسیر داخلی مانند /shop/ لازم است. این دکمه‌ها صفحهٔ مقصد را باز می‌کنند.', 'gpante-support-chat' ); ?></p>
			<table class="form-table" role="presentation">
				<?php foreach ( $config['links'] as $index => $link ) : ?>
				<tr><th scope="row">
					<?php
					/* translators: %d: Shortcut number, from one to four. */
					echo esc_html( sprintf( __( 'دکمهٔ %d', 'gpante-support-chat' ), $index + 1 ) );
					?>
					</th><td>
					<p><label for="gpante-link-label-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'متن دکمه', 'gpante-support-chat' ); ?></label><br><input class="regular-text" type="text" id="gpante-link-label-<?php echo esc_attr( $index ); ?>" name="gpante_support_appearance[links][<?php echo esc_attr( $index ); ?>][label]" maxlength="80" value="<?php echo esc_attr( $link['label'] ); ?>"></p>
					<p><label for="gpante-link-url-<?php echo esc_attr( $index ); ?>"><?php esc_html_e( 'لینک دکمه', 'gpante-support-chat' ); ?></label><br><input class="regular-text" type="text" dir="ltr" id="gpante-link-url-<?php echo esc_attr( $index ); ?>" name="gpante_support_appearance[links][<?php echo esc_attr( $index ); ?>][url]" maxlength="2048" value="<?php echo esc_attr( $link['url'] ); ?>"></p>
				</td></tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
			<p><button class="button" type="submit" name="gpante_support_appearance[restore_defaults]" value="1" formnovalidate><?php esc_html_e( 'بازگرداندن ظاهر پیش‌فرض (متن، رنگ و دکمه‌ها)', 'gpante-support-chat' ); ?></button></p>
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
						'links'   => $config['links'],
					)
				);
			},
		)
	);
}
add_action( 'rest_api_init', 'gpante_support_appearance_routes' );
