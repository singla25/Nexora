<?php
/**
 * "Your cart by seller": the cart's marketplace lines grouped by vendor, and what changed since the member last looked.
 * Everything printed is escaped here.
 *
 * @var array[] $groups  vendor, subtotal, lines [ name, qty, unit, total, image ].
 * @var string[] $changes Plain-words messages about changes.
 */
?>
<section class="nx-cart-panel" aria-label="<?php echo esc_attr__( 'Your cart by seller', 'nexora' ); ?>">
	<?php if ( $changes ) : ?>
		<div class="nx-cart-changes" role="status">
			<p><strong><?php esc_html_e( 'Your cart changed:', 'nexora' ); ?></strong></p>
			<ul>
				<?php foreach ( $changes as $nx_change ) : ?>
					<li><?php echo esc_html( $nx_change ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<?php if ( $groups ) : ?>
		<h3 class="nx-cart-title"><?php esc_html_e( 'Your cart by seller', 'nexora' ); ?></h3>
		<?php foreach ( $groups as $nx_group ) : ?>
			<div class="nx-cart-vendor">
				<h4 class="nx-cart-vendor-name">
					<?php
					/* translators: %s: the seller's business name. */
					echo esc_html( sprintf( __( 'Sold by %s', 'nexora' ), $nx_group['vendor'] ) );
					?>
				</h4>
				<ul class="nx-cart-lines">
					<?php foreach ( $nx_group['lines'] as $nx_line ) : ?>
						<li class="nx-cart-line">
							<?php if ( $nx_line['image'] ) : ?>
								<img src="<?php echo esc_url( $nx_line['image'] ); ?>" alt="" class="nx-cart-thumb" loading="lazy">
							<?php endif; ?>
							<span class="nx-cart-line-name"><?php echo esc_html( $nx_line['name'] ); ?></span>
							<span class="nx-cart-line-qty">
								<?php
								/* translators: 1: quantity, 2: price of one. */
								echo esc_html( sprintf( __( '%1$d x %2$s', 'nexora' ), $nx_line['qty'], $nx_line['unit'] ) );
								?>
							</span>
							<span class="nx-cart-line-total"><?php echo esc_html( $nx_line['total'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<p class="nx-cart-sub-row">
					<?php esc_html_e( 'Subtotal from this seller', 'nexora' ); ?>:
					<span class="nx-cart-subtotal"><?php echo esc_html( $nx_group['subtotal'] ); ?></span>
				</p>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</section>
