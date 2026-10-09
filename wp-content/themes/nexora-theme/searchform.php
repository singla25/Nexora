<?php
/**
 * Search form.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<form role="search" method="get" class="nxt-search-form" action="<?php echo esc_url( home_url( '/' ) ); ?>">
	<label class="screen-reader-text" for="nxt-search-field"><?php esc_html_e( 'Search for:', 'nexora-theme' ); ?></label>
	<input type="search" id="nxt-search-field" class="nxt-search-field" placeholder="<?php esc_attr_e( 'Search...', 'nexora-theme' ); ?>" value="<?php echo esc_attr( get_search_query() ); ?>" name="s" />
	<button type="submit" class="nxt-btn nxt-btn--secondary"><?php esc_html_e( 'Search', 'nexora-theme' ); ?></button>
</form>
