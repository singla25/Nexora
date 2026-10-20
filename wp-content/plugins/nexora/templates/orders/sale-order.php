<?php
/**
 * One order for a vendor: their lines and what shipping needs (the recipient and the shipping address while the seller still has unshipped lines on a counted order).
 * Billing details, e-mail and phone are never part of this data. Read-only; escaped here.
 *
 * @var array $order From Orders\Earnings::order_for_vendor().
 */
use Nexora\Orders\Display;

$nx_o = $order;
?>
<div class="nx-sale-detail">
	<h4 class="nx-order-title">
		<?php
		/* translators: %s: order number. */
		echo esc_html( sprintf( __( 'Order #%s', 'nexora' ), $nx_o['number'] ) );
		?>
		<span class="nx-order-status nx-status-<?php echo esc_attr( sanitize_html_class( $nx_o['status'] ) ); ?>"><?php echo esc_html( $nx_o['status_label'] ); ?></span>
	</h4>
	<p class="nx-order-meta"><?php echo esc_html( $nx_o['date'] ); ?><?php echo '' !== $nx_o['payment'] ? ' &middot; ' . esc_html( $nx_o['payment'] ) : ''; ?></p>
	<table class="nx-order-lines">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'SKU', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Product', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Qty', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Total', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Your earning', 'nexora' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Shipped', 'nexora' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $nx_o['lines'] as $nx_l ) : ?>
				<tr>
					<td><?php echo esc_html( $nx_l['sku'] ); ?></td>
					<td><?php echo esc_html( $nx_l['name'] ); ?></td>
					<td><?php echo (int) $nx_l['qty']; ?></td>
					<td><?php echo esc_html( Display::price( $nx_l['gross'] ) ); ?></td>
					<td><?php echo esc_html( Display::price( $nx_l['net_earning'] ) ); ?></td>
					<td>
						<?php
						if ( $nx_l['shipped'] ) {
							esc_html_e( 'Shipped', 'nexora' );
						} elseif ( (int) $nx_l['qty'] - (int) $nx_l['refunded_qty'] <= 0 ) {
							esc_html_e( 'Refunded', 'nexora' );
						} else {
							esc_html_e( 'Not yet', 'nexora' );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php if ( ! empty( $nx_o['can_ship'] ) ) : ?>
		<p class="nx-ship-row"><button type="button" class="nx-ship" data-order="<?php echo (int) $nx_o['id']; ?>"><?php esc_html_e( 'Mark my items as shipped', 'nexora' ); ?></button></p>
	<?php endif; ?>
	<?php if ( ! empty( $nx_o['ship_to'] ) ) : ?>
		<p class="nx-order-ship">
			<strong><?php esc_html_e( 'Ship to', 'nexora' ); ?></strong><br>
			<?php foreach ( $nx_o['ship_to'] as $nx_line ) : ?>
				<?php echo esc_html( $nx_line ); ?><br>
			<?php endforeach; ?>
		</p>
	<?php else : ?>
		<p class="nx-order-ship"><?php esc_html_e( 'Nothing to ship for this order.', 'nexora' ); ?></p>
	<?php endif; ?>
</div>
