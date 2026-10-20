<?php
/**
 * Native administrator archive and central history policy. No transcript copies in WordPress.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Call the separate, fixed administrative service; independent of public enabled state.
 *
 * @param array $payload Validated fixed operation.
 * @return array|WP_Error
 */
function gpante_support_admin_request( $payload ) {
	$config = get_option( 'gpante_support_runtime', array() );
	if ( ! is_array( $config ) || (int) ( $config['schema_version'] ?? 0 ) > GPANTE_SUPPORT_SCHEMA_VERSION || ! gpante_support_valid_key( $config['admin_key'] ?? '' ) ) {
		return new WP_Error( 'admin_unavailable', __( 'اتصال خصوصی مدیریت آماده نیست. کلید مدیریت را در تنظیمات بررسی کنید.', 'gpante-support-chat' ) );
	}
	$result = wp_remote_post(
		GPANTE_SUPPORT_ADMIN_BACKEND,
		array(
			'headers'             => array(
				'Content-Type'       => 'application/json',
				'Accept'             => 'application/json',
				'X-Gpante-Admin-Key' => $config['admin_key'],
			),
			'body'                => wp_json_encode( $payload ),
			'timeout'             => 15,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 1048576,
		)
	);
	if ( is_wp_error( $result ) ) {
		return new WP_Error( 'admin_unavailable', __( 'ارتباط با آرشیو برقرار نشد؛ دوباره تلاش کنید.', 'gpante-support-chat' ) );
	}
	$data = json_decode( wp_remote_retrieve_body( $result ), true );
	if ( 409 === (int) wp_remote_retrieve_response_code( $result ) ) {
		return new WP_Error( 'policy_conflict', __( 'سیاست هم‌زمان تغییر کرده است. صفحه را تازه و دوباره ذخیره کنید.', 'gpante-support-chat' ) );
	}
	if ( 200 !== (int) wp_remote_retrieve_response_code( $result ) || ! is_array( $data ) || isset( $data['error'] ) ) {
		return new WP_Error( 'admin_unavailable', __( 'آرشیو پاسخ معتبر نداد؛ تغییری تأیید نشد.', 'gpante-support-chat' ) );
	}
	return $data;
}

/** Register a native menu; the original Settings URL remains valid. */
function gpante_support_archive_menu() {
	add_menu_page( __( 'گفتگوهای پانته', 'gpante-support-chat' ), __( 'چت پانته', 'gpante-support-chat' ), 'manage_options', 'gpante-support-conversations', 'gpante_support_archive_page', 'dashicons-format-chat', 58 );
	add_submenu_page( 'gpante-support-conversations', __( 'گفتگوها', 'gpante-support-chat' ), __( 'گفتگوها', 'gpante-support-chat' ), 'manage_options', 'gpante-support-conversations', 'gpante_support_archive_page' );
	add_submenu_page( 'gpante-support-conversations', __( 'تنظیمات اتصال', 'gpante-support-chat' ), __( 'تنظیمات اتصال', 'gpante-support-chat' ), 'manage_options', 'gpante-support-connection', 'gpante_support_settings_page' );
}
add_action( 'admin_menu', 'gpante_support_archive_menu' );

/**
 * Print a bounded, escaped technical value; null is never invented as zero.
 *
 * @param mixed $value Value.
 * @return string
 */
function gpante_support_archive_value( $value ) {
	return null === $value ? '—' : esc_html( (string) $value );
}

/**
 * Render pagination using only the validated current query.
 *
 * @param array $query Page arguments.
 * @param bool  $has_more Whether another page exists.
 */
function gpante_support_archive_pager( $query, $has_more ) {
	$page_no = $query['archive_page'];
	if ( $page_no > 1 ) {
		echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $query, array( 'archive_page' => $page_no - 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'صفحهٔ قبل', 'gpante-support-chat' ) . '</a> ';
	}
	echo '<span>' . esc_html( number_format_i18n( $page_no ) ) . '</span> ';
	if ( $has_more && $page_no < 1000 ) {
		echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $query, array( 'archive_page' => $page_no + 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'صفحهٔ بعد', 'gpante-support-chat' ) . '</a>';
	}
}

