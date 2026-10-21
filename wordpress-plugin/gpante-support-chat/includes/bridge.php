<?php
/**
 * Same-origin chat bridge and widget hooks.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/catalog.php';

/** Return valid enabled server settings, or false for unavailable configurations. */
function gpante_support_runtime() {
	$config = get_option( 'gpante_support_runtime', array() );
	if ( ! is_array( $config ) || (int) ( $config['schema_version'] ?? 0 ) > GPANTE_SUPPORT_SCHEMA_VERSION || ( $config['enabled'] ?? false ) !== true
		|| ( $config['backend_url'] ?? '' ) !== GPANTE_SUPPORT_BACKEND
		|| ! is_string( $config['shared_key'] ?? null )
		|| ! preg_match( '/^[\x21-\x7e]{32,256}$/D', $config['shared_key'] ) ) {
		return false;
	}
	return $config;
}

/**
 * Validate the public central-policy contract without accepting extra backend fields.
 *
 * @param mixed $policy Candidate.
 * @return bool
 */
function gpante_support_valid_policy( $policy ) {
	return is_array( $policy ) && 2 === ( $policy['version'] ?? null ) && 30 === ( $policy['archiveDays'] ?? null )
		&& in_array( $policy['userDays'] ?? null, array( 3, 7, 15, 30 ), true )
		&& is_bool( $policy['allowClear'] ?? null ) && is_int( $policy['revision'] ?? null ) && $policy['revision'] > 0;
}

/**
 * Return only the allowlisted policy fields.
 *
 * @param array $policy Validated policy.
 * @return array
 */
function gpante_support_public_policy( $policy ) {
	return array_intersect_key( $policy, array_flip( array( 'version', 'archiveDays', 'userDays', 'allowClear', 'revision' ) ) );
}

/**
 * Validate a bounded ISO expiry supplied by the trusted service.
 *
 * @param mixed $value Candidate timestamp.
 * @return bool
 */
function gpante_support_valid_expiry( $value ) {
	return is_string( $value ) && strlen( $value ) <= 40 && preg_match( '/^\d{4}-\d{2}-\d{2}T[0-9:.]+(?:Z|[+-]\d{2}:\d{2})$/D', $value ) && false !== strtotime( $value );
}

/**
 * Build a non-cacheable public response.
 *
 * @param mixed $data Public response body.
 * @param int   $status HTTP status.
 * @param array $headers Additional response headers.
 * @return WP_REST_Response
 */
function gpante_support_response( $data, $status = 200, $headers = array() ) {
	return new WP_REST_Response(
		$data,
		$status,
		array_merge(
			array(
				'Cache-Control'          => 'no-store, private',
				'Vary'                   => 'Origin, Cookie',
				'X-Content-Type-Options' => 'nosniff',
			),
			$headers
		)
	);
}

/**
 * Return a bounded public error without internal service details.
 *
 * @param int    $status HTTP status.
 * @param string $code Public error code.
 * @param array  $headers Additional response headers.
 * @return WP_REST_Response
 */
