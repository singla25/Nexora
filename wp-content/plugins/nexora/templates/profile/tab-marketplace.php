<?php
/**
 * The Marketplace tab shell. Products are fetched when the tab opens (assets/js/marketplace.js).
 *
 * @var array $categories slug => label.
 */
?>
					<!-- MARKETPLACE -->
					<div class="tab-content" id="marketplace">
						<div class="user-info-header">
							<div class="user-info-center">
								<h3><?php esc_html_e( 'Marketplace', 'nexora' ); ?></h3>
								<span class="user-info-sub"><?php esc_html_e( 'Browse products from our sellers', 'nexora' ); ?></span>
							</div>
						</div>

						<form class="nx-market-filter" role="search">
							<input type="search" name="search" maxlength="80" placeholder="<?php echo esc_attr__( 'Search products', 'nexora' ); ?>" aria-label="<?php echo esc_attr__( 'Search products', 'nexora' ); ?>">
							<select name="category" aria-label="<?php echo esc_attr__( 'Category', 'nexora' ); ?>">
								<option value=""><?php esc_html_e( 'All categories', 'nexora' ); ?></option>
								<?php foreach ( $categories as $nx_slug => $nx_label ) : ?>
									<option value="<?php echo esc_attr( $nx_slug ); ?>"><?php echo esc_html( $nx_label ); ?></option>
								<?php endforeach; ?>
							</select>
							<button type="submit" class="nx-market-search"><?php esc_html_e( 'Search', 'nexora' ); ?></button>
						</form>

						<div class="nx-market-results" aria-live="polite"></div>
					</div>

