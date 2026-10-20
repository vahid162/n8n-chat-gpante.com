<?php
/**
 * Public Persian widget.
 *
 * @package GPanteSupportChat
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="gpante-support" dir="rtl" lang="fa" data-endpoint="<?php echo esc_url( wp_make_link_relative( rest_url( 'gpante-support/v1/chat' ) ) ); ?>">
	<section id="gpante-support-panel" class="gpante-chat-panel" role="dialog" aria-labelledby="gpante-support-title" tabindex="-1" hidden>
		<header class="gpante-chat-header">
			<div><strong id="gpante-support-title"><?php esc_html_e( 'دستیار پانته', 'gpante-support-chat' ); ?></strong><span><?php esc_html_e( 'راهنمای طرح و فایل، خرید و دانلود', 'gpante-support-chat' ); ?></span></div>
			<button type="button" data-close aria-label="<?php esc_attr_e( 'بستن گفت‌وگو', 'gpante-support-chat' ); ?>">×</button>
		</header>
		<div class="gpante-chat-intro" data-policy><?php esc_html_e( 'دستیار هوش مصنوعی برای اطلاعات عمومی محصولات و راهنماهاست. به حساب یا سفارش شما دسترسی ندارد. هنگام بازشدن گفتگو، سیاست نگهداری نمایش داده می‌شود. آرشیو مدیریتی پیام‌ها برای بررسی فنی تا ۳۰ روز باقی می‌ماند.', 'gpante-support-chat' ); ?></div>
		<div class="gpante-chat-toolbar"><button type="button" data-new hidden><?php esc_html_e( 'پاک‌کردن و گفت‌وگوی تازه', 'gpante-support-chat' ); ?></button></div>
		<div class="gpante-chat-confirm" data-confirm hidden>
			<p><?php esc_html_e( 'این گفتگو از تاریخچهٔ قابل مشاهدهٔ شما پاک می‌شود و گفتگوی تازه‌ای آغاز خواهد شد. آرشیو مدیریتی آن برای بررسی فنی، تا پایان مهلت ۳۰روزه باقی می‌ماند. ادامه می‌دهید؟', 'gpante-support-chat' ); ?></p>
			<button type="button" data-clear><?php esc_html_e( 'بله، پاک شود', 'gpante-support-chat' ); ?></button><button type="button" data-cancel><?php esc_html_e( 'انصراف', 'gpante-support-chat' ); ?></button>
		</div>
		<div class="gpante-chat-messages" data-messages role="log" aria-label="<?php esc_attr_e( 'پیام‌های گفت‌وگو', 'gpante-support-chat' ); ?>" aria-live="polite" aria-relevant="additions" tabindex="0"></div>
		<div class="gpante-chat-status" data-status role="status" aria-live="polite" aria-atomic="true"></div>
		<button class="gpante-chat-retry" type="button" data-retry hidden><?php esc_html_e( 'تلاش دوباره', 'gpante-support-chat' ); ?></button>
		<form class="gpante-chat-form" data-form>
			<label for="gpante-support-input"><?php esc_html_e( 'پرسش شما', 'gpante-support-chat' ); ?></label>
			<textarea id="gpante-support-input" data-input rows="2" maxlength="4000" placeholder="<?php esc_attr_e( 'مثلاً باکس MDF با ضخامت ۲٫۷ و فایل DXF می‌خواهم…', 'gpante-support-chat' ); ?>" required></textarea>
			<div class="gpante-chat-compose"><small data-count aria-hidden="true"><?php esc_html_e( '۰ / ۴۰۰۰', 'gpante-support-chat' ); ?></small><button type="submit" data-send disabled><?php esc_html_e( 'ارسال پیام', 'gpante-support-chat' ); ?></button></div>
		</form>
	</section>
	<button class="gpante-chat-launcher" type="button" data-launcher aria-label="<?php esc_attr_e( 'باز کردن دستیار هوش مصنوعی پانته', 'gpante-support-chat' ); ?>" aria-controls="gpante-support-panel" aria-expanded="false">
		<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20 11.5a8.5 8.5 0 0 1-8.5 8.5H4l-3 2v-10A8.5 8.5 0 0 1 9.5 3H12a8 8 0 0 1 8 8.5Z"/><path d="M6 9h9M6 13h6"/></svg>
		<span><?php esc_html_e( 'از پانته بپرسید', 'gpante-support-chat' ); ?></span>
	</button>
</div>