function gpante_support_failure( $status, $code, $headers = array() ) {
	$messages = array(
		400 => __( 'درخواست معتبر نیست. پرسشتان را با حداکثر ۴۰۰۰ نویسه بنویسید.', 'gpante-support-chat' ),
		403 => __( 'برای گفت‌وگو، صفحهٔ سایت پانته را دوباره باز کنید.', 'gpante-support-chat' ),
		413 => __( 'حجم پیام بیش از حد مجاز است. لطفاً متن کوتاه‌تری بنویسید.', 'gpante-support-chat' ),
		415 => __( 'قالب درخواست معتبر نیست. لطفاً صفحه را تازه کنید.', 'gpante-support-chat' ),
	);
	if ( 'monthly_budget' === $code ) {
		$messages[ $status ] = __( 'سهمیهٔ پاسخ‌گویی دستیار برای این ماه به پایان رسیده است. می‌توانید از پیوندهای راهنمای فروشگاه استفاده کنید؛ تلاش دوباره در همین لحظه این محدودیت را برطرف نمی‌کند.', 'gpante-support-chat' );
	} elseif ( 'pricing_review' === $code ) {
		$messages[ $status ] = __( 'پاسخ‌گویی دستیار موقتاً در دسترس نیست و به بررسی مدیر سایت نیاز دارد. فعلاً از پیوندهای راهنمای فروشگاه استفاده کنید.', 'gpante-support-chat' );
	}
	if ( 429 === $status ) {
		$reasons       = array(
			'rate_limit'   => __( 'در مدت کوتاهی چند درخواست از این اتصال دریافت شده است.', 'gpante-support-chat' ),
			'session_busy' => __( 'یک درخواست دیگر در همین گفتگو هنوز در حال انجام است.', 'gpante-support-chat' ),
			'capacity'     => __( 'دستیار فعلاً مشغول پاسخ‌گویی به کاربران دیگر است.', 'gpante-support-chat' ),
		);
		$messages[429] = $reasons[ $code ] ?? __( 'فعلاً امکان انجام درخواست دیگری نیست.', 'gpante-support-chat' );
		$seconds       = (int) ( $headers['Retry-After'] ?? 0 );
		if ( $seconds > 0 ) {
			/* translators: %s: Number of seconds before retrying. */
			$wait = __( '%s ثانیه صبر کنید، سپس روی «تلاش دوباره» بزنید.', 'gpante-support-chat' );
			if ( $seconds >= 3600 ) {
				/* translators: %s: Number of hours before retrying. */
				$wait    = __( '%s ساعت صبر کنید، سپس روی «تلاش دوباره» بزنید.', 'gpante-support-chat' );
				$seconds = (int) ceil( $seconds / 3600 );
			} elseif ( $seconds >= 60 ) {
				/* translators: %s: Number of minutes before retrying. */
				$wait    = __( '%s دقیقه صبر کنید، سپس روی «تلاش دوباره» بزنید.', 'gpante-support-chat' );
				$seconds = (int) ceil( $seconds / 60 );
			}
			$number         = str_replace( range( 0, 9 ), array( '۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹' ), (string) $seconds );
			$messages[429] .= ' ' . sprintf( $wait, $number );
		} else {
			$messages[429] .= ' ' . __( 'زمان دقیق رفع این وضعیت مشخص نیست؛ کمی بعد روی «تلاش دوباره» بزنید.', 'gpante-support-chat' );
		}
	}
	return gpante_support_response(
		array(
			'error'  => array( 'code' => $code ),
			'output' => $messages[ $status ] ?? 'دستیار در حال حاضر در دسترس نیست. لطفاً کمی بعد دوباره تلاش کنید.',
		),
		$status,
		$headers
	);
}

/**
 * Read or mint a signed host-only session cookie.
 *
 * @return string|false
 */
function gpante_support_session_id() {
	$now    = time();
	$cookie = isset( $_COOKIE[ GPANTE_SUPPORT_COOKIE ] ) && is_string( $_COOKIE[ GPANTE_SUPPORT_COOKIE ] )
		? wp_unslash( $_COOKIE[ GPANTE_SUPPORT_COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict format and HMAC validation below must not mutate signed bytes.
	$parts  = explode( '.', $cookie );
	$valid  = 4 === count( $parts ) && 'v1' === $parts[0]
		&& preg_match( '/^[0-9]{10}$/D', $parts[1] )
		&& (int) $parts[1] <= $now && (int) $parts[1] > $now - 30 * DAY_IN_SECONDS
		&& preg_match( '/^[A-Za-z0-9_-]{43}$/D', $parts[2] )
		&& preg_match( '/^[a-f0-9]{64}$/D', $parts[3] );
	if ( $valid ) {
		$valid = hash_equals( hash_hmac( 'sha256', 'gpante-support-cookie|' . implode( '.', array_slice( $parts, 0, 3 ) ), wp_salt( 'auth' ) ), $parts[3] );
	}
	if ( ! $valid ) {
		try {
			// Encode cryptographic random bytes in a cookie-safe format.
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		} catch ( Exception $error ) {
			return false;
		}
		$value  = 'v1.' . $now . '.' . $token;
		$cookie = $value . '.' . hash_hmac( 'sha256', 'gpante-support-cookie|' . $value, wp_salt( 'auth' ) );
		if ( headers_sent() || ! setcookie(
			GPANTE_SUPPORT_COOKIE,
			$cookie,
			array(
				'expires'  => $now + 30 * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => true,
				'httponly' => true,
				'samesite' => 'Strict',
			)
		) ) {
			return false;
		}
	} else {
		$token = $parts[2];
	}
	return hash_hmac( 'sha256', 'gpante-support-session|' . $token, wp_salt( 'auth' ) );
}

/** Return the exact HTTPS origin from trusted WordPress configuration. */
function gpante_support_origin() {
	$parts = wp_parse_url( home_url( '/' ) );
	if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) || empty( $parts['host'] ) ) {
		return '';
	}
	return 'https://' . $parts['host'] . ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '' );
}

