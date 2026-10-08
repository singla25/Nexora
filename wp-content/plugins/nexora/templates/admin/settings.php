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
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
