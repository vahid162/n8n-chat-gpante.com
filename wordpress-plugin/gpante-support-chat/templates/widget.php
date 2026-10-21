<?php
/**
 * Public Persian widget.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$appearance = gpante_support_get_appearance();
$styles     = '';
foreach ( $appearance['colors'] as $key => $color ) {
	$styles .= '--gpante-' . str_replace( '_', '-', $key ) . ':' . $color . ';';
}
$public_appearance = array(
	'version' => 1,
	'texts'   => $appearance['texts'],
	'colors'  => $appearance['colors'],
	'links'   => $appearance['links'],
);
?>
<div id="gpante-support" dir="rtl" lang="fa" style="<?php echo esc_attr( $styles ); ?>" data-appearance="<?php echo esc_attr( wp_json_encode( $public_appearance ) ); ?>" data-appearance-endpoint="<?php echo esc_url( wp_make_link_relative( rest_url( 'gpante-support/v1/appearance' ) ) ); ?>" data-endpoint="<?php echo esc_url( wp_make_link_relative( rest_url( 'gpante-support/v1/chat' ) ) ); ?>">
	<section id="gpante-support-panel" class="gpante-chat-panel" role="dialog" aria-labelledby="gpante-support-title" tabindex="-1" hidden>
		<div class="gpante-chat-header">
			<div class="gpante-chat-heading">
				<div class="gpante-chat-title-row">
					<strong id="gpante-support-title" data-title><?php echo esc_html( $appearance['texts']['title'] ); ?></strong>
					<span class="gpante-chat-status" data-status data-state="connecting" role="status" aria-live="polite" aria-atomic="true">
						<span class="gpante-chat-status-icon" aria-hidden="true"></span><span data-status-label lang="en" dir="ltr">Connecting</span>
					</span>
				</div>
				<span data-subtitle><?php echo esc_html( $appearance['texts']['subtitle'] ); ?></span>
			</div>
			<button type="button" data-close aria-label="<?php esc_attr_e( 'بستن گفت‌وگو', 'gpante-support-chat' ); ?>">×</button>
		</div>
		<div class="gpante-chat-toolbar"><button type="button" data-new hidden><?php echo esc_html( $appearance['texts']['clear'] ); ?></button></div>
		<div class="gpante-chat-confirm" data-confirm hidden>
			<p><?php esc_html_e( 'این گفتگو از تاریخچهٔ قابل مشاهدهٔ شما پاک می‌شود و گفتگوی تازه‌ای آغاز خواهد شد. آرشیو مدیریتی آن برای بررسی فنی، تا پایان مهلت ۳۰روزه باقی می‌ماند. ادامه می‌دهید؟', 'gpante-support-chat' ); ?></p>
			<button type="button" data-clear><?php esc_html_e( 'بله، پاک شود', 'gpante-support-chat' ); ?></button><button type="button" data-cancel><?php esc_html_e( 'انصراف', 'gpante-support-chat' ); ?></button>
		</div>
		<div class="gpante-chat-messages" data-scroll tabindex="0" role="region" aria-label="<?php esc_attr_e( 'گفتگو و پیوندهای راهنما', 'gpante-support-chat' ); ?>">
			<div data-messages role="log" aria-label="<?php esc_attr_e( 'پیام‌های گفت‌وگو', 'gpante-support-chat' ); ?>" aria-live="polite" aria-relevant="additions"></div>
			<div class="gpante-chat-activity" data-activity role="status" aria-live="polite" hidden><span class="gpante-chat-dots" aria-hidden="true"><i></i><i></i><i></i></span><span data-activity-text></span></div>
			<div class="gpante-chat-notice" data-notice hidden>
				<p data-status-text role="status" aria-live="polite" aria-atomic="true"></p>
				<button type="button" data-reconnect hidden><?php esc_html_e( 'اتصال مجدد', 'gpante-support-chat' ); ?></button>
				<button type="button" data-retry hidden><?php esc_html_e( 'تلاش دوباره', 'gpante-support-chat' ); ?></button>
			</div>
			<nav class="gpante-chat-shortcuts" data-shortcuts aria-label="<?php esc_attr_e( 'پیوندهای راهنما', 'gpante-support-chat' ); ?>" <?php echo array_filter( $appearance['links'], static fn( $shortcut ) => '' !== $shortcut['label'] ) ? '' : 'hidden'; ?>>
				<?php foreach ( $appearance['links'] as $shortcut ) : ?>
					<?php if ( '' !== $shortcut['label'] ) : ?>
						<a href="<?php echo esc_url( $shortcut['url'] ); ?>"><?php echo esc_html( $shortcut['label'] ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</nav>
		</div>
		<form class="gpante-chat-form" data-form>
			<label class="gpante-chat-sr-only" for="gpante-support-input" data-input-label><?php echo esc_html( $appearance['texts']['input_label'] ); ?></label>
			<div class="gpante-chat-composer">
				<textarea id="gpante-support-input" data-input rows="2" maxlength="4000" placeholder="<?php echo esc_attr( $appearance['texts']['placeholder'] ); ?>" required></textarea>
				<button type="submit" data-send disabled aria-label="<?php echo esc_attr( $appearance['texts']['send'] ); ?>" title="<?php echo esc_attr( $appearance['texts']['send'] ); ?>"><span class="gpante-chat-sr-only" data-send-label><?php echo esc_html( $appearance['texts']['send'] ); ?></span><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m22 2-7 20-4-9L2 9Z"/><path d="m22 2-11 11"/></svg></button>
			</div>
			<small class="gpante-chat-count" data-count aria-hidden="true"><?php esc_html_e( '۰ / ۴۰۰۰', 'gpante-support-chat' ); ?></small>
		</form>
	</section>
	<button class="gpante-chat-launcher" type="button" data-launcher aria-label="<?php echo esc_attr( $appearance['texts']['launcher'] ); ?>" aria-controls="gpante-support-panel" aria-expanded="false">
		<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 2v-10A8.5 8.5 0 0 1 9.5 3H12a8 8 0 0 1 8 8.5Z"/><path d="M6 9h9M6 13h6"/></svg>
		<span data-launcher-label><?php echo esc_html( $appearance['texts']['launcher'] ); ?></span>
	</button>
</div>
