<?php
/**
 * E-mail to a seller: one of their products is running low.
 *
 * @var string $greeting  The seller's display name.
 * @var string $name      Product name.
 * @var string $sku       SKU.
 * @var int    $stock     Units left.
 * @var int    $threshold The low-stock threshold.
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
	echo esc_html(
		sprintf(
			/* translators: 1: product name, 2: SKU, 3: units left, 4: threshold */
			__( 'Low stock: "%1$s" (%2$s) has %3$d left (your alert level is %4$d). Update the stock in My Products to keep it on sale.', 'nexora' ),
			$name,
			$sku,
			(int) $stock,
			(int) $threshold
		)
	);
	?>
</p>
