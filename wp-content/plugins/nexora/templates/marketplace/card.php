<?php
/**
 * One product card (read-only). Everything printed is escaped here.
 *
 * @var array $card From Products\Presenter::card().
 */
?>
<article class="nx-product-card<?php echo $card['own'] ? ' nx-product-mine' : ''; ?>">
	<?php if ( $card['image'] ) : ?>
		<img src="<?php echo esc_url( $card['image'] ); ?>" alt="<?php echo esc_attr( $card['name'] ); ?>" class="nx-product-img" loading="lazy">
	<?php else : ?>
		<div class="nx-product-img nx-product-noimg" aria-hidden="true"></div>
	<?php endif; ?>
	<div class="nx-product-body">
		<?php if ( $card['category'] ) : ?>
			<span class="nx-product-cat"><?php echo esc_html( $card['category'] ); ?></span>
		<?php endif; ?>
		<h4 class="nx-product-name"><?php echo esc_html( $card['name'] ); ?></h4>
		<?php if ( $card['description'] ) : ?>
			<p class="nx-product-desc"><?php echo esc_html( $card['description'] ); ?></p>
		<?php endif; ?>
		<p class="nx-product-price"><?php echo esc_html( $card['price'] ); ?></p>
		<p class="nx-product-stock"><?php echo esc_html( $card['stock_label'] ); ?></p>
		<?php if ( $card['seller'] ) : ?>
			<p class="nx-product-seller">
				<?php
				/* translators: %s: the seller's business name. */
				echo esc_html( sprintf( __( 'Sold by %s', 'nexora' ), $card['seller'] ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( $card['own'] ) : ?>
			<span class="nx-product-own"><?php esc_html_e( 'Your product', 'nexora' ); ?></span>
		<?php endif; ?>
	</div>
</article>
