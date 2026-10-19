<?php
/**
 * Read-only "Seller account" box on a customer's edit screen.
 *
 * @var bool   $is_seller Whether this customer is also a seller (any status).
 * @var string $business  Business name.
 * @var string $status    Status label.
 * @var string $edit_url  Edit link of the seller record.
 */
?>
<?php if ( $is_seller ) : ?>
	<p><strong><?php esc_html_e( 'Business:', 'nexora' ); ?></strong> <?php echo esc_html( $business ); ?></p>
	<p><strong><?php esc_html_e( 'Status:', 'nexora' ); ?></strong> <?php echo esc_html( $status ); ?></p>
	<p><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Open the seller record', 'nexora' ); ?></a></p>
<?php else : ?>
	<p><?php esc_html_e( 'This customer is not a seller.', 'nexora' ); ?></p>
<?php endif; ?>
