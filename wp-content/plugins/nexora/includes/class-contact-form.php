<?php

if (!defined('ABSPATH')) exit;

/**
 * [nexora_contact_form]
 *
 * A small, spam-resistant contact form that mails the site admin (or the
 * "Admin Notification Email" from Nexora settings). Protections: nonce,
 * honeypot field, per-IP rate limit, header-injection safe Reply-To.
 */
class Nexora_Contact_Form {

    const ACTION = 'nexora_contact';

    public function __construct() {
        add_shortcode('nexora_contact_form', [$this, 'render']);

        add_action('admin_post_nopriv_' . self::ACTION, [$this, 'handle']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    public function render() {

        $status = isset($_GET['nx_contact']) ? sanitize_key(wp_unslash($_GET['nx_contact'])) : '';

        $current = is_singular() ? get_permalink() : home_url('/');

        $user  = wp_get_current_user();
        $name  = $user->exists() ? $user->display_name : '';
        $email = $user->exists() ? $user->user_email : '';

        ob_start(); ?>

        <div class="nx-contact-form">

            <?php if ($status === 'sent'): ?>
                <p class="nx-form-notice nx-form-notice--ok" role="status">Thank you! Your message has been sent. We will get back to you soon.</p>
            <?php elseif ($status === 'invalid'): ?>
                <p class="nx-form-notice nx-form-notice--error" role="alert">Please fill in your name, a valid email and a message.</p>
            <?php elseif ($status === 'limit'): ?>
                <p class="nx-form-notice nx-form-notice--error" role="alert">Too many messages sent. Please try again in a few minutes.</p>
            <?php elseif ($status === 'error'): ?>
                <p class="nx-form-notice nx-form-notice--error" role="alert">Sorry, the message could not be sent. Please try again later.</p>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                <input type="hidden" name="redirect_to" value="<?php echo esc_url($current); ?>">
                <?php wp_nonce_field(self::ACTION, 'nx_contact_nonce'); ?>

                <!-- honeypot: real visitors never see or fill this -->
                <div class="nx-hp" aria-hidden="true">
                    <label>Leave this empty <input type="text" name="nx_website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="nx-form-row">
                    <label for="nx-contact-name">Your name</label>
                    <input type="text" id="nx-contact-name" name="nx_name" value="<?php echo esc_attr($name); ?>" required maxlength="100">
                </div>

                <div class="nx-form-row">
                    <label for="nx-contact-email">Email</label>
                    <input type="email" id="nx-contact-email" name="nx_email" value="<?php echo esc_attr($email); ?>" required maxlength="150">
                </div>

                <div class="nx-form-row">
                    <label for="nx-contact-subject">Subject</label>
                    <input type="text" id="nx-contact-subject" name="nx_subject" maxlength="150">
                </div>

                <div class="nx-form-row">
                    <label for="nx-contact-message">Message</label>
                    <textarea id="nx-contact-message" name="nx_message" rows="6" required maxlength="3000"></textarea>
                </div>

                <button type="submit" class="nx-btn nx-primary">Send message</button>
            </form>
        </div>

        <?php
        return ob_get_clean();
    }

    public function handle() {

        $redirect = isset($_POST['redirect_to'])
            ? wp_validate_redirect(esc_url_raw(wp_unslash($_POST['redirect_to'])), home_url('/'))
            : home_url('/');

        $back = function ($status) use ($redirect) {
            wp_safe_redirect(add_query_arg('nx_contact', $status, remove_query_arg('nx_contact', $redirect)));
            exit;
        };

        if (!isset($_POST['nx_contact_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nx_contact_nonce'])), self::ACTION)) {
            $back('error');
        }

        // Honeypot filled: pretend success so bots learn nothing
        if (!empty($_POST['nx_website'])) {
            $back('sent');
        }

        $ip     = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $rl_key = 'nx_contact_' . md5($ip);
        $hits   = (int) get_transient($rl_key);

        if ($hits >= 3) {
            $back('limit');
        }

        $name    = sanitize_text_field(wp_unslash($_POST['nx_name'] ?? ''));
        $email   = sanitize_email(wp_unslash($_POST['nx_email'] ?? ''));
        $subject = sanitize_text_field(wp_unslash($_POST['nx_subject'] ?? ''));
        $message = sanitize_textarea_field(wp_unslash($_POST['nx_message'] ?? ''));

        if ($name === '' || !is_email($email) || $message === '') {
            $back('invalid');
        }

        $to = get_option('default_admin_mail');
        if (!is_email($to)) {
            $to = get_option('admin_email');
        }

        $subject = '[' . wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES) . '] '
            . ($subject !== '' ? $subject : 'New contact message');

        $body = "Name: {$name}\nEmail: {$email}\n\n{$message}\n";

        // name / email were sanitised (no line breaks), so they cannot inject headers
        $headers = ['Reply-To: ' . $name . ' <' . $email . '>'];

        set_transient($rl_key, $hits + 1, 10 * MINUTE_IN_SECONDS);

        $sent = wp_mail($to, $subject, $body, $headers);

        $back($sent ? 'sent' : 'error');
    }
}
