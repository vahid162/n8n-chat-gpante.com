<?php
/**
 * Administrator-only source health and bounded search diagnostics.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/admin-ui.php';

/** Register the diagnostic page beside the existing conversation archive. */
function gpante_support_diagnostics_menu() {
	add_submenu_page( 'gpante-support-conversations', __( 'کیفیت و منابع', 'gpante-support-chat' ), __( 'کیفیت و منابع', 'gpante-support-chat' ), 'manage_options', 'gpante-support-diagnostics', 'gpante_support_diagnostics_page' );
}
add_action( 'admin_menu', 'gpante_support_diagnostics_menu' );

/**
 * Render an explicit scalar allowlist without inventing missing values.
 *
 * @param array $values Service values.
 * @param array $labels Allowed fields and their labels.
 */
function gpante_support_diagnostics_values( $values, $labels ) {
	echo '<table class="widefat striped"><tbody>';
	foreach ( $labels as $key => $label ) {
		$value = $values[ $key ] ?? null;
		$value = is_scalar( $value ) ? $value : null;
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . gpante_support_archive_value( $value ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Existing helper escapes scalar values.
	}
	echo '</tbody></table>';
}

/**
 * Read bounded diagnostic filters. The backend validates them again before SQL.
 *
 * @param array $input Query.
 * @return array
 */
function gpante_support_diagnostic_filters( $input ) {
	$filters = array();
	foreach ( array( 'search', 'from', 'to', 'status', 'traffic', 'source' ) as $key ) {
		$filters[ $key ] = isset( $input[ $key ] ) && is_string( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
	}
	$filters['search'] = mb_substr( $filters['search'], 0, 100 );
	return $filters;
}

/**
 * Keep the same bounded public-metadata allowlist in both human and technical views.
 *
 * @param array $event Event.
 * @return array
 */
function gpante_support_diagnostic_metadata( $event ) {
	$metadata = array();
	if ( 'source' === ( $event['event'] ?? null ) ) {
		foreach ( array( 'event', 'tool', 'source', 'status', 'query', 'coverage' ) as $key ) {
			if ( is_string( $event[ $key ] ?? null ) ) {
				$metadata[ $key ] = mb_substr( $event[ $key ], 0, 120 );
			}
		}
		$metadata['filters'] = array();
		foreach ( array( 'format', 'material', 'thickness', 'order', 'category' ) as $key ) {
			if ( is_scalar( $event['filters'][ $key ] ?? null ) ) {
				$metadata['filters'][ $key ] = mb_substr( (string) $event['filters'][ $key ], 0, 40 );
			}
		}
		$metadata['results'] = array();
		foreach ( array_slice( is_array( $event['results'] ?? null ) ? $event['results'] : array(), 0, 5 ) as $result ) {
			if ( is_array( $result ) && ( is_string( $result['id'] ?? null ) || is_int( $result['id'] ?? null ) ) && is_int( $result['rank'] ?? null ) && $result['rank'] >= 1 && $result['rank'] <= 5 && is_string( $result['kind'] ?? null ) ) {
				$metadata['results'][] = array(
					'id'   => mb_substr( (string) $result['id'], 0, 80 ),
					'rank' => $result['rank'],
					'kind' => mb_substr( $result['kind'], 0, 20 ),
				);
			}
		}
	} elseif ( 'rejection' === ( $event['event'] ?? null ) && is_string( $event['code'] ?? null ) ) {
		$metadata = array(
			'event' => 'rejection',
			'code'  => mb_substr( $event['code'], 0, 64 ),
		);
	}
	return $metadata;
}

/**
 * Present recorded search steps; result IDs are not a complete result count.
 *
 * @param array $events Bounded events.
 */
function gpante_support_diagnostic_steps( $events ) {
	$technical = array();
	foreach ( array_slice( $events, 0, 12 ) as $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}
		$metadata = gpante_support_diagnostic_metadata( $event );
		if ( $metadata ) {
			$technical[] = $metadata;
		}
	}
	if ( ! $technical ) {
		echo '<p>' . esc_html__( 'جزئیات مسیر منابع برای این نوبت ثبت نشده است.', 'gpante-support-chat' ) . '</p>';
		return;
	}
	echo '<details><summary>' . esc_html__( 'مسیر منابع و تصمیم‌ها', 'gpante-support-chat' ) . '</summary><div class="gpante-admin-table-wrap"><table class="widefat striped"><thead><tr>';
	foreach ( array( 'منبع / ابزار', 'جستجو و شرط‌ها', 'نتایج ثبت‌شده، حداکثر ۵', 'پوشش / نتیجه' ) as $label ) {
		echo '<th scope="col">' . esc_html( $label ) . '</th>';
	}
	echo '</tr></thead><tbody>';
	foreach ( $technical as $step ) {
		if ( 'rejection' === $step['event'] ) {
			echo '<tr><td>' . esc_html__( 'کنترل پاسخ', 'gpante-support-chat' ) . '</td><td colspan="3">' . esc_html( gpante_support_admin_error_hint( $step['code'] ) ) . ' <code>' . esc_html( $step['code'] ) . '</code></td></tr>';
			continue;
		}
		echo '<tr><td>' . esc_html( gpante_support_admin_label( $step['source'] ?? null, 'source' ) ) . '<br><code>' . esc_html( $step['tool'] ?? '' ) . '</code></td><td>' . esc_html( $step['query'] ?? '' ) . '<dl>';
		$labels = array(
			'format'    => 'فرمت',
			'material'  => 'جنس',
			'thickness' => 'ضخامت',
			'order'     => 'ترتیب',
			'category'  => 'دسته',
		);
		foreach ( $step['filters'] as $key => $value ) {
			echo '<dt>' . esc_html( $labels[ $key ] ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl></td><td>';
		if ( ! $step['results'] ) {
			echo esc_html__( 'نتیجه‌ای در گزارش ثبت نشده است.', 'gpante-support-chat' );
		}
		foreach ( $step['results'] as $result ) {
			echo '<div>' . esc_html__( 'رتبه', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $result['rank'] ) ) . ' · <code>' . esc_html( $result['id'] ) . '</code> · ' . esc_html( $result['kind'] ) . '</div>';
		}
		$status = gpante_support_admin_label( $step['status'] ?? null );
		echo '</td><td>' . esc_html( gpante_support_admin_label( $step['coverage'] ?? null, 'coverage' ) ) . '<br>' . esc_html( gpante_support_admin_unknown() === $status ? ( $step['status'] ?? $status ) : $status ) . '</td></tr>';
	}
	echo '</tbody></table></div><details><summary>' . esc_html__( 'دادهٔ فنی مجاز', 'gpante-support-chat' ) . '</summary>';
	foreach ( $technical as $step ) {
		echo '<pre dir="ltr">' . esc_html( wp_json_encode( $step ) ) . '</pre>';
	}
	echo '</details></details>';
}

/** Render a daily overview and paginated, server-filtered diagnostic records. */
function gpante_support_diagnostics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	nocache_headers();
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only bounded diagnostic filters.
	$filters = gpante_support_diagnostic_filters( $_GET );
	$page_no = isset( $_GET['diagnostic_page'] ) ? max( 1, min( 1000, absint( $_GET['diagnostic_page'] ) ) ) : 1;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
	$payload  = array( 'operation' => 'getDiagnostics' );
	$filtered = (bool) array_filter( $filters, static fn( $value ) => '' !== $value );
	if ( $page_no > 1 || $filtered ) {
		$payload = array_merge( $payload, $filters, array( 'page' => $page_no ) );
	}
	$health  = gpante_support_search_index_health();
	$data    = gpante_support_admin_request( $payload );
	$valid   = ! is_wp_error( $data ) && is_array( $data['summary'] ?? null ) && is_array( $data['budget'] ?? null ) && is_array( $data['turns'] ?? null );
	$budget  = $valid ? $data['budget'] : array();
	$totals  = $valid ? $data['summary'] : array();
	$checked = gpante_support_admin_time( gmdate( 'c' ) );
	?>
	<div class="wrap gpante-admin" dir="rtl">
		<h1 class="gpante-admin-header"><?php esc_html_e( 'کیفیت پاسخ و سلامت منابع', 'gpante-support-chat' ); ?></h1>
		<p class="gpante-admin-description"><?php esc_html_e( 'نمای کلی روزمره و گزارش محدود منابع. این صفحه تماس مدل ندارد. آمار گفتگو و بازخورد: ۷ روز گذشته؛ بودجه: ماه تقویمی جاری UTC؛ آرشیو مدیر: ۳۰ روز. تاریخ‌ها میلادی و UTC هستند.', 'gpante-support-chat' ); ?></p>
		<p><?php echo esc_html__( 'زمان این بررسی:', 'gpante-support-chat' ) . ' ' . esc_html( $checked ); ?> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=gpante-support-diagnostics' ) ); ?>"><?php esc_html_e( 'بررسی دوباره', 'gpante-support-chat' ); ?></a></p>
		<div class="gpante-admin-overview">
			<section class="gpante-admin-card"><h2><?php esc_html_e( 'اتصال مدیریت', 'gpante-support-chat' ); ?></h2><p class="gpante-admin-metric"><?php echo esc_html( $valid ? __( 'پاسخ معتبر دریافت شد', 'gpante-support-chat' ) : __( 'پاسخ تأیید نشد', 'gpante-support-chat' ) ); ?></p><p><?php esc_html_e( 'این نتیجه، اتصال سرویس مدیریت را نشان می‌دهد؛ سلامت پیوستهٔ مدل را تضمین نمی‌کند.', 'gpante-support-chat' ); ?></p></section>
			<section class="gpante-admin-card"><h2><?php esc_html_e( 'نمایهٔ عمومی سایت', 'gpante-support-chat' ); ?></h2><p class="gpante-admin-metric"><?php echo esc_html( true === ( $health['complete'] ?? null ) ? __( 'کامل', 'gpante-support-chat' ) : ( false === ( $health['complete'] ?? null ) ? __( 'ناقص', 'gpante-support-chat' ) : gpante_support_admin_unknown() ) ); ?></p><p><?php echo esc_html__( 'اسناد نمایه‌شده:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $health['documents'] ?? null ) ); ?></p><p><?php echo esc_html__( 'اسناد نامعتبر:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $health['invalidDocuments'] ?? null ) ); ?></p><p><?php echo esc_html( gpante_support_admin_time( $health['reconciledAt'] ?? null ) ); ?></p></section>
			<section class="gpante-admin-card"><h2><?php esc_html_e( 'گفتگوها، ۷ روز گذشته', 'gpante-support-chat' ); ?></h2><p class="gpante-admin-metric"><?php echo esc_html( gpante_support_admin_number( $totals['total'] ?? null ) ); ?></p><p><?php echo esc_html__( 'خطا یا قطع‌شده:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $totals['errors'] ?? null ) ); ?></p><a href="<?php echo esc_url( admin_url( 'admin.php?page=gpante-support-diagnostics&status=error#gpante-diagnostic-records' ) ); ?>"><?php esc_html_e( 'بررسی گفتگوهای دارای خطا', 'gpante-support-chat' ); ?></a> · <a href="<?php echo esc_url( admin_url( 'admin.php?page=gpante-support-diagnostics&status=interrupted#gpante-diagnostic-records' ) ); ?>"><?php esc_html_e( 'بررسی قطع‌شده‌ها', 'gpante-support-chat' ); ?></a></section>
			<section class="gpante-admin-card"><h2><?php esc_html_e( 'مصرف محافظه‌کارانه، ماه جاری UTC', 'gpante-support-chat' ); ?></h2><p class="gpante-admin-metric" dir="ltr"><?php echo esc_html( gpante_support_admin_money( $budget['usedNano'] ?? null ) ); ?></p><p><?php echo esc_html__( 'سقف ماه، USD:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_money( $budget['monthlyBudgetNano'] ?? null ) ); ?></p><p><?php esc_html_e( 'شامل رزرو و مصرف محافظه‌کارانه؛ هزینهٔ قطعیِ تسویه‌شده نیست.', 'gpante-support-chat' ); ?></p><p><?php echo esc_html__( 'درخواست فعال:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $budget['activeLeases'] ?? null ) ); ?></p></section>
			<section class="gpante-admin-card"><h2><?php esc_html_e( 'بازخورد ثبت‌شده، ۷ روز', 'gpante-support-chat' ); ?></h2><p><?php echo esc_html__( 'مفید:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $totals['helpful'] ?? null ) ); ?></p><p><?php echo esc_html__( 'نامفید:', 'gpante-support-chat' ) . ' ' . esc_html( gpante_support_admin_number( $totals['unhelpful'] ?? null ) ); ?></p><p><?php esc_html_e( 'این شمار بازخورد است، نه درصد دقت همهٔ پاسخ‌ها.', 'gpante-support-chat' ); ?></p></section>
		</div>
		<details class="gpante-admin-card"><summary><?php esc_html_e( 'جزئیات وضعیت و بودجه', 'gpante-support-chat' ); ?></summary>
			<?php
			gpante_support_diagnostics_values(
				array(
					'synthetic'         => $totals['synthetic'] ?? null,
					'unclassified'      => $totals['unclassified'] ?? null,
					'initializing'      => is_bool( $health['initializing'] ?? null ) ? ( $health['initializing'] ? 'بله' : 'خیر' ) : null,
					'enabled'           => is_bool( $budget['enabled'] ?? null ) ? ( $budget['enabled'] ? 'فعال' : 'غیرفعال' ) : null,
					'pricingValidUntil' => $budget['pricingValidUntil'] ?? null,
					'invalidIds'        => wp_json_encode( array_slice( array_filter( $health['invalidIds'] ?? array(), 'is_int' ), 0, 20 ) ),
				),
				array(
					'synthetic'         => 'آزمون‌های تأییدشده، ۷ روز',
					'unclassified'      => 'طبقه‌بندی‌نشده، ۷ روز',
					'initializing'      => 'آماده‌سازی نمایه',
					'enabled'           => 'فعال‌بودن سرویس در تنظیمات',
					'pricingValidUntil' => 'اعتبار تعرفه، UTC',
					'invalidIds'        => 'شناسهٔ اسناد نامعتبر، حداکثر۲۰',
				)
			);
			?>
		</details>
		<h2 id="gpante-diagnostic-records"><?php esc_html_e( 'گزارش نوبت‌ها، ۷ روز گذشته', 'gpante-support-chat' ); ?></h2>
		<p><?php esc_html_e( 'فیلترها فقط فهرست پایین را محدود می‌کنند؛ خلاصه‌های بالا کل هفت روز را نشان می‌دهند. جستجو روی اطلاعات تشخیصی است، نه متن گفتگو.', 'gpante-support-chat' ); ?></p>
		<form method="get" class="gpante-admin-card gpante-admin-filters"><input type="hidden" name="page" value="gpante-support-diagnostics">
			<label><?php esc_html_e( 'عبارت جستجو، شناسه یا کد خطا', 'gpante-support-chat' ); ?><input name="search" maxlength="100" value="<?php echo esc_attr( $filters['search'] ); ?>"></label>
			<label><?php esc_html_e( 'از تاریخ، UTC', 'gpante-support-chat' ); ?><input type="date" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>"></label>
			<label><?php esc_html_e( 'تا تاریخ، UTC', 'gpante-support-chat' ); ?><input type="date" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>"></label>
			<?php
			foreach ( array(
				'status'  => array(
					'label'  => 'وضعیت',
					'values' => array(
						''            => 'همه',
						'success'     => 'موفق',
						'error'       => 'خطا',
						'interrupted' => 'قطع‌شده',
						'pending'     => 'در حال پردازش',
						'legacy'      => 'قدیمی',
					),
				),
				'traffic' => array(
					'label'  => 'نوع ترافیک',
					'values' => array(
						''             => 'همه',
						'synthetic'    => 'آزمون تأییدشده',
						'unclassified' => 'طبقه‌بندی‌نشده',
					),
				),
				'source'  => array(
					'label'  => 'منبع',
					'values' => array(
						''           => 'همه',
						'products'   => 'محصولات',
						'articles'   => 'مقاله‌ها',
						'site'       => 'جستجوی سایت',
						'faq'        => 'پرسش‌های متداول',
						'guides'     => 'راهنماها',
						'categories' => 'دسته‌ها',
					),
				),
			) as $key => $field ) :
				?>
			<label><?php echo esc_html( $field['label'] ); ?><select name="<?php echo esc_attr( $key ); ?>">
						<?php foreach ( $field['values'] as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters[ $key ], $value ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?></select></label>
			<?php endforeach; ?>
			<button class="button button-primary"><?php esc_html_e( 'فیلتر گزارش', 'gpante-support-chat' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=gpante-support-diagnostics' ) ); ?>"><?php esc_html_e( 'پاک‌کردن فیلترها', 'gpante-support-chat' ); ?></a>
		</form>
		<?php
		if ( ! $valid ) {
			echo '<div class="notice notice-error"><p>' . esc_html( is_wp_error( $data ) ? $data->get_error_message() : __( 'اطلاعات تشخیصی معتبر دریافت نشد؛ نبود نتیجه تأیید نشده است.', 'gpante-support-chat' ) ) . '</p></div></div>';
			return;
		}
		if ( ( $page_no > 1 || $filtered ) && ( $data['page'] ?? null ) !== $page_no ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'بک‌اند، صفحه و فیلترهای گزارش را تأیید نکرد؛ نسخهٔ سرویس مدیریت را بررسی کنید.', 'gpante-support-chat' ) . '</p></div></div>';
			return;
		}
		foreach ( array_slice( $data['turns'], 0, 20 ) as $turn ) {
			if ( ! is_array( $turn ) ) {
				continue;
			}
			echo '<article class="postbox gpante-admin-card"><div class="gpante-admin-card__header">' . gpante_support_admin_badge( $turn['status'] ?? null ) . '<time>' . esc_html( gpante_support_admin_time( $turn['createdAt'] ?? null ) ) . '</time></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Badge helper escapes values.
			echo gpante_support_admin_reference( $turn['requestId'] ?? null ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Reference helper emits fixed escaped markup.
			echo '<p>' . esc_html( gpante_support_admin_label( $turn['trafficKind'] ?? null, 'traffic' ) ) . ' · ' . esc_html( gpante_support_admin_label( $turn['feedback'] ?? null, 'feedback' ) ) . '</p>';
			if ( ! empty( $turn['errorCode'] ) ) {
				echo '<p class="gpante-admin-warning">' . esc_html( gpante_support_admin_error_hint( $turn['errorCode'] ) ) . '</p>';
			}
			if ( is_string( $turn['sessionId'] ?? null ) && preg_match( '/^[a-f0-9]{64}$/D', $turn['sessionId'] ) ) {
				$args = array_merge(
					$filters,
					array(
						'page'        => 'gpante-support-conversations',
						'session'     => $turn['sessionId'],
						'return_view' => 'diagnostics',
						'return_page' => $page_no,
					)
				);
				echo '<p><a class="button" href="' . esc_url( add_query_arg( $args, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'مشاهدهٔ گفتگو', 'gpante-support-chat' ) . '</a></p>';
			}
			gpante_support_diagnostic_steps( is_array( $turn['events'] ?? null ) ? $turn['events'] : array() );
			echo '<details><summary>' . esc_html__( 'مشخصات فنی نوبت', 'gpante-support-chat' ) . '</summary>';
			gpante_support_diagnostics_values(
				$turn,
				array(
					'requestId'   => 'شناسهٔ کامل',
					'createdAt'   => 'زمان اصلی UTC',
					'status'      => 'وضعیت اصلی',
					'errorCode'   => 'کد خطا',
					'trafficKind' => 'نوع ترافیک اصلی',
					'feedback'    => 'بازخورد اصلی',
				)
			);
			echo '</details>';
			if ( 'unclassified' === ( $turn['trafficKind'] ?? null ) && is_string( $turn['requestId'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $turn['requestId'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gpante_support_synthetic"><input type="hidden" name="request_id" value="' . esc_attr( $turn['requestId'] ) . '">';
				wp_nonce_field( 'gpante_support_synthetic' );
				echo '<p>' . esc_html__( 'فقط نوبتی را که می‌شناسید آزمایشی علامت بزنید؛ طبقه‌بندی‌نشده به معنی کاربر واقعیِ تأییدشده نیست.', 'gpante-support-chat' ) . '</p>';
				submit_button( __( 'علامت‌گذاری به‌عنوان آزمون', 'gpante-support-chat' ), 'secondary', 'submit', false );
				echo '</form>';
			}
			echo '</article>';
		}
		if ( empty( $data['turns'] ) ) {
			echo '<p>' . esc_html__( 'گزارشی مطابق این فیلترها در بازهٔ هفت‌روزه وجود ندارد.', 'gpante-support-chat' ) . '</p>';
		}
		echo '<nav class="gpante-admin-pager" aria-label="' . esc_attr__( 'صفحه‌های گزارش', 'gpante-support-chat' ) . '">';
		$args = array_merge( $filters, array( 'page' => 'gpante-support-diagnostics' ) );
		if ( $page_no > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $args, array( 'diagnostic_page' => $page_no - 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'صفحهٔ قبل', 'gpante-support-chat' ) . '</a>';
		}
		echo '<span>' . esc_html( gpante_support_admin_number( $page_no ) ) . '</span>';
		if ( true === ( $data['hasMore'] ?? false ) && $page_no < 1000 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $args, array( 'diagnostic_page' => $page_no + 1 ) ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'صفحهٔ بعد', 'gpante-support-chat' ) . '</a>';
		}
		echo '</nav></div>';
}

/** Mark a known existing synthetic turn only with administrator authority. */
function gpante_support_mark_synthetic() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'دسترسی مجاز نیست.', 'gpante-support-chat' ), '', array( 'response' => 403 ) );
	}
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Exact method comparison rejects all other input without repair or output.
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		wp_die( esc_html__( 'روش درخواست معتبر نیست.', 'gpante-support-chat' ), '', array( 'response' => 405 ) );
	}
	check_admin_referer( 'gpante_support_synthetic' );
	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact hexadecimal validation below must reject, rather than repair, malformed identifiers.
	$request_id = isset( $_POST['request_id'] ) && is_string( $_POST['request_id'] ) ? wp_unslash( $_POST['request_id'] ) : '';
	if ( ! preg_match( '/^[a-f0-9]{32}$/D', $request_id ) ) {
		wp_die( esc_html__( 'شناسهٔ نوبت معتبر نیست.', 'gpante-support-chat' ), '', array( 'response' => 400 ) );
	}
	$result = gpante_support_admin_request(
		array(
			'operation' => 'markSynthetic',
			'requestId' => $request_id,
		)
	);
	if ( is_wp_error( $result ) || true !== ( $result['ok'] ?? null ) ) {
		wp_die( esc_html( is_wp_error( $result ) ? $result->get_error_message() : __( 'علامت‌گذاری تأیید نشد.', 'gpante-support-chat' ) ), '', array( 'response' => 502 ) );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=gpante-support-diagnostics' ) );
	exit;
}
add_action( 'admin_post_gpante_support_synthetic', 'gpante_support_mark_synthetic' );
