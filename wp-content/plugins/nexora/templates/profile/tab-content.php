<?php /** Variables come from Nexora\Profile\Page::view_data(). */ extract($ctx); ?>
                    <!-- CONTENT -->
                    <div class="tab-content" id="content">
                        <div class="content-header">
                            <div class="content-left">
                                <h3>Content</h3>
                                <span class="content-sub">See Content of Other Users</span>
                            </div>

                            <div class="content-right">
                                <button class="content-tab" data-type="add">Add New</button>
                                <button class="content-tab" data-type="history">History</button>
                            </div>
                        </div>

                        <div class="content-box">

                            <?php if ($any_posts): ?>

                            <?php foreach ($feed as $item): ?>

                            <div class="content-card"
                                data-title="<?php echo esc_attr($item['title']); ?>"
                                data-content="<?php echo esc_attr($item['content']); ?>"
                                data-image="<?php echo esc_url($item['image']); ?>"
                                data-username="<?php echo esc_attr($item['user_name']); ?>"
                                data-fullname="<?php echo esc_attr($item['full_name']); ?>"
                                data-date="<?php echo esc_attr($item['date']); ?>"
                                data-profile="<?php echo esc_url($item['profile_link']); ?>"
                            >

                                <img src="<?php echo esc_url($item['image']); ?>" class="content-img">

                                <div class="content-body">
                                    <a href="<?php echo esc_url($item['profile_link']); ?>" 
                                        class="content-user" target="_blank"
                                        onclick="event.stopPropagation();">
                                        <?php echo esc_html($item['user_name']); ?>
                                    </a>

                                    <h4 class="content-title view-post"><?php echo esc_html($item['title']); ?></h4>
                                </div>

                            </div>

                            <?php endforeach; ?>

                            <?php if (!$feed): ?>

                                <!-- EMPTY STATE -->
                                <div class="empty-content">
                                    <div class="empty-icon">📭</div>
                                    <h3>No Content Yet</h3>
                                    <p>No one else has posted anything yet.</p>
                                </div>

                            <?php endif; ?>

                            <?php else: ?>

                                <!-- OPTIONAL: if literally no posts exist at all -->
                                <div class="empty-content">
                                    <div class="empty-icon">📭</div>
                                    <h3>No Content Yet</h3>
                                    <p>No one else has posted anything yet.</p>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
