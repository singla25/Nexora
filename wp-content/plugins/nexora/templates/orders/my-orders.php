<?php
/**
 * The member's own orders as read-only cards. Everything printed is escaped here; the lines are the values frozen when the order was placed.
 *
 * @var array[] $orders From Orders\Customer_Orders::page().
 */
use Nexora\Orders\Display;

if ( ! $orders ) : ?>
	<p class="nx-orders-empty"><?php esc_html_e( 'You have no orders yet. Browse the Marketplace to find something you like.', 'nexora' ); ?></p>
	<?php
	return;
endif;
?>
<?php foreach ( $orders as $nx_o ) : ?>
	<article class="nx-order-card">
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
			<?php if ( '' !== $nx_o['payment'] ) : ?>
				&middot; <?php echo esc_html( $nx_o['payment'] ); ?>
			<?php endif; ?>
			&middot; <strong><?php echo esc_html( $nx_o['total'] ); ?></strong>
		</p>
		<table class="nx-order-lines">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Seller', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Qty', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'nexora' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Total', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $nx_o['lines'] as $nx_l ) : ?>
					<tr>
						<td><?php echo esc_html( $nx_l['name'] ); ?> <span class="nx-order-sku"><?php echo esc_html( $nx_l['sku'] ); ?></span></td>
						<td><?php echo esc_html( $nx_l['vendor_name'] ); ?></td>
						<td><?php echo (int) $nx_l['qty']; ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['price'] ) ); ?></td>
						<td><?php echo esc_html( Display::price( $nx_l['gross'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $nx_o['ship_to'] ) : ?>
			<p class="nx-order-ship">
				<strong><?php esc_html_e( 'Shipping to', 'nexora' ); ?></strong><br>
				<?php echo esc_html( implode( ', ', $nx_o['ship_to'] ) ); ?>
			</p>
		<?php endif; ?>
	</article>
<?php endforeach; ?>
