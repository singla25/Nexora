<?php
/**
 * [nexora_contact_form]
 *
 * @var array|null $notice      type (ok|error), role, text; null for none
 * @var string     $action      admin-post action name
 * @var string     $action_url
 * @var string     $redirect    page to come back to
 * @var string     $name        prefilled for logged-in visitors
 * @var string     $email
 */
?>

		<div class="nx-contact-form">

			<?php if ( $notice ) : ?>
				<p class="nx-form-notice nx-form-notice--<?php echo esc_attr( $notice['type'] ); ?>" role="<?php echo esc_attr( $notice['role'] ); ?>"><?php echo esc_html( $notice['text'] ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $action_url ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_url( $redirect ); ?>">
				<?php wp_nonce_field( $action, 'nx_contact_nonce' ); ?>

				<!-- honeypot: real visitors never see or fill this -->
				<div class="nx-hp" aria-hidden="true">
					<label><?php esc_html_e( 'Leave this empty', 'nexora' ); ?> <input type="text" name="nx_website" tabindex="-1" autocomplete="off"></label>
				</div>

				<div class="nx-form-row">
					<label for="nx-contact-name"><?php esc_html_e( 'Your name', 'nexora' ); ?></label>
					<input type="text" id="nx-contact-name" name="nx_name" value="<?php echo esc_attr( $name ); ?>" required maxlength="100">
				</div>

				<div class="nx-form-row">
					<label for="nx-contact-email"><?php esc_html_e( 'Email', 'nexora' ); ?></label>
					<input type="email" id="nx-contact-email" name="nx_email" value="<?php echo esc_attr( $email ); ?>" required maxlength="150">
				</div>

				<div class="nx-form-row">
					<label for="nx-contact-subject"><?php esc_html_e( 'Subject', 'nexora' ); ?></label>
					<input type="text" id="nx-contact-subject" name="nx_subject" maxlength="150">
				</div>

				<div class="nx-form-row">
					<label for="nx-contact-message"><?php esc_html_e( 'Message', 'nexora' ); ?></label>
					<textarea id="nx-contact-message" name="nx_message" rows="6" required maxlength="3000"></textarea>
				</div>

				<button type="submit" class="nx-btn nx-primary"><?php esc_html_e( 'Send message', 'nexora' ); ?></button>
			</form>
		</div>

