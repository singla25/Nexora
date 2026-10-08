<?php /** @var object[] $threads  admin thread rows plus user1, user2, other_user, last_message_text */ ?>

        <div class="wrap">
            <h1>💬 Nexora Chat (Admin)</h1>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Thread ID</th>
                        <th>Connection ID</th> <!-- ✅ NEW -->
                        <th>Status</th>        <!-- ✅ NEW -->
                        <th>User 1</th>
                        <th>User 2</th>
                        <th>Subject</th>
                        <th>Last Message</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>

                <?php if ($threads): foreach ($threads as $thread): ?>

                    <tr>
                        <td><?php echo esc_html($thread->id); ?></td>
                        <td><?php echo esc_html($thread->connection_id ?: '-'); ?></td>
                        <td>
                            <?php if ($thread->status === 'active'): ?>
                                <span style="color: green; font-weight: 600;">Active</span>
                            <?php else: ?>
                                <span style="color: red; font-weight: 600;">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($thread->user1); ?></td>
                        <td><?php echo esc_html($thread->user2); ?></td>
                        <td><?php echo esc_html($thread->subject ?: '-'); ?></td>
                        <td><?php echo esc_html($thread->last_message_text); ?></td>

                        <td>
                            <!-- <button class="button button-primary nexora-open-chat" data-thread="<?php echo $thread->id; ?>">
                                View Chat
                            </button> -->
                            <!-- <button class="button button-primary nexora-open-chat" 
                                    data-thread="<?php echo esc_attr($thread->id); ?>" 
                                    data-user="<?php echo esc_attr($thread->other_user); ?>" > 
                                View Chat 
                            </button> -->
                            <button 
                                class="button button-primary nexora-open-chat"
                                data-thread="<?php echo esc_attr($thread->id); ?>"
                                data-user="<?php echo esc_attr($thread->other_user); ?>"
                                data-name="<?php echo esc_attr($thread->user1 . ' and ' . $thread->user2); ?>"
                            >
                                View Chat
                            </button>
                        </td>
                    </tr>

                <?php endforeach; else: ?>

                    <tr>
                        <td colspan="5" style="text-align:center;">No chats found</td>
                    </tr>

                <?php endif; ?>

                </tbody>
            </table>
        </div>

