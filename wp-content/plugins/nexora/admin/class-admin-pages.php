<?php
/**
 * admin/class-admin-pages.php
 *
 * Renders the three admin menu pages:
 *   - Settings  (images, email, reCAPTCHA)
 *   - Notifications
 *   - Nexora Chat
 *
 * Registered as menu pages in NEXORA_CPT_Register::register_main_menu().
 * No hooks live here — this class is purely HTML output.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_Admin_Pages {

    /* =========================================================================
       SETTINGS PAGE
    ========================================================================= */

    public function settings_page(): void {
        ?>
        <div class="wrap">
            <h1>Profile System Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'profile_settings_group' );
                do_settings_sections( 'profile_settings_group' );
                ?>

                <table class="form-table">

                    <?php
                    // ── Image upload settings ──────────────────────────
                    $image_settings = [
                        'default_profile_image'         => 'Default Profile Image',
                        'default_cover_image'           => 'Default Cover Image',
                        'default_document_image'        => 'Default Document Image',
                        'default_home_cover_image'      => 'Default Home Cover Image',
                        'default_feed_experience_image' => 'Default Feed Experience Image',
                        'default_real_time_chat_image'  => 'Default Real-Time Chat Image',
                        'default_smart_connections_image' => 'Default Smart Connections Image',
                    ];

                    foreach ( $image_settings as $option_key => $label ) :
                        $attachment_id = get_option( $option_key );
                        $url           = $attachment_id ? wp_get_attachment_url( $attachment_id ) : '';
                    ?>
                        <tr>
                            <th><?php echo esc_html( $label ); ?></th>
                            <td>
                                <img src="<?php echo esc_url( $url ); ?>"
                                     style="max-width:150px; display:<?php echo $url ? 'block' : 'none'; ?>; margin-bottom:10px;">
                                <input type="hidden"
                                       name="<?php echo esc_attr( $option_key ); ?>"
                                       value="<?php echo esc_attr( $attachment_id ); ?>">
                                <button type="button" class="button upload-btn">Upload</button>
                                <button type="button" class="button remove-btn">Remove</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- ── Admin email ──────────────────────────────── -->
                    <tr>
                        <th>Admin Notification Email</th>
                        <td>
                            <input type="email"
                                   name="default_admin_mail"
                                   value="<?php echo esc_attr( get_option( 'default_admin_mail' ) ); ?>"
                                   class="regular-text"
                                   placeholder="Enter admin email">
                            <p class="description">All registration notifications will be sent to this email.</p>
                        </td>
                    </tr>

                    <!-- ── reCAPTCHA ────────────────────────────────── -->
                    <tr>
                        <th>Google reCAPTCHA Site Key</th>
                        <td>
                            <input type="text"
                                   name="recaptcha_site_key"
                                   value="<?php echo esc_attr( get_option( 'recaptcha_site_key' ) ); ?>"
                                   class="regular-text"
                                   placeholder="Enter Site Key">
                            <p class="description">Used on frontend forms.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Google reCAPTCHA Secret Key</th>
                        <td>
                            <input type="password"
                                   name="recaptcha_secret_key"
                                   value="<?php echo get_option( 'recaptcha_secret_key' ) ? esc_attr( '************' ) : ''; ?>"
                                   class="regular-text"
                                   placeholder="Enter Secret Key">
                            <p class="description">Used for backend verification. Keep it secure.</p>
                        </td>
                    </tr>

                    <tr>
                        <th>Enable reCAPTCHA</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="recaptcha_enabled"
                                       value="1"
                                       <?php checked( get_option( 'recaptcha_enabled' ), 1 ); ?>>
                                Enable Google reCAPTCHA
                            </label>
                            <p class="description">Enable captcha protection on login, registration and forms.</p>
                        </td>
                    </tr>

                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /* =========================================================================
       NOTIFICATIONS PAGE
    ========================================================================= */

    public function notifications_page(): void {

        $notifications = ( new NEXORA_Notification() )->get_all();
        ?>

        <div class="wrap">
            <h1>Notifications</h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th><th>Actor</th><th>Receiver</th>
                        <th>Type</th><th>Message</th><th>Status</th><th>Date</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ( $notifications ) : foreach ( $notifications as $n ) : ?>

                    <tr>
                        <td><?php echo esc_html( $n->id ); ?></td>
                        <td><?php echo esc_html( $n->actor_user_name ); ?></td>
                        <td><?php echo esc_html( $n->receiver_user_name ); ?></td>
                        <td><?php echo esc_html( $n->type ); ?></td>
                        <td><?php echo esc_html( $n->message ); ?></td>
                        <td>
                            <?php if ( $n->is_read ) : ?>
                                <span style="color:grey;font-weight:600;">Read</span>
                            <?php else : ?>
                                <span style="color:green;font-weight:600;">Unread</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $n->created_at ); ?></td>
                    </tr>

                <?php endforeach; else : ?>
                    <tr><td colspan="7" style="text-align:center;">No notifications found</td></tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>
        <?php
    }

    /* =========================================================================
       CHAT PAGE
    ========================================================================= */

    public function chat_page(): void {

        $threads = ( new NEXORA_CHAT_DB() )->get_all_threads_with_last_message();
        ?>

        <div class="wrap">
            <h1>Nexora Chat (Admin)</h1>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Thread ID</th><th>Connection ID</th><th>Status</th>
                        <th>User 1</th><th>User 2</th><th>Subject</th>
                        <th>Last Message</th><th>Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ( $threads ) : foreach ( $threads as $thread ) :

                    [ $user1, $user2, $other_user ] = $this->resolve_thread_users( $thread );
                    $last_message = $thread->last_message ? wp_trim_words( $thread->last_message, 10 ) : '-';
                ?>

                    <tr>
                        <td><?php echo esc_html( $thread->id ); ?></td>
                        <td><?php echo esc_html( $thread->connection_id ?: '-' ); ?></td>
                        <td>
                            <?php if ( $thread->status === 'active' ) : ?>
                                <span style="color:green;font-weight:600;">Active</span>
                            <?php else : ?>
                                <span style="color:red;font-weight:600;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $user1 ); ?></td>
                        <td><?php echo esc_html( $user2 ); ?></td>
                        <td><?php echo esc_html( $thread->subject ?: '-' ); ?></td>
                        <td><?php echo esc_html( $last_message ); ?></td>
                        <td>
                            <button class="button button-primary nexora-open-chat"
                                    data-thread="<?php echo esc_attr( $thread->id ); ?>"
                                    data-user="<?php echo esc_attr( $other_user ); ?>"
                                    data-name="<?php echo esc_attr( $user1 . ' and ' . $user2 ); ?>">
                                View Chat
                            </button>
                        </td>
                    </tr>

                <?php endforeach; else : ?>
                    <tr><td colspan="8" style="text-align:center;">No chats found</td></tr>
                <?php endif; ?>

                </tbody>
            </table>
        </div>
        <?php
    }

    /* =========================================================================
       PRIVATE HELPERS
    ========================================================================= */

    /**
     * Resolve display names and "other user" ID from a chat thread row.
     *
     * @param object $thread  Row from get_all_threads_with_last_message()
     * @return array  [ string $user1, string $user2, int $other_user_id ]
     */
    private function resolve_thread_users( object $thread ): array {

        $user_ids = explode( ',', $thread->participants );

        $user1 = '-';
        $user2 = '-';

        if ( ! empty( $user_ids[0] ) ) {
            $u = get_userdata( $user_ids[0] );
            $user1 = $u ? $u->display_name : '-';
        }

        if ( ! empty( $user_ids[1] ) ) {
            $u = get_userdata( $user_ids[1] );
            $user2 = $u ? $u->display_name : '-';
        }

        // "Other user" for the chat button — pick whichever is not the current admin
        $other_user = $user_ids[1] ?? $user_ids[0] ?? 0;

        return [ $user1, $user2, (int) $other_user ];
    }
}
