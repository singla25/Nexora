<?php
/**
 * Read-only details of a vendor's saved API connection (administrators). The key is never shown.
 *
 * @var string $name    Connection name.
 * @var string $vendor  The vendor's business name.
 * @var string $address The address.
 * @var string $auth    The kind of authentication, in words.
 * @var string $last    The last import.
 */
?>
<table class="form-table" role="presentation">
	<tr><th><?php esc_html_e( 'Connection', 'nexora' ); ?></th><td><?php echo esc_html( $name ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Vendor', 'nexora' ); ?></th><td><?php echo esc_html( $vendor ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Address', 'nexora' ); ?></th><td><?php echo esc_html( $address ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Authentication', 'nexora' ); ?></th><td><?php echo esc_html( $auth ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Last import', 'nexora' ); ?></th><td><?php echo esc_html( $last ); ?></td></tr>
</table>
