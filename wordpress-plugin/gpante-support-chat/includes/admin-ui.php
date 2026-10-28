<?php
/**
 * Presentation helpers for privileged administration; stored values stay unchanged.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Name an unavailable value without inventing zero.
 *
 * @return string
 */
function gpante_support_admin_unknown() {
	return __( 'نامشخص', 'gpante-support-chat' );
}

/**
 * Format exact integer nanodollars without rounding small charges to zero.
 *
 * @param mixed $value Nanodollars.
 * @return string
 */
function gpante_support_admin_money( $value ) {
	if ( ! is_int( $value ) || $value < 0 ) {
		return gpante_support_admin_unknown();
	}
	$fraction = rtrim( str_pad( (string) ( $value % 1000000000 ), 9, '0', STR_PAD_LEFT ), '0' );
	$decimal  = $GLOBALS['wp_locale']->number_format['decimal_point'] ?? '.';
	return '$' . number_format_i18n( intdiv( $value, 1000000000 ) ) . $decimal . ( '' === $fraction ? '00' : $fraction );
}

/**
 * Format a known nonnegative counter.
 *
 * @param mixed $value Counter.
 * @return string
 */
function gpante_support_admin_number( $value ) {
	return is_int( $value ) && $value >= 0 ? number_format_i18n( $value ) : gpante_support_admin_unknown();
}

/**
 * Present milliseconds as seconds without losing subsecond durations.
 *
 * @param mixed $value Milliseconds.
 * @return string
 */
function gpante_support_admin_duration( $value ) {
	if ( ! is_int( $value ) || $value < 0 ) {
		return gpante_support_admin_unknown();
	}
	$precision = strlen( rtrim( str_pad( (string) ( $value % 1000 ), 3, '0', STR_PAD_LEFT ), '0' ) );
	$seconds   = number_format_i18n( $value / 1000, $precision );
	return $seconds . ' ' . __( 'ثانیه', 'gpante-support-chat' );
}

/**
 * Keep readable display and the existing date filters explicitly in UTC.
 *
 * @param mixed $value ISO timestamp.
 * @return string
 */
function gpante_support_admin_time( $value ) {
	if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value ) ) {
		return gpante_support_admin_unknown();
	}
	try {
		$date   = new DateTimeImmutable( $value );
		$errors = DateTimeImmutable::getLastErrors();
		if ( $errors && ( $errors['warning_count'] || $errors['error_count'] ) ) {
			return gpante_support_admin_unknown();
		}
		return wp_date( 'Y/m/d H:i:s', $date->getTimestamp(), new DateTimeZone( 'UTC' ) ) . ' UTC';
	} catch ( Exception $error ) {
		return gpante_support_admin_unknown();
	}
}

/**
 * Translate fixed states while leaving original codes in technical details.
 *
 * @param mixed  $value Fixed code.
 * @param string $kind Code family.
 * @return string
 */
function gpante_support_admin_label( $value, $kind = 'status' ) {
	$labels = array(
		'status'   => array(
			'success'            => 'موفق',
			'error'              => 'خطا',
			'interrupted'        => 'قطع‌شده',
			'pending'            => 'در حال پردازش',
			'legacy'             => 'قدیمی',
			'ok'                 => 'موفق',
			'no_matches'         => 'نتیجه‌ای پیدا نشد',
			'incomplete_source'  => 'پوشش ناقص',
			'source_unavailable' => 'منبع در دسترس نیست',
		),
		'traffic'  => array(
			'synthetic'    => 'آزمون تأییدشده',
			'unclassified' => 'طبقه‌بندی‌نشده',
		),
		'feedback' => array(
			'helpful'   => 'مفید',
			'unhelpful' => 'نامفید',
		),
		'source'   => array(
			'products'   => 'محصولات',
			'articles'   => 'مقاله‌ها',
			'site'       => 'جستجوی سایت',
			'faq'        => 'پرسش‌های متداول',
			'guides'     => 'راهنماها',
			'categories' => 'دسته‌ها',
			'unknown'    => 'نامشخص',
		),
		'coverage' => array(
			'complete'   => 'کامل',
			'incomplete' => 'ناقص',
			'unknown'    => 'نامشخص',
		),
	);
	return is_string( $value ) && isset( $labels[ $kind ][ $value ] ) ? $labels[ $kind ][ $value ] : gpante_support_admin_unknown();
}

