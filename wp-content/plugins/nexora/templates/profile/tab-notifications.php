<?php /** Variables come from Nexora\Profile\Page::view_data(). */ ?>
					<!-- NOTIFICATION -->
					<div class="tab-content" id="notifications">

						<?php if ( $is_owner ) : ?>


						<div class="notification-wrapper">

							<div class="notification-header">
								<h3>🔔 Notifications</h3>
							</div>

							<div class="notification-list">

								<?php
								if ( $notifications ) :
									foreach ( $notifications as $noti ) :
										?>


									<div class="notification-item <?php echo ! $noti['is_read'] ? 'unread' : ''; ?>">

										<!-- LEFT AVATAR -->

										<div class="noti-avatar">
											<img src="<?php echo esc_url( $noti['actor_image'] ); ?>">
										</div>

										<!-- CONTENT -->
										<div class="noti-content">

											<div class="noti-top">
												<span class="noti-message">
													<?php echo esc_html( $noti['message'] ); ?>
												</span>

												<span class="noti-status <?php echo ! $noti['is_read'] ? 'new' : 'read'; ?>">
													<?php echo ! $noti['is_read'] ? 'New' : 'Read'; ?>
												</span>
											</div>

											<div class="noti-time">
												<?php echo esc_html( $noti['time'] ); ?>
											</div>

										</div>

										<!-- ACTION -->
										<button 
											class="notification-view"
											data-id="<?php echo (int) $noti['id']; ?>"
											data-type="received"
										>
											View
										</button>

									</div>

																	<?php endforeach; else : ?>

									<div class="empty-notification empty-content">

										<div class="empty-icon">🔔</div>

										<h3>No Notifications Yet</h3>

										<p>
											You're all caught up 🎉 <br>
											Notifications will appear here when you get updates
										</p>
									</div>

								<?php endif; ?>
							</div>
						</div>

						<?php else : ?>
							<p>Access restricted</p>
						<?php endif; ?>
					</div>

