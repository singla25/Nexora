<?php /** Variables come from Nexora\Profile\Page::view_data(). */ extract( $ctx ); ?>
					<!-- CONNECTIONS -->
					<div class="tab-content" id="connections">
						<div class="connection-header">

							<?php if ( ! $is_logged_in ) : ?>
								<!-- CASE 1: GUEST -->
								<div class="conn-center">
									<h3>Connections</h3>
									<span class="conn-sub">Login to explore connections</span>
								</div>

							<?php elseif ( $is_owner ) : ?>
								<!-- CASE 2: OWNER -->
								<div class="conn-left">
									<h3 id="conn-heading">Connections</h3>
									<span class="conn-sub">Manage your network</span>
								</div>

								<div class="conn-right">
									<button class="conn-tab" data-type="add">Add New</button>
									<button class="conn-tab" data-type="requests">Requests</button>
									<button class="conn-tab" data-type="history">History</button>
									<button class="conn-tab" data-type="chat">Chat</button>
								</div>

							<?php else : ?>
								<!-- CASE 3: OTHER USER -->
								<div class="conn-left">
									<h3>Connections</h3>
									<span class="conn-sub">View their network</span>
								</div>

								<div class="conn-right"> 
									<button class="conn-tab" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
										All Connections
									</button>

									<button class="conn-tab" data-type="view-common-conn" data-profile="<?php echo (int) $profile_id; ?>">
										Mutual
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
															Remove
														</button>
													<?php endif; ?>
												</div>
											</div>
										<?php endforeach; ?>
									<?php else : ?>
										<div class="empty-content">
											<div class="empty-icon">🤝</div>
											<h3>No Connections Yet</h3>
											<p>
												You haven’t connected with anyone yet.<br>
												Start building your network by sending connection requests 🚀
											</p>
											<button class="conn-tab" data-type="add">
												+ Find People
											</button>
										</div>
									<?php endif; ?>
								</div>
							<?php else : ?>

								<div class="connection-summary-wrapper">
									<div class="connection-summary-card">
										<h2><?php echo esc_html( $total_connections ); ?></h2>
										<p>Connections</p>

										<?php if ( $is_logged_in ) : ?>
											<p class="mutual-count">
												<?php echo esc_html( $mutual_count ); ?> Mutual Connections
											</p>
										<?php endif; ?>

										<div class="connection-preview">
											<?php foreach ( $preview as $user ) : ?>
												<img src="<?php echo esc_url( $user['image'] ); ?>" alt="">
											<?php endforeach; ?>
										</div>

										<?php if ( $is_logged_in ) : ?>
											<button class="view-all-btn" data-type="view-all-conn" data-profile="<?php echo (int) $profile_id; ?>">
												View All Connections
											</button>
										<?php endif; ?>
									</div>
								</div>
							<?php endif; ?>
						</div>
					</div>

