<?php
/**
 * Nexora System > Settings.
 *
 * @var array[] $images  rows: option, label, width, id, url
 * @var array   $v       text option values: eyebrow, title, subtitle, features, testimonials, admin_email, site_key, has_secret, enabled
 */
?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Profile System Settings', 'nexora' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'profile_settings_group' ); ?>
				<?php do_settings_sections( 'profile_settings_group' ); ?>

				<table class="form-table">
<?php foreach ( $images as $img ) : ?>
					<tr>
						<th><?php echo esc_html( $img['label'] ); ?></th>
						<td>
							<img src="<?php echo $img['url'] ? esc_url( $img['url'] ) : ''; ?>" 
								style="max-width:<?php echo (int) $img['width']; ?>px; display:block; margin-bottom:10px;">

							<input type="hidden" name="<?php echo esc_attr( $img['option'] ); ?>" value="<?php echo esc_attr( $img['id'] ); ?>">
							<button type="button" class="button upload-btn"><?php esc_html_e( 'Upload', 'nexora' ); ?></button>
							<button type="button" class="button remove-btn"><?php esc_html_e( 'Remove', 'nexora' ); ?></button>
						</td>
					</tr>

<?php endforeach; ?>
					<tr>
						<th colspan="2"><h2 style="margin:20px 0 0;"><?php esc_html_e( 'Home page content', 'nexora' ); ?></h2>
							<p class="description" style="font-weight:normal;"><?php esc_html_e( 'Live numbers (members, connections, posts, conversations) are calculated automatically. Leave a field empty to use the default.', 'nexora' ); ?></p>
						</th>
					</tr>

					<tr>
						<th><label for="nexora_home_eyebrow"><?php esc_html_e( 'Hero eyebrow', 'nexora' ); ?></label></th>
						<td><input type="text" id="nexora_home_eyebrow" name="nexora_home_eyebrow" value="<?php echo esc_attr( $v['eyebrow'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Your professional network', 'nexora' ); ?>"></td>
					</tr>

					<tr>
						<th><label for="nexora_home_title"><?php esc_html_e( 'Hero title', 'nexora' ); ?></label></th>
						<td><input type="text" id="nexora_home_title" name="nexora_home_title" value="<?php echo esc_attr( $v['title'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'Connect. Grow. Discover.', 'nexora' ); ?>"></td>
					</tr>

					<tr>
						<th><label for="nexora_home_subtitle"><?php esc_html_e( 'Hero subtitle', 'nexora' ); ?></label></th>
						<td><textarea id="nexora_home_subtitle" name="nexora_home_subtitle" rows="3" class="large-text" placeholder="<?php echo esc_attr__( 'Nexora helps you connect, share, and grow your network in real-time.', 'nexora' ); ?>"><?php echo esc_textarea( $v['subtitle'] ); ?></textarea></td>
					</tr>

					<tr>
						<th><label for="nexora_home_features"><?php esc_html_e( 'Features', 'nexora' ); ?></label></th>
						<td>
							<textarea id="nexora_home_features" name="nexora_home_features" rows="6" class="large-text code" placeholder="<?php echo esc_attr__( 'Real-time chat | Instant conversations with subject-based threads.', 'nexora' ); ?>"><?php echo esc_textarea( $v['features'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One feature per line:', 'nexora' ); ?> <code><?php esc_html_e( 'Title | Description', 'nexora' ); ?></code></p>
						</td>
					</tr>

					<tr>
						<th><label for="nexora_home_testimonials"><?php esc_html_e( 'Testimonials', 'nexora' ); ?></label></th>
						<td>
							<textarea id="nexora_home_testimonials" name="nexora_home_testimonials" rows="6" class="large-text code" placeholder="<?php echo esc_attr__( 'Jane Doe | Designer | Great place to meet people.', 'nexora' ); ?>"><?php echo esc_textarea( $v['testimonials'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line:', 'nexora' ); ?> <code><?php esc_html_e( 'Name | Role | Quote', 'nexora' ); ?></code><?php esc_html_e( '. The section is hidden when empty.', 'nexora' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="nexora_product_categories"><?php esc_html_e( 'Product categories', 'nexora' ); ?></label></th>
						<td>
							<textarea id="nexora_product_categories" name="nexora_product_categories" rows="6" class="large-text code" placeholder="<?php echo esc_attr__( 'Electronics', 'nexora' ); ?>"><?php echo esc_textarea( $v['categories'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One category per line. Leave empty to use the defaults. "Other" is always available.', 'nexora' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="nexora_commission_rate"><?php esc_html_e( 'Marketplace commission (%)', 'nexora' ); ?></label></th>
						<td>
							<input type="text" inputmode="decimal" id="nexora_commission_rate" name="nexora_commission_rate" value="<?php echo esc_attr( $v['commission'] ); ?>" class="small-text" maxlength="6"> %
							<p class="description"><?php esc_html_e( 'The share of each sale the marketplace keeps, from 0 to 100 (for example 10 or 12.5). The vendor earns the rest. An order keeps the rate it was placed at: changing this affects new orders only.', 'nexora' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><label for="nexora_low_stock_threshold"><?php esc_html_e( 'Low-stock threshold', 'nexora' ); ?></label></th>
						<td>
							<input type="text" inputmode="numeric" id="nexora_low_stock_threshold" name="nexora_low_stock_threshold" value="<?php echo esc_attr( $v['low_stock'] ); ?>" class="small-text" maxlength="6">
							<p class="description"><?php esc_html_e( 'When a product\'s stock falls to this number or below, its seller gets one e-mail and one notification. A whole number from 0 to 100000; 0 turns the alerts off. The alert is sent again only after the stock has risen above this number.', 'nexora' ); ?></p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Product API sources', 'nexora' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Product catalogs every approved vendor can import from. Only public https addresses work (private, local and cloud-metadata addresses are always refused). Keys are stored encrypted and are never shown again: leave a key field empty to keep the saved key.', 'nexora' ); ?></p>
							<?php
							$nx_index  = 0;
							$nx_rows   = $v['sources'];
							$nx_rows[] = array(
								'id'                => '',
								'name'              => '',
								'url'               => '',
								'format'            => 'nexora',
								'auth_type'         => 'none',
								'auth_header'       => '',
								'enabled'           => true,
								'stock_default'     => 10,
								'create_categories' => true,
								'has_secret'        => false,
								'blank'             => true,
							);
							$nx_rows[] = array(
								'id'                => '',
								'name'              => '',
								'url'               => '',
								'format'            => 'nexora',
								'auth_type'         => 'none',
								'auth_header'       => '',
								'enabled'           => true,
								'stock_default'     => 10,
								'create_categories' => true,
								'has_secret'        => false,
								'blank'             => true,
							);
							?>
							<?php foreach ( $nx_rows as $nx_src ) : ?>
								<?php $nx_n = 'nexora_api_sources[' . (int) $nx_index . ']'; ?>
								<fieldset class="nx-source-row" style="border:1px solid #ccd0d4;padding:10px 12px;margin:0 0 10px;">
									<legend><?php echo ! empty( $nx_src['blank'] ) ? esc_html__( 'Add a source', 'nexora' ) : esc_html( $nx_src['name'] ); ?></legend>
									<input type="hidden" name="<?php echo esc_attr( $nx_n ); ?>[id]" value="<?php echo esc_attr( $nx_src['id'] ); ?>">
									<p>
										<label><?php esc_html_e( 'Name', 'nexora' ); ?> <input type="text" name="<?php echo esc_attr( $nx_n ); ?>[name]" value="<?php echo esc_attr( $nx_src['name'] ); ?>" class="regular-text" maxlength="60"></label>
										<label><?php esc_html_e( 'Address (https)', 'nexora' ); ?> <input type="url" name="<?php echo esc_attr( $nx_n ); ?>[url]" value="<?php echo esc_attr( $nx_src['url'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'https://', 'nexora' ); ?>"></label>
									</p>
									<p>
										<label><?php esc_html_e( 'Format', 'nexora' ); ?>
											<select name="<?php echo esc_attr( $nx_n ); ?>[format]">
												<option value="nexora" <?php selected( $nx_src['format'], 'nexora' ); ?>><?php esc_html_e( 'Nexora format', 'nexora' ); ?></option>
												<option value="dummyjson" <?php selected( $nx_src['format'], 'dummyjson' ); ?>><?php esc_html_e( 'DummyJSON adapter', 'nexora' ); ?></option>
												<option value="fakestore" <?php selected( $nx_src['format'], 'fakestore' ); ?>><?php esc_html_e( 'Fake Store adapter', 'nexora' ); ?></option>
											</select>
										</label>
										<label><?php esc_html_e( 'Authentication', 'nexora' ); ?>
											<select name="<?php echo esc_attr( $nx_n ); ?>[auth_type]">
												<option value="none" <?php selected( $nx_src['auth_type'], 'none' ); ?>><?php esc_html_e( 'None', 'nexora' ); ?></option>
												<option value="header" <?php selected( $nx_src['auth_type'], 'header' ); ?>><?php esc_html_e( 'Header key', 'nexora' ); ?></option>
												<option value="bearer" <?php selected( $nx_src['auth_type'], 'bearer' ); ?>><?php esc_html_e( 'Bearer token', 'nexora' ); ?></option>
											</select>
										</label>
										<label><?php esc_html_e( 'Header name', 'nexora' ); ?> <input type="text" name="<?php echo esc_attr( $nx_n ); ?>[auth_header]" value="<?php echo esc_attr( $nx_src['auth_header'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr__( 'X-API-Key', 'nexora' ); ?>"></label>
									</p>
									<p>
										<label><?php esc_html_e( 'Key / token', 'nexora' ); ?>
											<input type="password" name="<?php echo esc_attr( $nx_n ); ?>[secret]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( $nx_src['has_secret'] ? '•••••••• ' . __( 'saved (leave empty to keep)', 'nexora' ) : __( 'No key saved', 'nexora' ) ); ?>">
										</label>
										<?php if ( $nx_src['has_secret'] ) : ?>
											<label><input type="checkbox" name="<?php echo esc_attr( $nx_n ); ?>[clear_secret]" value="1"> <?php esc_html_e( 'Remove the saved key', 'nexora' ); ?></label>
										<?php endif; ?>
									</p>
									<p class="nx-fakestore-only" <?php echo 'fakestore' === $nx_src['format'] ? '' : 'hidden'; ?>>
										<label><?php esc_html_e( 'Stock for items without stock', 'nexora' ); ?> <input type="number" name="<?php echo esc_attr( $nx_n ); ?>[stock_default]" value="<?php echo esc_attr( (string) ( $nx_src['stock_default'] ?? 10 ) ); ?>" min="0" max="100000" step="1" class="small-text"></label>
										<label><input type="checkbox" name="<?php echo esc_attr( $nx_n ); ?>[create_categories]" value="1" <?php checked( ! isset( $nx_src['create_categories'] ) || ! empty( $nx_src['create_categories'] ) ); ?>> <?php esc_html_e( 'Create missing categories', 'nexora' ); ?></label>
									</p>
									<p>
										<label><input type="checkbox" name="<?php echo esc_attr( $nx_n ); ?>[enabled]" value="1" <?php checked( ! empty( $nx_src['enabled'] ) ); ?>> <?php esc_html_e( 'Enabled (vendors can pick it)', 'nexora' ); ?></label>
										<?php if ( empty( $nx_src['blank'] ) ) : ?>
											<label><input type="checkbox" name="<?php echo esc_attr( $nx_n ); ?>[remove]" value="1"> <?php esc_html_e( 'Delete this source', 'nexora' ); ?></label>
										<?php endif; ?>
									</p>
								</fieldset>
								<?php ++$nx_index; ?>
							<?php endforeach; ?>

							<script>
							document.querySelectorAll('.nx-source-row').forEach(function (row) {
								var format = row.querySelector('select[name$="[format]"]');
								var only = row.querySelector('.nx-fakestore-only');
								if (format && only) {
									format.addEventListener('change', function () { only.hidden = format.value !== 'fakestore'; });
								}
							});
							</script>

							<p>
								<label>
									<input type="checkbox" name="nexora_vendor_apis_enabled" value="1" <?php checked( ! empty( $v['vendor_apis'] ) ); ?>>
									<?php esc_html_e( 'Let vendors import from their own API ("My own API")', 'nexora' ); ?>
								</label>
							</p>
							<p>
								<label for="nexora_api_host_allowlist"><?php esc_html_e( 'Allowed hosts for vendors\' own APIs (optional, one per line; empty = any public https host)', 'nexora' ); ?></label><br>
								<textarea id="nexora_api_host_allowlist" name="nexora_api_host_allowlist" rows="3" class="large-text code" placeholder="<?php echo esc_attr__( 'api.example.com', 'nexora' ); ?>"><?php echo esc_textarea( $v['allowlist'] ); ?></textarea>
							</p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Admin Notification Email', 'nexora' ); ?></th>
						<td>
							<input 
								type="email" 
								name="default_admin_mail" 
								value="<?php echo esc_attr( $v['admin_email'] ); ?>" 
								class="regular-text"
								placeholder="<?php echo esc_attr__( 'Enter admin email', 'nexora' ); ?>"
							>

							<p class="description">
								<?php esc_html_e( 'All registration notifications will be sent to this email.', 'nexora' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Google reCAPTCHA Site Key', 'nexora' ); ?></th>
						<td>
							<input 
								type="text" 
								name="recaptcha_site_key" 
								value="<?php echo esc_attr( $v['site_key'] ); ?>" 
								class="regular-text"
								placeholder="<?php echo esc_attr__( 'Enter Site Key', 'nexora' ); ?>"
							>

							<p class="description">
								<?php esc_html_e( 'Used on frontend (forms).', 'nexora' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th><?php esc_html_e( 'Google reCAPTCHA Secret Key', 'nexora' ); ?></th>
						<td>
							<input 
								type="password" 
								name="recaptcha_secret_key" 
								value="<?php echo esc_attr( $v['has_secret'] ? '************' : '' ); ?>" 
								class="regular-text"
								placeholder="<?php echo esc_attr__( 'Enter Secret Key', 'nexora' ); ?>"
							>

							<p class="description">
								<?php esc_html_e( 'Used for backend verification. Keep it secure.', 'nexora' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Enable reCAPTCHA', 'nexora' ); ?></th>
						<td>
							<label>
								<input 
									type="checkbox" 
									name="recaptcha_enabled" 
									value="1" 
									<?php checked( $v['enabled'], 1 ); ?>
								>
								<?php esc_html_e( 'Enable Google reCAPTCHA', 'nexora' ); ?>
							</label>

							<p class="description">
								<?php esc_html_e( 'Enable captcha protection on login, registration and forms.', 'nexora' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Delete data on uninstall', 'nexora' ); ?></th>
						<td>
							<label>
								<input 
									type="checkbox" 
									name="nexora_delete_data_on_uninstall" 
									value="1" 
									<?php checked( $v['delete_data'], 1 ); ?>
								>
								<?php esc_html_e( 'Delete all Nexora data when the plugin is deleted', 'nexora' ); ?>
							</label>

							<p class="description">
								<?php esc_html_e( 'Removes profiles, connections, content, chat, notifications, ID documents and settings. Member accounts and Media Library files are kept. Leave off to keep everything.', 'nexora' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
