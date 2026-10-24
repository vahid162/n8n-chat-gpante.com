<?php
/**
 * Administrator-only source health and bounded search diagnostics.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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

/** Render current source health and the latest bounded diagnostic records. */
function gpante_support_diagnostics_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	nocache_headers();
	$health = gpante_support_search_index_health();
	$data   = gpante_support_admin_request( array( 'operation' => 'getDiagnostics' ) );
	echo '<div class="wrap" dir="rtl"><h1>' . esc_html__( 'کیفیت پاسخ و سلامت منابع', 'gpante-support-chat' ) . '</h1>';
	echo '<p>' . esc_html__( 'این صفحه بدون تماس با مدل، سلامت منابع و اطلاعات تشخیصی محدود را نشان می‌دهد. رویدادهای جست‌وجو حداکثر هفت روز نگهداری می‌شوند؛ متن گفتگو و محتوای کامل منابع در این صفحه نمایش داده نمی‌شود. زمان‌ها UTC هستند.', 'gpante-support-chat' ) . '</p>';
	echo '<h2>' . esc_html__( 'نمایهٔ عمومی سایت', 'gpante-support-chat' ) . '</h2>';
	gpante_support_diagnostics_values(
		array_merge(
			$health,
			array(
				'complete'     => true === ( $health['complete'] ?? null ) ? __( 'کامل', 'gpante-support-chat' ) : __( 'ناقص', 'gpante-support-chat' ),
				'initializing' => true === ( $health['initializing'] ?? null ) ? __( 'بله', 'gpante-support-chat' ) : __( 'خیر', 'gpante-support-chat' ),
				'invalidIds'   => wp_json_encode( array_slice( array_filter( $health['invalidIds'] ?? array(), 'is_int' ), 0, 20 ) ),
			)
		),
		array(
			'complete'         => __( 'پوشش منابع', 'gpante-support-chat' ),
			'initializing'     => __( 'در حال آماده‌سازی', 'gpante-support-chat' ),
			'documents'        => __( 'اسناد نمایه‌شده', 'gpante-support-chat' ),
			'invalidDocuments' => __( 'اسناد نامعتبر', 'gpante-support-chat' ),
			'invalidIds'       => __( 'شناسهٔ اسناد نامعتبر، حداکثر ۲۰ مورد', 'gpante-support-chat' ),
			'reconciledAt'     => __( 'آخرین بازبینی پوشش', 'gpante-support-chat' ),
		)
	);
	if ( is_wp_error( $data ) || ! is_array( $data['summary'] ?? null ) || ! is_array( $data['budget'] ?? null ) || ! is_array( $data['turns'] ?? null ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( is_wp_error( $data ) ? $data->get_error_message() : __( 'اطلاعات تشخیصی معتبر دریافت نشد.', 'gpante-support-chat' ) ) . '</p></div></div>';
		return;
	}
	echo '<h2>' . esc_html__( 'خلاصهٔ گفتگوهای قابل بررسی', 'gpante-support-chat' ) . '</h2>';
	gpante_support_diagnostics_values(
		$data['summary'],
		array(
			'total'        => __( 'کل نوبت‌ها', 'gpante-support-chat' ),
			'synthetic'    => __( 'آزمایشیِ تأییدشده توسط مدیر', 'gpante-support-chat' ),
			'unclassified' => __( 'طبقه‌بندی‌نشده', 'gpante-support-chat' ),
			'errors'       => __( 'خطاها', 'gpante-support-chat' ),
			'helpful'      => __( 'بازخورد مفید', 'gpante-support-chat' ),
			'unhelpful'    => __( 'بازخورد نامفید', 'gpante-support-chat' ),
		)
	);
	echo '<h2>' . esc_html__( 'بودجه و ظرفیت', 'gpante-support-chat' ) . '</h2>';
	gpante_support_diagnostics_values(
		array_merge( $data['budget'], array( 'enabled' => true === ( $data['budget']['enabled'] ?? null ) ? __( 'فعال', 'gpante-support-chat' ) : __( 'غیرفعال', 'gpante-support-chat' ) ) ),
		array(
			'enabled'           => __( 'سرویس', 'gpante-support-chat' ),
			'monthlyBudgetNano' => __( 'سقف ماهانه، نانودلار', 'gpante-support-chat' ),
			'usedNano'          => __( 'مصرف محافظه‌کارانه، نانودلار', 'gpante-support-chat' ),
			'pricingValidUntil' => __( 'اعتبار تعرفه', 'gpante-support-chat' ),
			'activeLeases'      => __( 'گفتگوهای در حال پردازش', 'gpante-support-chat' ),
		)
	);
	echo '<h2>' . esc_html__( 'آخرین نوبت‌ها، حداکثر ۲۰ مورد', 'gpante-support-chat' ) . '</h2>';
	foreach ( array_slice( $data['turns'], 0, 20 ) as $turn ) {
		if ( ! is_array( $turn ) ) {
			continue;
		}
		echo '<div class="postbox" style="padding:16px">';
		gpante_support_diagnostics_values(
			$turn,
			array(
				'requestId'   => __( 'شناسهٔ نوبت', 'gpante-support-chat' ),
				'createdAt'   => __( 'زمان', 'gpante-support-chat' ),
				'status'      => __( 'وضعیت', 'gpante-support-chat' ),
				'errorCode'   => __( 'کد خطا', 'gpante-support-chat' ),
				'trafficKind' => __( 'نوع ترافیک', 'gpante-support-chat' ),
				'feedback'    => __( 'بازخورد', 'gpante-support-chat' ),
			)
		);
		$events = is_array( $turn['events'] ?? null ) ? array_slice( $turn['events'], 0, 12 ) : array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
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
			if ( $metadata ) {
				echo '<pre dir="ltr" style="white-space:pre-wrap;overflow-wrap:anywhere">' . esc_html( wp_json_encode( $metadata ) ) . '</pre>';
			}
		}
		if ( 'unclassified' === ( $turn['trafficKind'] ?? null ) && is_string( $turn['requestId'] ?? null ) && preg_match( '/^[a-f0-9]{32}$/D', $turn['requestId'] ) ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="gpante_support_synthetic"><input type="hidden" name="request_id" value="' . esc_attr( $turn['requestId'] ) . '">';
			wp_nonce_field( 'gpante_support_synthetic' );
			echo '<p>' . esc_html__( 'فقط اگر این نوبت را به‌عنوان آزمون می‌شناسید، آن را آزمایشی علامت بزنید؛ طبقه‌بندی‌نشده به معنی بازدیدکنندهٔ واقعیِ تأییدشده نیست.', 'gpante-support-chat' ) . '</p>';
			submit_button( __( 'علامت‌گذاری به‌عنوان آزمون', 'gpante-support-chat' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}
	if ( empty( $data['turns'] ) ) {
		echo '<p>' . esc_html__( 'نوبتی در بازهٔ نگهداری موجود نیست.', 'gpante-support-chat' ) . '</p>';
	}
	echo '</div>';
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
