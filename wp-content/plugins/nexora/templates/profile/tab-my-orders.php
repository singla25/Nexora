<?php
/**
 * The My Orders tab shell. The cards are fetched when the tab opens (assets/js/orders.js).
 */
?>
					<!-- MY ORDERS -->
					<div class="tab-content" id="my-orders">
						<div class="user-info-header">
							<div class="user-info-center">
								<h3><?php esc_html_e( 'My Orders', 'nexora' ); ?></h3>
								<span class="user-info-sub"><?php esc_html_e( 'What you have bought', 'nexora' ); ?></span>
							</div>
						</div>
						<div class="nx-orders-results" aria-live="polite"></div>
						<div class="nx-orders-pager"></div>
					</div>

