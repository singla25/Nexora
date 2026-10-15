<?php
/**
 * One page of the marketplace (returned by the listing endpoint).
 *
 * @var array $cards Cards from Products\Presenter::cards().
 * @var int   $page  Current page.
 * @var int   $pages Number of pages.
 * @var int   $total Matching products.
 */
?>
<?php if ( ! $cards ) : ?>
	<p class="nx-market-empty"><?php esc_html_e( 'No products found.', 'nexora' ); ?></p>
<?php else : ?>
	<p class="nx-market-count">
		<?php
		/* translators: %d: number of products found. */
		echo esc_html( sprintf( _n( '%d product', '%d products', $total, 'nexora' ), $total ) );
		?>
	</p>
	<div class="nx-product-grid">
		<?php foreach ( $cards as $nx_card ) : ?>
			<?php \Nexora\Core\View::output( 'marketplace/card', array( 'card' => $nx_card ) ); ?>
		<?php endforeach; ?>
	</div>
	<?php if ( $pages > 1 ) : ?>
		<nav class="nx-market-pager" aria-label="<?php echo esc_attr__( 'Product pages', 'nexora' ); ?>">
			<button type="button" class="nx-market-page" data-page="<?php echo (int) max( 1, $page - 1 ); ?>" <?php disabled( $page <= 1 ); ?>><?php esc_html_e( 'Previous', 'nexora' ); ?></button>
			<span class="nx-market-page-info">
				<?php
				/* translators: 1: current page, 2: number of pages. */
				echo esc_html( sprintf( __( 'Page %1$d of %2$d', 'nexora' ), $page, $pages ) );
				?>
			</span>
			<button type="button" class="nx-market-page" data-page="<?php echo (int) min( $pages, $page + 1 ); ?>" <?php disabled( $page >= $pages ); ?>><?php esc_html_e( 'Next', 'nexora' ); ?></button>
		</nav>
	<?php endif; ?>
<?php endif; ?>