/**
 * Validate a same-origin public action and call the fixed trusted backend.
 *
 * @param WP_REST_Request $request Incoming REST request.
 * @return WP_REST_Response
 */
function gpante_support_chat( $request ) {
	// Public endpoint: browser identity comes only from our signed, host-only cookie.
	if ( '' === gpante_support_origin() || ! is_ssl() || $request->get_header( 'origin' ) !== gpante_support_origin()
		|| 'same-origin' !== $request->get_header( 'sec-fetch-site' ) ) {
		return gpante_support_failure( 403, 'origin_rejected' );
	}
	$config = gpante_support_runtime();
	if ( ! $config ) {
		return gpante_support_failure( 503, 'unavailable' );
	}
	if ( ! preg_match( '/^application\/json(?:\s*;\s*charset=utf-8)?$/iD', $request->get_header( 'content-type' ) ) ) {
		return gpante_support_failure( 415, 'json_required' );
	}
	$body = $request->get_body();
	if ( strlen( $body ) > 32768 ) {
		return gpante_support_failure( 413, 'body_too_large' );
	}
	$input = json_decode( $body, true );
	if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $input )
		|| ! in_array( $input['action'] ?? null, array( 'sendMessage', 'loadPreviousSession', 'resetSession' ), true ) ) {
		return gpante_support_failure( 400, 'invalid_input' );
	}
	$action = $input['action'];
	if ( 'loadPreviousSession' !== $action && 2 !== ( $input['policyVersion'] ?? null ) ) {
		return gpante_support_failure( 403, 'client_upgrade' );
	}
	$text = is_string( $input['chatInput'] ?? null ) ? trim( $input['chatInput'] ) : '';
	if ( 'sendMessage' === $action && ( '' === $text || preg_match_all( '/./us', $text ) > 4000 ) ) {
		return gpante_support_failure( 400, 'invalid_input' );
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		return gpante_support_failure( 503, 'unavailable' );
	}
	$session = gpante_support_session_id();
	if ( ! $session ) {
		return gpante_support_failure( 503, 'unavailable' );
	}
	$payload = array(
		'action'        => $action,
		'policyVersion' => 2,
		'sessionId'     => $session,
		'clientId'      => hash_hmac( 'sha256', 'gpante-support-client|' . inet_pton( $ip ), wp_salt( 'auth' ) ),
	);
	if ( 'sendMessage' === $action ) {
		$payload['chatInput'] = $text;
	}
	$result = wp_remote_post(
		GPANTE_SUPPORT_BACKEND,
		array(
			'headers'             => array(
				'Content-Type'      => 'application/json',
				'Accept'            => 'application/json',
				'X-Gpante-Chat-Key' => $config['shared_key'],
			),
			'body'                => wp_json_encode( $payload ),
			'timeout'             => 155,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 131072,
		)
	);
	if ( is_wp_error( $result ) ) {
		return gpante_support_failure( 502, 'backend_unavailable' );
	}
	$status = (int) wp_remote_retrieve_response_code( $result );
	$data   = json_decode( wp_remote_retrieve_body( $result ), true );
	if ( $status < 200 || $status >= 300 || ! is_array( $data ) || isset( $data['error'] ) ) {
		$headers = array();
		$retry   = wp_remote_retrieve_header( $result, 'retry-after' );
		if ( 429 === $status && is_string( $retry ) && preg_match( '/^[0-9]{1,6}$/D', $retry ) ) {
			$headers['Retry-After'] = (string) max( 1, min( 86400, (int) $retry ) );
		}
		$code = 429 === $status ? 'request_limit' : 'backend_unavailable';
		if ( 429 === $status && is_array( $data['error'] ?? null ) && in_array( $data['error']['code'] ?? null, array( 'rate_limit', 'session_busy', 'capacity' ), true ) ) {
			$code = $data['error']['code'];
		}
		if ( 503 === $status && is_array( $data['error'] ?? null ) && in_array( $data['error']['code'] ?? null, array( 'monthly_budget', 'pricing_review' ), true ) ) {
			$code = $data['error']['code'];
		}
		return gpante_support_failure( 429 === $status ? 429 : ( 403 === $status ? 403 : 502 ), $code, $headers );
	}
	if ( ! gpante_support_valid_policy( $data['policy'] ?? null ) ) {
		return gpante_support_failure( 502, 'invalid_policy' );
	}
	$policy   = gpante_support_public_policy( $data['policy'] );
	$previews = array();
	if ( 'resetSession' === $action && true === ( $data['ok'] ?? false ) ) {
		return gpante_support_response(
			array(
				'ok'     => true,
				'policy' => $policy,
			)
		);
	}
	if ( 'sendMessage' === $action && is_string( $data['output'] ?? null ) && '' !== trim( $data['output'] ) && strlen( $data['output'] ) <= 32000 && gpante_support_valid_expiry( $data['expiresAt'] ?? null ) ) {
		return gpante_support_response(
			array(
				'output'    => $data['output'],
				'products'  => gpante_support_reply_products( $data['output'], $previews ),
				'policy'    => $policy,
				'expiresAt' => $data['expiresAt'],
			)
		);
	}
	if ( 'loadPreviousSession' === $action && isset( $data['messages'] ) && is_array( $data['messages'] ) && count( $data['messages'] ) <= 100 ) {
		$messages = array();
		foreach ( $data['messages'] as $message ) {
			if ( ! is_array( $message ) || ! in_array( $message['role'] ?? null, array( 'user', 'assistant' ), true )
				|| ! is_string( $message['content'] ?? null ) || strlen( $message['content'] ) > 32000 || ! gpante_support_valid_expiry( $message['expiresAt'] ?? null ) ) {
				return gpante_support_failure( 502, 'invalid_backend_response' );
			}
			$messages[] = array(
				'role'      => $message['role'],
				'content'   => $message['content'],
				'products'  => 'assistant' === $message['role'] ? gpante_support_reply_products( $message['content'], $previews ) : array(),
				'expiresAt' => $message['expiresAt'],
			);
		}
		return gpante_support_response(
			array(
				'messages' => $messages,
				'policy'   => $policy,
			)
		);
	}
	return gpante_support_failure( 502, 'invalid_backend_response' );
}

add_action(
	'rest_api_init',
	function () {
		register_rest_route(
			'gpante-support/v1',
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => 'gpante_support_chat',
				'permission_callback' => '__return_true',
			)
		);
		gpante_support_register_catalog();
	}
);

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! gpante_support_runtime() || ( defined( 'GPANTE_SUPPORT_INLINE_WIDGET' ) && GPANTE_SUPPORT_INLINE_WIDGET ) ) {
			return;
		}
		wp_enqueue_style( 'gpante-support-chat', GPANTE_SUPPORT_URL . 'assets/chat.css', array(), GPANTE_SUPPORT_VERSION );
		wp_enqueue_script( 'gpante-support-chat', GPANTE_SUPPORT_URL . 'assets/chat.js', array(), GPANTE_SUPPORT_VERSION, true );
	}
);

add_action(
	'wp_footer',
	function () {
		if ( ! gpante_support_runtime() || ( defined( 'GPANTE_SUPPORT_INLINE_WIDGET' ) && GPANTE_SUPPORT_INLINE_WIDGET ) ) {
			return;
		}
		require GPANTE_SUPPORT_DIR . 'templates/widget.php';
	}
);
