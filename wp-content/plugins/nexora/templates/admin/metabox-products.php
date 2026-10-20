<?php /** @var array[] $rows  id, edit_url, name, sku, price, stock; @var string $links  pagination (built by paginate_links); @var int $total */ ?>
<?php if ( ! $rows ) : ?>
		<p><?php esc_html_e( 'No products yet', 'nexora' ); ?></p>
<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product ID', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Name', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'SKU', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Price', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Items in stock', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>
	<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( $row['edit_url'] ); ?>"><?php echo esc_html( $row['id'] ); ?></a></td>
					<td><?php echo esc_html( $row['name'] ); ?></td>
					<td><?php echo esc_html( $row['sku'] ); ?></td>
					<td><?php echo esc_html( $row['price'] ); ?></td>
					<td><?php echo esc_html( $row['stock'] ); ?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	<?php if ( '' !== $links ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num"><?php echo esc_html( sprintf( /* translators: %d: number of products */ _n( '%d item', '%d items', (int) $total, 'nexora' ), (int) $total ) ); ?></span>
				<span class="pagination-links"><?php echo wp_kses_post( $links ); ?></span>
			</div>
		</div>
<?php endif; ?>
<?php endif; ?>
