<?php
/**
 * Nexora System > Settings.
 * @var array[] $images  rows: option, label, width, id, url
 * @var array   $v       text option values: eyebrow, title, subtitle, features, testimonials, admin_email, site_key, has_secret, enabled
 */
?>
        <div class="wrap">
            <h1>Profile System Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('profile_settings_group'); ?>
                <?php do_settings_sections('profile_settings_group'); ?>

                <table class="form-table">
<?php foreach ($images as $img): ?>
                    <tr>
                        <th><?php echo esc_html($img['label']); ?></th>
                        <td>
                            <img src="<?php echo $img['url'] ? esc_url($img['url']) : ''; ?>" 
                                style="max-width:<?php echo (int) $img['width']; ?>px; display:block; margin-bottom:10px;">

                            <input type="hidden" name="<?php echo esc_attr($img['option']); ?>" value="<?php echo esc_attr($img['id']); ?>">
                            <button type="button" class="button upload-btn">Upload</button>
                            <button type="button" class="button remove-btn">Remove</button>
                        </td>
                    </tr>

<?php endforeach; ?>
                    <tr>
                        <th colspan="2"><h2 style="margin:20px 0 0;">Home page content</h2>
                            <p class="description" style="font-weight:normal;">Live numbers (members, connections, posts, conversations) are calculated automatically. Leave a field empty to use the default.</p>
                        </th>
                    </tr>

                    <tr>
                        <th><label for="nexora_home_eyebrow">Hero eyebrow</label></th>
                        <td><input type="text" id="nexora_home_eyebrow" name="nexora_home_eyebrow" value="<?php echo esc_attr($v['eyebrow']); ?>" class="regular-text" placeholder="Your professional network"></td>
                    </tr>

                    <tr>
                        <th><label for="nexora_home_title">Hero title</label></th>
                        <td><input type="text" id="nexora_home_title" name="nexora_home_title" value="<?php echo esc_attr($v['title']); ?>" class="regular-text" placeholder="Connect. Grow. Discover."></td>
                    </tr>

                    <tr>
                        <th><label for="nexora_home_subtitle">Hero subtitle</label></th>
                        <td><textarea id="nexora_home_subtitle" name="nexora_home_subtitle" rows="3" class="large-text" placeholder="Nexora helps you connect, share, and grow your network in real-time."><?php echo esc_textarea($v['subtitle']); ?></textarea></td>
                    </tr>

                    <tr>
                        <th><label for="nexora_home_features">Features</label></th>
                        <td>
                            <textarea id="nexora_home_features" name="nexora_home_features" rows="6" class="large-text code" placeholder="Real-time chat | Instant conversations with subject-based threads."><?php echo esc_textarea($v['features']); ?></textarea>
                            <p class="description">One feature per line: <code>Title | Description</code></p>
                        </td>
                    </tr>

                    <tr>
                        <th><label for="nexora_home_testimonials">Testimonials</label></th>
                        <td>
                            <textarea id="nexora_home_testimonials" name="nexora_home_testimonials" rows="6" class="large-text code" placeholder="Jane Doe | Designer | Great place to meet people."><?php echo esc_textarea($v['testimonials']); ?></textarea>
                            <p class="description">One per line: <code>Name | Role | Quote</code>. The section is hidden when empty.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Admin Notification Email</th>
                        <td>
                            <input 
                                type="email" 
                                name="default_admin_mail" 
                                value="<?php echo esc_attr($v['admin_email']); ?>" 
                                class="regular-text"
                                placeholder="Enter admin email"
                            >

                            <p class="description">
                                All registration notifications will be sent to this email.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Google reCAPTCHA Site Key</th>
                        <td>
                            <input 
                                type="text" 
                                name="recaptcha_site_key" 
                                value="<?php echo esc_attr($v['site_key']); ?>" 
                                class="regular-text"
                                placeholder="Enter Site Key"
                            >

                            <p class="description">
                                Used on frontend (forms).
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th>Google reCAPTCHA Secret Key</th>
                        <td>
                            <input 
                                type="password" 
                                name="recaptcha_secret_key" 
                                value="<?php echo esc_attr($v['has_secret'] ? '************' : ''); ?>" 
                                class="regular-text"
                                placeholder="Enter Secret Key"
                            >

                            <p class="description">
                                Used for backend verification. Keep it secure.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th>Enable reCAPTCHA</th>
                        <td>
                            <label>
                                <input 
                                    type="checkbox" 
                                    name="recaptcha_enabled" 
                                    value="1" 
                                    <?php checked($v['enabled'], 1); ?>
                                >
                                Enable Google reCAPTCHA
                            </label>

                            <p class="description">
                                Enable captcha protection on login, registration and forms.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
