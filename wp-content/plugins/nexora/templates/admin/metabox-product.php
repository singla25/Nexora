<?php
/**
 * Read-only product details for administrators.
 *
 * @var array  $data     Product data.
 * @var string $category Category label.
 * @var string $seller   Seller's business name.
 * @var string $account  Seller's username.
 * @var string $price    Formatted price.
 * @var string $status   Post status.
 */
?>
<table class="form-table" role="presentation">
	<tr><th><?php esc_html_e( 'Seller', 'nexora' ); ?></th><td><?php echo esc_html( $seller ); ?> (<?php echo esc_html( $account ); ?>)</td></tr>
	<tr><th><?php esc_html_e( 'Status', 'nexora' ); ?></th><td><?php echo esc_html( $status ); ?></td></tr>
	<tr><th><?php esc_html_e( 'SKU', 'nexora' ); ?></th><td><?php echo esc_html( $data['sku'] ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Price', 'nexora' ); ?></th><td><?php echo esc_html( $price ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Stock', 'nexora' ); ?></th><td><?php echo esc_html( (string) $data['stock'] ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Category', 'nexora' ); ?></th><td><?php echo esc_html( $category ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Source', 'nexora' ); ?></th><td><?php echo esc_html( $data['source'] ); ?></td></tr>
	<tr><th><?php esc_html_e( 'Description', 'nexora' ); ?></th><td><?php echo esc_html( $data['description'] ); ?></td></tr>
</table>
