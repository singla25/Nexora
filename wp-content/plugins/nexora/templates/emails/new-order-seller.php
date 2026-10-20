<?php
/**
 * E-mail to a seller: an order that contains their products is now Processing. Their own lines only; nothing about the buyer.
 *
 * @var string   $greeting The seller's display name.
 * @var string   $number   Order number.
 * @var array[]  $lines    The seller's lines (Orders\Lines::read()).
 * @var callable $price    Formats an amount.
 * @var string   $sales    Link to the seller's profile (the Sales tab is there).
 */
?>
<p>
	<?php
	/* translators: %s: the seller's name. */
	echo esc_html( sprintf( __( 'Hello %s,', 'nexora' ), $greeting ) );
	?>
</p>
<p>
	<?php
	/* translators: %s: order number. */
	echo esc_html( sprintf( __( 'Order #%s contains your products and is ready for you to ship. Your items on it:', 'nexora' ), $number ) );
	?>
</p>
<ul>
	<?php foreach ( $lines as $nx_l ) : ?>
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: product name, 2: SKU, 3: quantity, 4: unit price, 5: seller earning for the line */
					__( '%1$s (%2$s) x %3$d at %4$s - you earn %5$s', 'nexora' ),
					$nx_l['name'],
					$nx_l['sku'],
					(int) $nx_l['qty'],
					$price( $nx_l['price'] ),
					$price( $nx_l['net_earning'] )
				)
			);
			?>
		</li>
	<?php endforeach; ?>
</ul>
<p>
	<?php esc_html_e( 'Open the Sales tab of your profile to see the order and to mark your items as shipped.', 'nexora' ); ?>
	<a href="<?php echo esc_url( $sales ); ?>"><?php esc_html_e( 'Go to my profile', 'nexora' ); ?></a>
</p>
