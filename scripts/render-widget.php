<?php
/** CLI-only renderer for the standalone embed; runtime uses the same template. */
if ( 'cli' !== PHP_SAPI ) { exit; }
define( 'ABSPATH', __DIR__ );
function esc_html_e( $text, $domain ) { echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr_e( $text, $domain ) { esc_html_e( $text, $domain ); }
function rest_url( $path ) { return '/wp-json/' . $path; }
function wp_make_link_relative( $path ) { return $path; }
function esc_url( $url ) { return htmlspecialchars( $url, ENT_QUOTES, 'UTF-8' ); }
function __( $text, $domain ) { return $text; }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_html( $text ) { return esc_attr( $text ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_text_field( $text ) { return $text; }
function sanitize_textarea_field( $text ) { return $text; }
function add_action( ...$args ) {}
function get_option( $name, $default ) { return $default; }
require dirname( __DIR__ ) . '/wordpress-plugin/gpante-support-chat/includes/appearance.php';
require dirname( __DIR__ ) . '/wordpress-plugin/gpante-support-chat/templates/widget.php';
