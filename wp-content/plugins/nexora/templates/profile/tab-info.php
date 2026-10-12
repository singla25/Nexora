<?php /** Variables come from Nexora\Profile\Page::view_data(). */ ?>
					<!-- USER INFORMATION -->
					<div class="tab-content active" id="user-info">
						<div class="user-info-header">
							<?php if ( $is_owner ) : ?>
								
								<div class="user-info-left">
									<h3><?php esc_html_e( 'Your Information', 'nexora' ); ?></h3>
									<span class="user-info-sub"><?php esc_html_e( 'Manage your Informations', 'nexora' ); ?></span>
								</div>

								<div class="user-info-right">
									<button class="user-edit-info active" data-type="personal-info"><?php esc_html_e( 'Personal', 'nexora' ); ?></button>
									<button class="user-edit-info" data-type="address-info"><?php esc_html_e( 'Address', 'nexora' ); ?></button>
									<button class="user-edit-info" data-type="work-info"><?php esc_html_e( 'Work', 'nexora' ); ?></button>
									<button class="user-edit-info" data-type="docs-info"><?php esc_html_e( 'Documents', 'nexora' ); ?></button>
									<button class="user-edit-info" data-type="security-info"><?php esc_html_e( 'Security', 'nexora' ); ?></button>
								</div>

							<?php else : ?>
								<div class="user-info-center">
									<h3><?php esc_html_e( 'User Information', 'nexora' ); ?></h3>
									<span class="user-info-sub"><?php esc_html_e( 'Login to explore more', 'nexora' ); ?></span>
								</div>
							<?php endif; ?>
						</div>

						<div id="user-info-content">
							<!-- PERSONAL INFO -->
							<div class="info-card">
								<h3><?php esc_html_e( 'Personal Information', 'nexora' ); ?></h3>

								<div class="info-grid">

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Username', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $username ); ?></span>
									</div>

									<?php if ( $is_owner ) : ?>
									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Email', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $email ); ?></span>
									</div>
									<?php endif; ?>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'First Name', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['first_name'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Last Name', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['last_name'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Gender', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['gender'] ); ?></span>
									</div>

									<?php if ( $is_owner ) : ?>
									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Birthdate', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['birthdate'] ); ?></span>
									</div>
									<?php endif; ?>

									<?php if ( $is_owner ) : ?>
									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Phone', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $phone ); ?></span>
									</div>
									<?php endif; ?>
									
									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'LinkedIn', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['linkedin_id'] ); ?></span>
									</div>

								</div>

								<div class="info-full">
									<span class="info-label"><?php esc_html_e( 'Bio', 'nexora' ); ?></span>
									<p class="info-value"><?php echo esc_html( $meta['bio'] ); ?></p>
								</div>
							</div>

							<!-- ADDRESS INFO -->
							<?php if ( $is_owner ) : ?>
							<div class="info-card">
								<h3><?php esc_html_e( 'Address Information', 'nexora' ); ?></h3>

								<!-- PERMANENT -->
								<div class="info-section">
									<h4><?php esc_html_e( 'Permanent Address', 'nexora' ); ?></h4>

									<div class="info-grid">
										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'Address', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['perm_address'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'City', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['perm_city'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'State', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['perm_state'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'Pincode', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['perm_pincode'] ); ?></span>
										</div>
									</div>
								</div>

								<!-- CORRESPONDENCE -->
								<div class="info-section">
									<h4><?php esc_html_e( 'Correspondence Address', 'nexora' ); ?></h4>

									<div class="info-grid">
										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'Address', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['corr_address'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'City', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['corr_city'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'State', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['corr_state'] ); ?></span>
										</div>

										<div class="info-item">
											<span class="info-label"><?php esc_html_e( 'Pincode', 'nexora' ); ?></span>
											<span class="info-value"><?php echo esc_html( $meta['corr_pincode'] ); ?></span>
										</div>
									</div>
								</div>
							</div>
							<?php endif; ?>

							<!-- WORK INFO -->
							<div class="info-card">
								<h3><?php esc_html_e( 'Work Information', 'nexora' ); ?></h3>

								<div class="info-grid">

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Company Name', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['company_name'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Designation', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['designation'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Company Email', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['company_email'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Company Phone', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['company_phone'] ); ?></span>
									</div>

									<div class="info-item">
										<span class="info-label"><?php esc_html_e( 'Company Address', 'nexora' ); ?></span>
										<span class="info-value"><?php echo esc_html( $meta['company_address'] ); ?></span>
									</div>
								</div>
							</div>

							<!-- DOCUMENTS -->
							<div class="info-card">
								<h3><?php esc_html_e( 'Documents', 'nexora' ); ?></h3>

								<div class="doc-grid">

									<?php foreach ( $docs as $doc ) : ?>

										<div class="doc-card">
											<span class="doc-title"><?php echo esc_html( $doc['label'] ); ?></span>

											<?php if ( $doc['url'] ) : ?>
												<a href="<?php echo esc_url( $doc['url'] ); ?>" target="_blank">
													<img src="<?php echo esc_url( $doc['url'] ); ?>" class="doc-img">
												</a>
											<?php else : ?>
												<div class="doc-empty-box"><?php esc_html_e( 'No File', 'nexora' ); ?></div>
											<?php endif; ?>
										</div>
									<?php endforeach; ?>
								</div>
							</div>
						</div>
					</div>

