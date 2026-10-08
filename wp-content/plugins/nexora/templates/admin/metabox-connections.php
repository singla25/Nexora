<?php /** @var array[] $received  @var array[] $sent  rows: profile_id, user_name, status */ ?>

        <h2>📥 Received Requests</h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Sender Profile ID</th>
                    <th>Sender Username</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($received): foreach ($received as $row): ?>

                <tr>
                    <td><?php echo esc_html($row['profile_id']); ?></td>
                    <td><?php echo esc_html($row['user_name']); ?></td>
                    <td><?php echo esc_html($row['status']); ?></td>
                </tr>

            <?php endforeach; else: ?>

                <tr><td colspan="3">No received requests</td></tr>

            <?php endif; ?>

            </tbody>
        </table>


        <br><br>

        <h2>📤 Sent Requests</h2>

        <table class="widefat striped">
            <thead>
                <tr>
                    <th>Receiver Profile ID</th>
                    <th>Receiver Username</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>

            <?php if ($sent): foreach ($sent as $row): ?>

                <tr>
                    <td><?php echo esc_html($row['profile_id']); ?></td>
                    <td><?php echo esc_html($row['user_name']); ?></td>
                    <td><?php echo esc_html($row['status']); ?></td>
                </tr>

            <?php endforeach; else: ?>

                <tr><td colspan="3">No sent requests</td></tr>

            <?php endif; ?>

            </tbody>
        </table>

        