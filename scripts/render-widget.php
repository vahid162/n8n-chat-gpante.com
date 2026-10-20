<?php
/** CLI-only renderer for the standalone embed; runtime uses the same template. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ );
function esc_html_e( $text, $domain ) { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $text, $domain ) { esc_html_e( $text, $domain ); }
function rest_url( $path ) { return '/wp-json/' . $path; }
function wp_make_link_relative( $path ) { return $path; }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
require dirname( __DIR__ ) . '/wordpress-plugin/gpante-support-chat/templates/widget.php';
