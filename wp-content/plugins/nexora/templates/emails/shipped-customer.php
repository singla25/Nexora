<?php
/**
 * E-mail to the customer: a seller shipped their items. Only the shipped items of that seller; no private detail of the seller.
 *
 * @var string  $greeting The customer's first name (may be empty).
 * @var string  $business The seller's business name.
 * @var string  $number   Order number.
 * @var array[] $lines    The shipped lines (Orders\Lines::read()).
 */
?>
<p>
	<?php
	if ( '' !== (string) $greeting ) {
		/* translators: %s: the customer's first name. */
		echo esc_html( sprintf( __( 'Hello %s,', 'nexora' ), $greeting ) );
	} else {
		esc_html_e( 'Hello,', 'nexora' );
	}
	?>
</p>
<p>
	<?php
	/* translators: 1: seller business name, 2: order number. */
	echo esc_html( sprintf( __( 'Your items from %1$s in order #%2$s have shipped:', 'nexora' ), $business, $number ) );
	?>
</p>
<ul>
	<?php foreach ( $lines as $nx_l ) : ?>
		<li>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: product name, 2: quantity */
					__( '%1$s x %2$d', 'nexora' ),
					$nx_l['name'],
					(int) $nx_l['qty']
				)
			);
			?>
		</li>
	<?php endforeach; ?>
</ul>
<p><?php esc_html_e( 'You can follow your orders in the My Orders tab of your profile.', 'nexora' ); ?></p>
