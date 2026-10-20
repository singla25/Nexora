<?php
/**
 * The Sales tab shell (vendors; suspended vendors read their history). Totals and orders are fetched when the tab opens (assets/js/orders.js).
 */
?>
					<!-- SALES -->
					<div class="tab-content" id="sales">
						<div class="user-info-header">
							<div class="user-info-center">
								<h3><?php esc_html_e( 'Sales', 'nexora' ); ?></h3>
								<span class="user-info-sub"><?php esc_html_e( 'Orders for your products and what you earn from them. Only Processing and Completed orders count.', 'nexora' ); ?></span>
							</div>
						</div>
						<?php \Nexora\Orders\Report_View::output_for_current_user(); ?>
						<h4><?php esc_html_e( 'Order details', 'nexora' ); ?></h4>
						<dl class="nx-sales-totals" aria-live="polite"></dl>
						<div class="nx-sales-results" aria-live="polite"></div>
						<div class="nx-orders-pager"></div>
					</div>

