<?php
/**
 * Native administrator archive and central history policy. No transcript copies in WordPress.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin-ui.php';

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
	if ( is_array( $data ) && 'invalid_input' === ( $data['error'] ?? null ) ) {
		return new WP_Error( 'invalid_input', __( 'فیلتر یا مقدار واردشده معتبر نیست؛ تاریخ‌ها و ترتیب بازه را بررسی کنید.', 'gpante-support-chat' ) );
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

/**
 * Read only the bounded list filters used by the existing archive contract.
 *
 * @param array $input Query values.
 * @return array
 */
function gpante_support_archive_filters( $input ) {
	$filters = array();
	foreach ( array( 'search', 'from', 'to', 'status', 'visibility' ) as $key ) {
		$filters[ $key ] = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
	}
	$filters['search'] = mb_substr( $filters['search'], 0, 100 );
	return $filters;
}

/** Render the escaped exchange first, with technical metadata available on demand. */
function gpante_support_archive_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	nocache_headers();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Bounded read-only navigation under manage_options.
	$filters   = gpante_support_archive_filters( $_GET );
	$page_no   = isset( $_GET['archive_page'] ) ? max( 1, min( 1000, absint( $_GET['archive_page'] ) ) ) : 1;
	$list_page = isset( $_GET['return_page'] ) ? max( 1, min( 1000, absint( $_GET['return_page'] ) ) ) : 1;
	$sid       = isset( $_GET['session'] ) && is_string( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
	$query     = array_merge(
		array(
			'page'         => 'gpante-support-conversations',
			'archive_page' => $page_no,
		),
		$filters
	);
	$policy    = gpante_support_admin_request( array( 'operation' => 'getPolicy' ) );
	?>
	<div class="wrap gpante-admin" dir="rtl">
		<h1 class="gpante-admin-header"><?php esc_html_e( 'گفتگوهای چت پانته', 'gpante-support-chat' ); ?></h1>
		<p class="gpante-admin-description"><?php esc_html_e( 'آرشیو مدیریتی: ۳۰ روز از زمان اصلی هر پرسش. نشست‌ها ناشناس‌اند و شناسهٔ آن‌ها حساب مشتری نیست. تاریخ‌ها میلادی و UTC هستند. خواندن این صفحه هزینهٔ مدل ندارد.', 'gpante-support-chat' ); ?></p>
		<?php if ( is_wp_error( $policy ) || ! gpante_support_valid_policy( $policy['policy'] ?? null ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( is_wp_error( $policy ) ? $policy->get_error_message() : __( 'سیاست فعلی دریافت نشد؛ فرم تغییر سیاست در دسترس نیست.', 'gpante-support-chat' ) ); ?></p></div>
		<?php else : ?>
			<?php $cfg = $policy['policy']; ?>
			<details class="gpante-admin-card"><summary><?php esc_html_e( 'سیاست تاریخچه و پاک‌کردن کاربر', 'gpante-support-chat' ); ?></summary>
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
				<p><?php esc_html_e( 'آرشیو مدیریتی همیشه ۳۰ روز است. پاک‌کردن کاربر آرشیو مدیر را حذف نمی‌کند؛ افزایش مدت، پیام منقضی یا پاک‌شده را برنمی‌گرداند.', 'gpante-support-chat' ); ?></p>
				<?php submit_button( __( 'ذخیرهٔ سیاست مرکزی', 'gpante-support-chat' ) ); ?>
			</form></details>
		<?php endif; ?>
		<?php
		if ( '' !== $sid && ! preg_match( '/^[a-f0-9]{64}$/D', $sid ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'شناسهٔ نشست معتبر نیست.', 'gpante-support-chat' ) . '</p></div></div>';
			return;
		}
		if ( '' !== $sid ) {
			$query['session']     = $sid;
			$query['return_page'] = $list_page;
			$back                 = array_merge(
				array(
					'page'         => 'gpante-support-conversations',
					'archive_page' => $list_page,
				),
				$filters
			);
				$return_view      = isset( $_GET['return_view'] ) && is_string( $_GET['return_view'] ) ? sanitize_text_field( wp_unslash( $_GET['return_view'] ) ) : '';
			if ( 'diagnostics' === $return_view ) {
				$back                 = array_merge(
					array(
						'page'            => 'gpante-support-diagnostics',
						'diagnostic_page' => $list_page,
					),
					array_intersect_key( $filters, array_flip( array( 'search', 'from', 'to', 'status' ) ) )
				);
				$query['return_view'] = 'diagnostics';
				foreach ( array( 'traffic', 'source' ) as $key ) {
					$value         = isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
					$back[ $key ]  = $value;
					$query[ $key ] = $value;
				}
			}
			echo '<p><a class="button" href="' . esc_url( add_query_arg( $back, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'بازگشت به فهرست', 'gpante-support-chat' ) . '</a></p>';
			echo '<h2>' . esc_html__( 'گفتگوی ناشناس', 'gpante-support-chat' ) . '</h2>' . gpante_support_admin_reference( $sid ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Helper emits fixed escaped markup.
			$data = gpante_support_admin_request(
				array(
					'operation' => 'getSession',
					'sessionId' => $sid,
					'page'      => $page_no,
				)
			);
			if ( ! is_wp_error( $data ) && is_array( $data['turns'] ?? null ) ) {
				foreach ( $data['turns'] as $turn ) {
					if ( ! is_array( $turn ) ) {
						continue;
					}
					echo '<article class="gpante-admin-card gpante-admin-turn">';
					echo '<div class="gpante-admin-card__header">' . gpante_support_admin_badge( $turn['status'] ?? null ) . '<time>' . esc_html( gpante_support_admin_time( $turn['createdAt'] ?? null ) ) . '</time></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge helper escapes values.
					foreach ( is_array( $turn['messages'] ?? null ) ? $turn['messages'] : array() as $message ) {
						if ( ! is_array( $message ) || ! is_string( $message['content'] ?? null ) || ! in_array( $message['role'] ?? null, array( 'user', 'assistant' ), true ) ) {
							continue;
						}
						$role = $message['role'];
						echo '<section class="gpante-admin-message is-' . esc_attr( $role ) . '"><h3>' . esc_html( 'user' === $role ? __( 'پرسش کاربر', 'gpante-support-chat' ) : __( 'پاسخ دستیار', 'gpante-support-chat' ) ) . '</h3><div class="gpante-admin-message__content">' . esc_html( $message['content'] ) . '</div></section>';
					}
					echo '<dl class="gpante-admin-summary"><div><dt>' . esc_html__( 'زمان پاسخ', 'gpante-support-chat' ) . '</dt><dd>' . esc_html( gpante_support_admin_duration( $turn['durationMs'] ?? null ) ) . '</dd></div><div><dt>' . esc_html__( 'مصرف محافظه‌کارانه، USD', 'gpante-support-chat' ) . '</dt><dd dir="ltr">' . esc_html( gpante_support_admin_money( $turn['costNano'] ?? null ) ) . '</dd></div></dl>';
					if ( false === ( $turn['usageComplete'] ?? null ) ) {
						echo '<p class="gpante-admin-warning">' . esc_html__( 'مصرف نهایی تأیید نشده؛ مبلغ شامل رزرو محافظه‌کارانه است.', 'gpante-support-chat' ) . '</p>';
					}
					if ( ! empty( $turn['errorCode'] ) ) {
						echo '<p class="gpante-admin-warning">' . esc_html( gpante_support_admin_error_hint( $turn['errorCode'] ) ) . '</p>';
					}
					echo '<details><summary>' . esc_html__( 'جزئیات فنی', 'gpante-support-chat' ) . '</summary><dl class="gpante-admin-details">';
					foreach ( array(
						'errorCode'        => 'کد خطا',
						'createdAt'        => 'زمان اصلی UTC',
						'durationMs'       => 'مدت پاسخ، میلی‌ثانیه',
						'calls'            => 'تماس مدل',
						'promptTokens'     => 'توکن ورودی',
						'completionTokens' => 'توکن خروجی',
						'costNano'         => 'مصرف محافظه‌کارانه، نانودلار',
						'userExpiresAt'    => 'پایان نمایش کاربر',
						'archiveExpiresAt' => 'پایان آرشیو مدیر',
						'clearedAt'        => 'پاک‌شده در',
					) as $key => $label ) {
						$value = $turn[ $key ] ?? null;
						echo '<div><dt>' . esc_html( $label ) . '</dt><dd dir="ltr">' . gpante_support_archive_value( is_scalar( $value ) ? $value : null ) . '</dd></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Scalar escaping helper.
					}
					echo '</dl></details></article>';
				}
				if ( empty( $data['turns'] ) ) {
					echo '<p>' . esc_html__( 'پیامی در بازهٔ نگهداری موجود نیست.', 'gpante-support-chat' ) . '</p>';
				}
			}
		} else {
			?>
			<form method="get" class="gpante-admin-card gpante-admin-filters"><input type="hidden" name="page" value="gpante-support-conversations">
				<label><?php esc_html_e( 'جستجوی متن گفتگو', 'gpante-support-chat' ); ?><input name="search" maxlength="100" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
				<label><?php esc_html_e( 'از تاریخ، UTC', 'gpante-support-chat' ); ?><input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
				<label><?php esc_html_e( 'تا تاریخ، UTC', 'gpante-support-chat' ); ?><input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
				<label><?php esc_html_e( 'وضعیت', 'gpante-support-chat' ); ?><select name="status">
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
				<label><?php esc_html_e( 'دسترسی کاربر', 'gpante-support-chat' ); ?><select name="visibility">
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
				<button class="button button-primary"><?php esc_html_e( 'فیلتر', 'gpante-support-chat' ); ?></button>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gpante-support-conversations' ) ); ?>"><?php esc_html_e( 'پاک‌کردن فیلترها', 'gpante-support-chat' ); ?></a>
			</form>
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
				echo '<div class="gpante-admin-table-wrap"><table class="widefat striped"><thead><tr>';
				foreach ( array( 'گفتگوی ناشناس', 'آخرین فعالیت مطابق فیلتر', 'اولین فعالیت مطابق فیلتر', 'نوبت‌ها', 'پیام‌ها', 'خطاها' ) as $label ) {
					echo '<th scope="col">' . esc_html( $label ) . '</th>';
				}
				echo '</tr></thead><tbody>';
				foreach ( $data['sessions'] as $session ) {
					if ( ! is_array( $session ) || ! is_string( $session['sessionId'] ?? null ) || ! preg_match( '/^[a-f0-9]{64}$/D', $session['sessionId'] ) ) {
						continue;
					}
					$link = add_query_arg(
						array_merge(
							$query,
							array(
								'session'      => $session['sessionId'],
								'archive_page' => 1,
								'return_page'  => $page_no,
							)
						),
						admin_url( 'admin.php' )
					);
					echo '<tr><td><a href="' . esc_url( $link ) . '">' . esc_html__( 'مشاهدهٔ گفتگو', 'gpante-support-chat' ) . '</a><br><code>' . esc_html( substr( $session['sessionId'], 0, 12 ) . '…' ) . '</code></td><td>' . esc_html( gpante_support_admin_time( $session['lastAt'] ?? null ) ) . '</td><td>' . esc_html( gpante_support_admin_time( $session['firstAt'] ?? null ) ) . '</td>';
					foreach ( array( 'turns', 'messages', 'errors' ) as $key ) {
						echo '<td>' . esc_html( gpante_support_admin_number( $session[ $key ] ?? null ) ) . '</td>';
					}
					echo '</tr>';
				}
				if ( empty( $data['sessions'] ) ) {
					echo '<tr><td colspan="6">' . esc_html__( 'گفتگویی مطابق این فیلترها در بازهٔ آرشیو وجود ندارد.', 'gpante-support-chat' ) . '</td></tr>';
				}
				echo '</tbody></table></div>';
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( is_wp_error( $data ) || ! is_array( $data ) || ( '' === $sid ? ! is_array( $data['sessions'] ?? null ) : ! is_array( $data['turns'] ?? null ) ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( is_wp_error( $data ) ? $data->get_error_message() : __( 'آرشیو پاسخ معتبر نداد؛ نتیجهٔ خالی تأیید نشده است.', 'gpante-support-chat' ) ) . '</p></div>';
		} else {
				echo '<nav class="gpante-admin-pager" aria-label="' . esc_attr__( 'صفحه‌های آرشیو', 'gpante-support-chat' ) . '">';
				$more = true === ( $data['hasMore'] ?? false );
				gpante_support_archive_pager( $query, $more );
			echo '</nav>';
		}
		?>
	</div>
	<?php
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
