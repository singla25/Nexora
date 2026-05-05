<?php
/**
 * admin/class-metabox-user.php
 *
 * Metabox render callbacks for the user_profile CPT only.
 *
 * ── Adding a new user metabox ─────────────────────────────────────────────
 *   1. Add the render method here.
 *   2. Register it in NEXORA_CPT_Register::add_meta_boxes().
 *   3. Add the field key(s) to NEXORA_CPT_Register::SAVE_FIELDS['user_profile'].
 *   That's it — saving is handled automatically.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class NEXORA_Metabox_User {

    /* =========================================================================
       PERSONAL
    ========================================================================= */

    public function personal_details( WP_Post $post ): void {
        NEXORA_CPT_Field_Renderer::personal_fields( $post );
    }

    /* =========================================================================
       ADDRESS
    ========================================================================= */

    public function address_details( WP_Post $post ): void {
        NEXORA_CPT_Field_Renderer::address_fields( $post );
    }

    /* =========================================================================
       WORK
    ========================================================================= */

    public function work_details( WP_Post $post ): void {

        $m = fn( $key ) => esc_attr( get_post_meta( $post->ID, $key, true ) );
        ?>

        <input type="text"  name="company_name"    placeholder="Company Name"    value="<?php echo $m( 'company_name' ); ?>"    class="widefat"><br><br>
        <input type="text"  name="designation"     placeholder="Designation"     value="<?php echo $m( 'designation' ); ?>"     class="widefat"><br><br>
        <input type="email" name="company_email"   placeholder="Company Email"   value="<?php echo $m( 'company_email' ); ?>"   class="widefat"><br><br>
        <input type="text"  name="company_phone"   placeholder="Company Phone"   value="<?php echo $m( 'company_phone' ); ?>"   class="widefat"><br><br>
        <input type="text"  name="company_address" placeholder="Company Address" value="<?php echo $m( 'company_address' ); ?>" class="widefat"><br><br>

        <?php
    }

    /* =========================================================================
       DOCUMENTS
    ========================================================================= */

    public function document_details( WP_Post $post ): void {

        $fields = [
            'profile_image'   => 'Profile Image',
            'cover_image'     => 'Cover Image',
            'aadhaar_card'    => 'Aadhaar Card',
            'driving_license' => 'Driving License',
            'company_id_card' => 'Company ID Card',
        ];

        foreach ( $fields as $key => $label ) {
            NEXORA_CPT_Field_Renderer::document_field( $post->ID, $key, $label );
        }
    }

    /* =========================================================================
       CONNECTIONS (read-only overview)
    ========================================================================= */

    public function connection_details( WP_Post $post ): void {

        $profile_id = $post->ID;

        $received = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'receiver_profile_id', 'value' => $profile_id ]],
        ]);

        $sent = get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'sender_profile_id', 'value' => $profile_id ]],
        ]);

        echo '<h2>Received Requests</h2>';
        $this->render_connection_table( $received, 'sender' );

        echo '<br><h2>Sent Requests</h2>';
        $this->render_connection_table( $sent, 'receiver' );
    }

    /* =========================================================================
       CONTENT (read-only overview)
    ========================================================================= */

    public function content_details( WP_Post $post ): void {

        $contents = get_posts([
            'post_type'      => 'user_content',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [[ 'key' => 'user_profile_id', 'value' => $post->ID, 'compare' => '=' ]],
        ]);
        ?>

        <table class="widefat striped">
            <thead>
                <tr><th>Title</th><th>Date</th><th>Action</th></tr>
            </thead>
            <tbody>
            <?php if ( $contents ) : foreach ( $contents as $c ) : ?>
                <tr>
                    <td><?php echo esc_html( $c->post_title ); ?></td>
                    <td><?php echo esc_html( get_the_date( 'Y-m-d H:i:s', $c->ID ) ); ?></td>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $c->ID . '&action=edit' ) ); ?>"
                           class="button button-primary">View</a>
                    </td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="3" style="text-align:center">No content found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
    }

    /* =========================================================================
       CHAT (read-only overview)
    ========================================================================= */

    public function chat_details( WP_Post $post ): void {

        $user_id = (int) get_post_meta( $post->ID, '_wp_user_id', true );

        if ( ! $user_id ) {
            echo '<p>No user linked.</p>';
            return;
        }

        $connections = $this->get_connections_by_user( $user_id );

        if ( ! $connections ) {
            echo '<p>No connections found.</p>';
            return;
        }

        $chat_db = new NEXORA_CHAT_DB();

        echo '<h3>User Chat Overview</h3>';
        echo '<table class="widefat striped" style="font-size:13px;">';
        echo '<thead><tr>
                <th>User</th><th>Connection ID</th><th>Status</th>
                <th>Connection Time</th><th>Threads</th>
              </tr></thead><tbody>';

        foreach ( $connections as $conn ) {

            $conn_id     = $conn->ID;
            $sender_id   = get_post_meta( $conn_id, 'sender_user_id', true );
            $receiver_id = get_post_meta( $conn_id, 'receiver_user_id', true );
            $status      = get_post_meta( $conn_id, 'status', true );

            $other_id   = ( (int) $sender_id === $user_id ) ? $receiver_id : $sender_id;
            $other_user = get_userdata( $other_id );
            $other_name = $other_user ? $other_user->display_name : '-';

            $status_color = match ( $status ) {
                'accepted' => '#16a34a',
                'pending'  => '#f59e0b',
                'removed'  => '#6b7280',
                default    => '#dc2626',
            };

            $threads = $chat_db->get_threads_by_connection( $conn_id );

            echo '<tr>';
            echo '<td><strong>' . esc_html( $other_name ) . '</strong></td>';
            echo '<td>#' . esc_html( $conn_id ) . '</td>';
            echo '<td><span style="color:white;background:' . esc_attr( $status_color ) . ';padding:3px 8px;border-radius:4px;font-size:12px;">'
                 . esc_html( $status ) . '</span></td>';
            echo '<td>' . esc_html( get_the_date( 'd M Y, H:i', $conn_id ) ) . '</td>';
            echo '<td>';

            if ( $threads ) {
                echo '<ul style="margin:0;">';
                foreach ( $threads as $t ) {
                    $tcolor  = $t->status === 'active' ? '#16a34a' : '#dc2626';
                    $subject = $t->subject ?: 'No Subject';
                    echo '<li style="margin-bottom:5px;">
                            <strong>' . esc_html( $subject ) . '</strong>
                            <span style="color:' . esc_attr( $tcolor ) . ';font-weight:600;margin-left:6px;">
                                &bull; ' . esc_html( $t->status ) . '
                            </span>
                          </li>';
                }
                echo '</ul>';
            } else {
                echo '<span style="color:#6b7280;">No conversations</span>';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
    }

    /* =========================================================================
       PRIVATE HELPERS
    ========================================================================= */

    /**
     * Render a connections table (received or sent).
     *
     * @param WP_Post[] $connections
     * @param string    $party  'sender' or 'receiver' — which party's info to show
     */
    private function render_connection_table( array $connections, string $party ): void {

        $id_key   = $party . '_profile_id';
        $name_key = $party . '_user_name';
        ?>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html( ucfirst( $party ) ); ?> Profile ID</th>
                    <th><?php echo esc_html( ucfirst( $party ) ); ?> Username</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ( $connections ) : foreach ( $connections as $conn ) : ?>
                <tr>
                    <td><?php echo esc_html( get_post_meta( $conn->ID, $id_key,   true ) ); ?></td>
                    <td><?php echo esc_html( get_post_meta( $conn->ID, $name_key, true ) ); ?></td>
                    <td><?php echo esc_html( get_post_meta( $conn->ID, 'status',  true ) ); ?></td>
                </tr>
            <?php endforeach; else : ?>
                <tr><td colspan="3">No records</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php
    }

    /**
     * Get all connections (sent or received) for a WP user ID.
     *
     * @return WP_Post[]
     */
    private function get_connections_by_user( int $user_id ): array {

        return get_posts([
            'post_type'      => 'user_connections',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => 'sender_user_id',   'value' => $user_id ],
                [ 'key' => 'receiver_user_id', 'value' => $user_id ],
            ],
        ]);
    }
}
