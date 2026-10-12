<?php /** Variables come from Nexora\Profile\Page::view_data(). */ ?>
					<!-- CONNECTIONS -->
					<div class="tab-content" id="connections">
						<div class="connection-header">

							<?php if ( ! $is_logged_in ) : ?>
								<!-- CASE 1: GUEST -->
								<div class="conn-center">
									<h3><?php esc_html_e( 'Connections', 'nexora' ); ?></h3>
									<span class="conn-sub"><?php esc_html_e( 'Login to explore connections', 'nexora' ); ?></span>
								</div>

							<?php elseif ( $is_owner ) : ?>
								<!-- CASE 2: OWNER -->
								<div class="conn-left">
									<h3 id="conn-heading"><?php esc_html_e( 'Connections', 'nexora' ); ?></h3>
									<span class="conn-sub"><?php esc_html_e( 'Manage your network', 'nexora' ); ?></span>
								</div>

								<div class="conn-right">
									<button class="conn-tab" data-type="add"><?php esc_html_e( 'Add New', 'nexora' ); ?></button>
									<button class="conn-tab" data-type="requests"><?php esc_html_e( 'Requests', 'nexora' ); ?></button>
									<button class="conn-tab" data-type="history"><?php esc_html_e( 'History', 'nexora' ); ?></button>
									<button class="conn-tab" data-type="chat"><?php esc_html_e( 'Chat', 'nexora' ); ?></button>
								</div>

							<?php else : ?>
								<!-- CASE 3: OTHER USER -->
								<div class="conn-left">
									<h3><?php esc_html_e( 'Connections', 'nexora' ); ?></h3>
									<span class="conn-sub"><?php esc_html_e( 'View their network', 'nexora' ); ?></span>
								</div>

								<div class="conn-right"> 
									<button class="conn-tab" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
										<?php esc_html_e( 'All Connections', 'nexora' ); ?>
									</button>

									<button class="conn-tab" data-type="view-common-conn" data-profile="<?php echo (int) $profile_id; ?>">
										<?php esc_html_e( 'Mutual', 'nexora' ); ?>
									</button>
								</div>
							<?php endif; ?>
						</div>

						<!-- CONNECTION ESTABLISHED -->
						<div id="connection-established">
							
							<?php if ( $is_owner ) : ?>
								<div class="establish-connection-cards">
									<?php if ( ! empty( $established ) ) : ?>
										<?php foreach ( $established as $user ) : ?>
											<div class="establish-connection-card">

												<!-- COVER -->
												<div class="conn-cover"></div>

												<!-- AVATAR -->
												<div class="conn-avatar">
													<img src="<?php echo esc_url( $user['image'] ); ?>" alt="">
												</div>

												<!-- INFO -->
												<div class="conn-body">

													<a href="<?php echo esc_url( $user['profile_link'] ); ?>" class="conn-username">
														<?php echo esc_html( $user['username'] ); ?>
													</a>

													<p class="conn-name">
														<?php echo esc_html( $user['name'] ); ?>
													</p>

													<?php if ( $is_owner ) : ?>
														<button class="remove-connection-btn" data-id="<?php echo (int) $user['connection_id']; ?>">
															<?php esc_html_e( 'Remove', 'nexora' ); ?>
														</button>
													<?php endif; ?>
												</div>
											</div>
										<?php endforeach; ?>
									<?php else : ?>
										<div class="empty-content">
											<div class="empty-icon">🤝</div>
											<h3><?php esc_html_e( 'No Connections Yet', 'nexora' ); ?></h3>
											<p>
												<?php esc_html_e( 'You haven’t connected with anyone yet.', 'nexora' ); ?><br>
												<?php esc_html_e( 'Start building your network by sending connection requests 🚀', 'nexora' ); ?>
											</p>
											<button class="conn-tab" data-type="add">
												<?php esc_html_e( '+ Find People', 'nexora' ); ?>
											</button>
										</div>
									<?php endif; ?>
								</div>
							<?php else : ?>

								<div class="connection-summary-wrapper">
									<div class="connection-summary-card">
										<h2><?php echo esc_html( $total_connections ); ?></h2>
										<p><?php esc_html_e( 'Connections', 'nexora' ); ?></p>

										<?php if ( $is_logged_in ) : ?>
											<p class="mutual-count">
												<?php
												/* translators: %s: number of mutual connections. */
												printf( esc_html__( '%s Mutual Connections', 'nexora' ), esc_html( $mutual_count ) );
												?>
											</p>
										<?php endif; ?>

										<div class="connection-preview">
											<?php foreach ( $preview as $user ) : ?>
												<img src="<?php echo esc_url( $user['image'] ); ?>" alt="">
											<?php endforeach; ?>
										</div>

										<?php if ( $is_logged_in ) : ?>
											<button class="view-all-btn" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
												<?php esc_html_e( 'View All Connections', 'nexora' ); ?>
											</button>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

