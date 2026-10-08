<?php /** Variables come from Nexora\Profile\Page::view_data(). */ extract($ctx); ?>
        <div class="profile-container">
            <div class="profile-wrapper">

                <!-- COVER -->
                <div class="profile-cover" style="background-image:url('<?php echo esc_url($cover_image); ?>')"></div>

                <!-- HEADER -->
                <div class="profile-header">
                    <img src="<?php echo esc_url($profile_image); ?>" class="profile-avatar">
                    <h2><?php echo esc_html($username); ?></h2>
                    <h4><?php echo esc_html($name); ?></h4>
                    <?php if ($is_owner): ?>
                        <p><?php echo esc_html($email); ?> | <?php echo esc_html($phone); ?></p>
                    <?php endif; ?>
                </div>

                <!-- TABS -->
                <div class="profile-tabs">
                    <button class="tab-btn active" data-tab="user-info">User Information</button>
                    <button class="tab-btn" data-tab="connections">Connections</button>
                    <?php if ($is_owner): ?>
                        <button class="tab-btn" data-tab="content">Content</button>
                        <button class="tab-btn" data-tab="notifications">
                            Notifications
                            <?php if ($unread_count > 0): ?>
                                <span class="noti-badge">
                                    <?php echo (int) $unread_count; ?>
                                </span>
                            <?php endif; ?>
                        </button>
                    <?php endif; ?>
                </div>

                <!-- MAIN CONTENT -->
                <div class="profile-content">
                    
<?php foreach (['tab-info', 'tab-connections', 'tab-notifications', 'tab-content'] as $tab): ?>
<?php \Nexora\Core\View::output('profile/' . $tab, ['ctx' => $ctx]); ?>
<?php endforeach; ?>
                </div>
            </div>

            <!-- LOG OUT -->
            <?php if ($is_owner): ?>                    
                <div style="text-align:center; margin-top:30px;">
                    <a class="logout-btn" href="<?php echo esc_url($logout_url); ?>" 
                    style="display:inline-block; padding:12px 25px; background:#ef4444; color:#fff; border-radius:10px; text-decoration:none;">
                        Logout
                    </a>
                </div>
            <?php endif; ?>
        </div>