/**
 * Render a badge with a fixed class and escaped translated text.
 *
 * @param mixed  $value Fixed code.
 * @param string $kind Code family.
 * @return string
 */
function gpante_support_admin_badge( $value, $kind = 'status' ) {
	$state = in_array( $value, array( 'success', 'ok', 'helpful' ), true ) ? 'is-success' : ( in_array( $value, array( 'error', 'interrupted', 'source_unavailable', 'unhelpful' ), true ) ? 'is-error' : 'is-neutral' );
	return '<span class="gpante-admin-badge ' . $state . '">' . esc_html( gpante_support_admin_label( $value, $kind ) ) . '</span>';
}

/**
 * Give an actionable explanation without guessing an unavailable root cause.
 *
 * @param mixed $code Fixed error code.
 * @return string
 */
function gpante_support_admin_error_hint( $code ) {
	$hints = array(
		'monthly_budget'     => 'بودجهٔ ماه جاری به حد تعیین‌شده رسیده است؛ مصرف بودجه را بررسی کنید.',
		'pricing_review'     => 'اعتبار تعرفه نیاز به بررسی دارد؛ تنظیم تعرفهٔ سرویس را بررسی کنید.',
		'rate_limit'         => 'محدودیت موقت درخواست ثبت شده است؛ ارسال پی‌درپی را تکرار نکنید.',
		'capacity'           => 'ظرفیت هم‌زمان سرویس پر بوده است؛ وضعیت درخواست‌های فعال را بررسی کنید.',
		'session_busy'       => 'در همان گفتگو یک درخواست دیگر در حال پردازش بوده است.',
		'source_required'    => 'پاسخ به منبع معتبر نیاز داشته است؛ مسیر منابع این نوبت را بررسی کنید.',
		'source_unavailable' => 'خواندن منبع تأیید نشده است؛ این وضعیت به معنی نبود نتیجه نیست.',
	);
	return is_string( $code ) && isset( $hints[ $code ] ) ? $hints[ $code ] : __( 'جزئیات فنی این نوبت و وضعیت اتصال را بررسی کنید؛ علت دقیق از این کد به‌تنهایی مشخص نیست.', 'gpante-support-chat' );
}

/**
 * Keep full anonymous references available without dominating the page.
 *
 * @param mixed $value Anonymous reference.
 * @return string
 */
function gpante_support_admin_reference( $value ) {
	static $index = 0;
	if ( ! is_string( $value ) || ! preg_match( '/^(?:[a-f0-9]{32}|[a-f0-9]{64})$/D', $value ) ) {
		return esc_html( gpante_support_admin_unknown() );
	}
	$id = 'gpante-reference-' . ( ++$index );
	return '<div class="gpante-admin-reference"><code>' . esc_html( substr( $value, 0, 12 ) . '…' ) . '</code> <button type="button" class="button-link" data-gpante-copy="' . esc_attr( $id ) . '">' . esc_html__( 'کپی شناسه', 'gpante-support-chat' ) . '</button><span data-gpante-copy-status role="status"></span><details><summary>' . esc_html__( 'شناسهٔ کامل', 'gpante-support-chat' ) . '</summary><code id="' . esc_attr( $id ) . '" tabindex="0">' . esc_html( $value ) . '</code></details></div>';
}

/**
 * Load small, scoped administration assets on this plugin's pages only.
 *
 * @param string $hook Current page hook.
 */
function gpante_support_admin_assets( $hook ) {
	if ( ! str_contains( $hook, 'gpante-support' ) ) {
		return;
	}
	wp_enqueue_style( 'gpante-support-admin', GPANTE_SUPPORT_URL . 'assets/admin.css', array(), GPANTE_SUPPORT_VERSION );
	wp_enqueue_script( 'gpante-support-admin', GPANTE_SUPPORT_URL . 'assets/admin.js', array( 'common' ), GPANTE_SUPPORT_VERSION, true );
}
