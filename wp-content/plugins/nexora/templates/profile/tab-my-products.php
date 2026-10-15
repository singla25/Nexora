<?php
/**
 * The My Products tab (approved vendors): read-only cards; add and edit happen in a SweetAlert2 popup.
 *
 * @var array $my_products Cards from Products\Presenter::mine().
 * @var array $categories  slug => label.
 * @var array $import_history Import history entries.
 */
?>
					<!-- MY PRODUCTS -->
					<div class="tab-content" id="my-products">
						<div class="user-info-header">
							<div class="user-info-left">
								<h3><?php esc_html_e( 'My Products', 'nexora' ); ?></h3>
								<span class="user-info-sub"><?php esc_html_e( 'Products you sell on the marketplace', 'nexora' ); ?></span>
							</div>
							<div class="user-info-right">
								<button type="button" class="nx-product-add" data-title="<?php echo esc_attr__( 'Add product', 'nexora' ); ?>"><?php esc_html_e( 'Add product', 'nexora' ); ?></button>
								<button type="button" class="nx-import-csv" data-template="nx-import-tpl-csv" data-title="<?php echo esc_attr__( 'Import products from a CSV file', 'nexora' ); ?>"><?php esc_html_e( 'Import CSV', 'nexora' ); ?></button>
								<button type="button" class="nx-import-api" data-template="nx-import-tpl-api" data-title="<?php echo esc_attr__( 'Import products from the API', 'nexora' ); ?>"><?php esc_html_e( 'Import from API', 'nexora' ); ?></button>
							</div>
						</div>

						<?php if ( ! $my_products ) : ?>
							<p class="nx-market-empty"><?php esc_html_e( 'You have not added any products yet.', 'nexora' ); ?></p>
						<?php else : ?>
							<div class="nx-product-grid">
								<?php foreach ( $my_products as $nx_card ) : ?>
									<div class="nx-product-wrap">
										<?php \Nexora\Core\View::output( 'marketplace/card', array( 'card' => $nx_card ) ); ?>
										<p class="nx-product-sku">
											<?php
											/* translators: %s: the product's SKU. */
											echo esc_html( sprintf( __( 'SKU: %s', 'nexora' ), $nx_card['sku'] ) );
											?>
										</p>
										<div class="nx-product-actions" data-product="<?php echo esc_attr( wp_json_encode( $nx_card['edit'] ) ); ?>">
											<button type="button" class="nx-product-edit" data-title="<?php echo esc_attr__( 'Edit product', 'nexora' ); ?>"><?php esc_html_e( 'Edit', 'nexora' ); ?></button>
											<button type="button" class="nx-product-delete" data-id="<?php echo (int) $nx_card['id']; ?>"><?php esc_html_e( 'Delete', 'nexora' ); ?></button>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>

						<?php \Nexora\Core\View::output( 'imports/history', array( 'import_history' => $import_history ) ); ?>

						<template id="nx-import-tpl-csv">
							<?php \Nexora\Core\View::output( 'imports/csv-popup' ); ?>
						</template>
						<template id="nx-import-tpl-api">
							<?php \Nexora\Core\View::output( 'imports/api-popup' ); ?>
						</template>

						<template id="nx-product-tpl-form">
							<?php \Nexora\Core\View::output( 'products/form', array( 'categories' => $categories ) ); ?>
						</template>
					</div>

