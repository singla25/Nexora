<?php /** @var object[] $notifications  rows from the notifications table */ ?>
        <div class="wrap">
            <h1>🔔 Notifications</h1>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Actor</th>
                        <th>Receiver</th>
                        <th>Type</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ($notifications): foreach ($notifications as $n): ?>

                    <tr>
                        <td><?php echo esc_html($n->id); ?></td>
                        <td><?php echo esc_html($n->actor_user_name); ?></td>
                        <td><?php echo esc_html($n->receiver_user_name); ?></td>
                        <td><?php echo esc_html($n->type); ?></td>
                        <td><?php echo esc_html($n->message); ?></td>
                        <td>
                            <?php if ($n->is_read): ?>
                                <span style="color: grey; font-weight: 600;">Read</span>
                            <?php else: ?>
                                <span style="color: green; font-weight: 600;">Unread</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($n->created_at); ?></td>
                    </tr>

                <?php endforeach; else: ?>

                    <tr><td colspan="7" style="text-align: center;">No notifications found</td></tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>