/** Render escaped transcript and policy. Cookie-authenticated wp-admin enforces access. */
function gpante_support_archive_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	nocache_headers();
	$policy = gpante_support_admin_request( array( 'operation' => 'getPolicy' ) );
	?>
	<div class="wrap" dir="rtl">
	<h1><?php esc_html_e( 'گفتگوهای چت پانته', 'gpante-support-chat' ); ?></h1>
	<p><?php esc_html_e( 'آرشیو نشست‌های ناشناس تا ۳۰ روز از زمان اصلی هر پرسش قابل بررسی است؛ این شناسه‌ها نام یا حساب مشتری نیستند. تاریخ‌ها UTC هستند. خواندن این صفحه هزینهٔ مدل ندارد.', 'gpante-support-chat' ); ?></p>
	<?php
	if ( is_wp_error( $policy ) || ! gpante_support_valid_policy( $policy['policy'] ?? null ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( is_wp_error( $policy ) ? $policy->get_error_message() : __( 'سیاست معتبر دریافت نشد.', 'gpante-support-chat' ) ) . '</p></div></div>';
		return;
	}
	$cfg = $policy['policy'];
	?>
	<details><summary><?php esc_html_e( 'سیاست تاریخچه و پاک‌کردن کاربر', 'gpante-support-chat' ); ?></summary>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="gpante_support_policy">
		<input type="hidden" name="revision" value="<?php echo esc_attr( $cfg['revision'] ); ?>">
		<?php wp_nonce_field( 'gpante_support_policy' ); ?>
		<p><label for="gpante-user-days"><?php esc_html_e( 'مدت نمایش تاریخچه برای همهٔ کاربران', 'gpante-support-chat' ); ?></label>
		<select id="gpante-user-days" name="user_days">
		<?php foreach ( array( 3, 7, 15, 30 ) as $days ) : ?>
			<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $cfg['userDays'], $days ); ?>><?php echo esc_html( number_format_i18n( $days ) . ' ' . __( 'روز', 'gpante-support-chat' ) ); ?></option>
		<?php endforeach; ?>
		</select></p>
		<p><label><input type="checkbox" name="allow_clear" value="1" <?php checked( $cfg['allowClear'] ); ?>> <?php esc_html_e( 'کاربر بتواند تاریخچهٔ قابل مشاهدهٔ خود را پاک کند', 'gpante-support-chat' ); ?></label></p>
		<p><?php esc_html_e( 'آرشیو مدیریتی همیشه ۳۰ روز است. افزایش مدت، پیام منقضی یا پاک‌شده را بازنمی‌گرداند. پاک‌کردن کاربر روی این آرشیو اثر ندارد.', 'gpante-support-chat' ); ?></p>
		<?php submit_button( __( 'ذخیرهٔ سیاست مرکزی', 'gpante-support-chat' ) ); ?>
	</form></details>
	<?php
	// GET only selects bounded read filters; no mutation and no arbitrary query/URL forwarding.
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only archive navigation under manage_options.
	$page_no = isset( $_GET['archive_page'] ) ? max( 1, min( 1000, absint( $_GET['archive_page'] ) ) ) : 1;
	$sid     = isset( $_GET['session'] ) && is_string( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
	if ( '' !== $sid && ! preg_match( '/^[a-f0-9]{64}$/D', $sid ) ) {
		echo '<p>' . esc_html__( 'شناسهٔ نشست معتبر نیست.', 'gpante-support-chat' ) . '</p></div>';
		return;
	}
	$query = array(
		'page'         => 'gpante-support-conversations',
		'archive_page' => $page_no,
	);
	if ( '' !== $sid ) {
		$query['session'] = $sid;
		$data             = gpante_support_admin_request(
			array(
				'operation' => 'getSession',
				'sessionId' => $sid,
				'page'      => $page_no,
			)
		);
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gpante-support-conversations' ) ) . '">' . esc_html__( 'بازگشت به فهرست', 'gpante-support-chat' ) . '</a></p><h2><code>' . esc_html( $sid ) . '</code></h2>';
		if ( ! is_wp_error( $data ) && is_array( $data['turns'] ?? null ) ) {
			foreach ( $data['turns'] as $turn ) {
				echo '<div class="postbox" style="padding:16px">';
				echo '<p>' . esc_html__( 'زمان / وضعیت / خطا', 'gpante-support-chat' ) . ': ' . gpante_support_archive_value( $turn['createdAt'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['status'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['errorCode'] ?? null ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper escapes each value.
				foreach ( $turn['messages'] ?? array() as $message ) {
					echo '<p><strong>' . esc_html( 'user' === ( $message['role'] ?? '' ) ? __( 'کاربر', 'gpante-support-chat' ) : __( 'دستیار', 'gpante-support-chat' ) ) . '</strong></p><div style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( $message['content'] ?? '' ) . '</div>';
				}
				echo '<p>' . esc_html__( 'انقضای نمایش / پایان آرشیو / پاک‌شده در', 'gpante-support-chat' ) . ': ' . gpante_support_archive_value( $turn['userExpiresAt'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['archiveExpiresAt'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['clearedAt'] ?? null ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping helper.
				echo '<p>' . esc_html__( 'مدت پاسخ (ms) / تماس مدل / توکن ورودی / خروجی / هزینهٔ محافظه‌کارانه (نانودلار)', 'gpante-support-chat' ) . ': ' . gpante_support_archive_value( $turn['durationMs'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['calls'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['promptTokens'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['completionTokens'] ?? null ) . ' / ' . gpante_support_archive_value( $turn['costNano'] ?? null ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaping helper.
				if ( false === ( $turn['usageComplete'] ?? null ) ) {
					echo '<p>' . esc_html__( 'usage کامل نیست؛ هزینه شامل رزرو محافظه‌کارانه است.', 'gpante-support-chat' ) . '</p>';
				}
				echo '</div>';
			}
			if ( empty( $data['turns'] ) ) {
				echo '<p>' . esc_html__( 'پیامی در بازهٔ نگهداری موجود نیست.', 'gpante-support-chat' ) . '</p>';
			}
		}
	} else {
		$filters = array();
		foreach ( array( 'search', 'from', 'to', 'status', 'visibility' ) as $key ) {
			$filters[ $key ] = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		}
		$filters['search'] = mb_substr( $filters['search'], 0, 100 );
		$query             = array_merge( $query, $filters );
		?>
		<form method="get"><input type="hidden" name="page" value="gpante-support-conversations">
		<p><label><?php esc_html_e( 'جست‌وجوی متن', 'gpante-support-chat' ); ?> <input name="search" maxlength="100" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
		<label><?php esc_html_e( 'از', 'gpante-support-chat' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
		<label><?php esc_html_e( 'تا', 'gpante-support-chat' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
		<label><?php esc_html_e( 'وضعیت', 'gpante-support-chat' ); ?> <select name="status">
		<?php
		foreach ( array(
			''            => 'همه',
			'success'     => 'موفق',
			'error'       => 'خطا',
			'interrupted' => 'قطع‌شده',
			'pending'     => 'در حال پردازش',
			'legacy'      => 'قدیمی',
		) as $value => $label ) :
			?>
		<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?></select></label>
		<label><?php esc_html_e( 'دسترسی کاربر', 'gpante-support-chat' ); ?> <select name="visibility">
		<?php
		foreach ( array(
			''        => 'همه',
			'visible' => 'قابل مشاهده',
			'cleared' => 'پاک‌شده',
			'expired' => 'منقضی',
		) as $value => $label ) :
			?>
		<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['visibility'], $value ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?></select></label>
		<button class="button"><?php esc_html_e( 'فیلتر', 'gpante-support-chat' ); ?></button></p></form>
		<?php
		$data = gpante_support_admin_request(
			array_merge(
				$filters,
				array(
					'operation' => 'listSessions',
					'page'      => $page_no,
				)
			)
		);
		if ( ! is_wp_error( $data ) && is_array( $data['sessions'] ?? null ) ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'نشست ناشناس', 'gpante-support-chat' ) . '</th><th>' . esc_html__( 'اولین / آخرین تطبیق', 'gpante-support-chat' ) . '</th><th>' . esc_html__( 'رکورد / پیام / خطا', 'gpante-support-chat' ) . '</th></tr></thead><tbody>';
			foreach ( $data['sessions'] as $session ) {
				$link = add_query_arg(
					array(
						'page'    => 'gpante-support-conversations',
						'session' => $session['sessionId'],
					),
					admin_url( 'admin.php' )
				);
				echo '<tr><td><a href="' . esc_url( $link ) . '"><code>' . esc_html( substr( $session['sessionId'], 0, 16 ) ) . '…</code></a></td><td>' . esc_html( $session['firstAt'] . ' / ' . $session['lastAt'] ) . '</td><td>' . esc_html( $session['turns'] . ' / ' . $session['messages'] . ' / ' . $session['errors'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	if ( is_wp_error( $data ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $data->get_error_message() ) . '</p></div>';
	} else {
		$more = true === ( $data['hasMore'] ?? false );
		gpante_support_archive_pager( $query, $more );
	}
	echo '</div>';
}

/** Authorize and persist policy centrally; a transport error never appears as success. */
function gpante_support_save_policy() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی مجاز نیست.', 'gpante-support-chat' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'gpante_support_policy' );
	$days     = isset( $_POST['user_days'] ) ? absint( $_POST['user_days'] ) : 0;
	$revision = isset( $_POST['revision'] ) ? absint( $_POST['revision'] ) : 0;
	if ( ! in_array( $days, array( 3, 7, 15, 30 ), true ) || $revision < 1 ) {
		wp_die( esc_html__( 'تنظیمات معتبر نیست.', 'gpante-support-chat' ), '', array( 'response' => 400 ) );
	}
	$result = gpante_support_admin_request(
		array(
			'operation'  => 'setPolicy',
			'userDays'   => $days,
			'allowClear' => isset( $_POST['allow_clear'] ) && '1' === $_POST['allow_clear'],
			'revision'   => $revision,
		)
	);
	if ( is_wp_error( $result ) || ! gpante_support_valid_policy( $result['policy'] ?? null ) ) {
		wp_die(
			esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'ذخیره تأیید نشد.', 'gpante-support-chat' ) ),
			'',
			array(
				'response'  => 502,
				'back_link' => true,
			)
		);
	}
	wp_safe_redirect( admin_url( 'admin.php?page=gpante-support-conversations' ) );
	exit;
}
add_action( 'admin_post_gpante_support_policy', 'gpante_support_save_policy' );
