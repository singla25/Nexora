<?php
/**
 * A vendor's orders: only the vendor's own lines, with the commission and the earning of each. Escaped here.
 *
 * @var array[] $orders From Orders\Earnings::sales().
 */
use Nexora\Orders\Display;

if ( ! $orders ) : ?>
	<p class="nx-orders-empty"><?php esc_html_e( 'No sales yet. When a customer orders your products they appear here.', 'nexora' ); ?></p>
	<?php
	return;
endif;
?>
<?php foreach ( $orders as $nx_o ) : ?>
	<article class="nx-order-card nx-sale">
		<header class="nx-order-head">
			<h4 class="nx-order-title">
				<?php
				/* translators: %s: order number. */
				echo esc_html( sprintf( __( 'Order #%s', 'nexora' ), $nx_o['number'] ) );
				?>
			</h4>
			<span class="nx-order-status nx-status-<?php echo esc_attr( sanitize_html_class( $nx_o['status'] ) ); ?>"><?php echo esc_html( $nx_o['status_label'] ); ?></span>
		</header>
		<p class="nx-order-meta">
			<?php echo esc_html( $nx_o['date'] ); ?>
			&middot;
			<?php
			if ( $nx_o['counted'] ) {
				esc_html_e( 'Counted in your earnings', 'nexora' );
			} elseif ( $nx_o['awaiting'] ) {
				esc_html_e( 'Awaiting payment: not counted yet', 'nexora' );
			} else {
				esc_html_e( 'Not counted', 'nexora' );
			}
			?>
		</p>
		<table class="nx-order-lines">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'SKU', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Product', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Commission', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Your earning', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $nx_o['lines'] as $nx_l ) : ?>
					<tr>
						<td><?php echo esc_html( $nx_l['sku'] ); ?></td>
						<td><?php echo esc_html( $nx_l['name'] ); ?></td>
						<td><?php echo (int) $nx_l['qty']; ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['price'] ) ); ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['gross'] ) ); ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['net_commission'] ) ); ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['net_earning'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="nx-order-total">
			<?php esc_html_e( 'Your earning on this order:', 'nexora' ); ?>
			<strong><?php echo esc_html( Display::price( $nx_o['totals']['earning'] ) ); ?></strong>
		</p>
		<button type="button" class="nx-sale-view" data-order="<?php echo (int) $nx_o['id']; ?>"><?php esc_html_e( 'View order', 'nexora' ); ?></button>
	</article>
<?php endforeach; ?>
