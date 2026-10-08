<?php /** Variables come from Nexora\Profile\Page::view_data(). */ extract($ctx); ?>
                    <!-- USER INFORMATION -->
                    <div class="tab-content active" id="user-info">
                        <div class="user-info-header">
                            <?php if ($is_owner): ?>
                                
                                <div class="user-info-left">
                                    <h3>Your Information</h3>
                                    <span class="user-info-sub">Manage your Informations</span>
                                </div>

                                <div class="user-info-right">
                                    <button class="user-edit-info active" data-type="personal-info">Personal</button>
                                    <button class="user-edit-info" data-type="address-info">Address</button>
                                    <button class="user-edit-info" data-type="work-info">Work</button>
                                    <button class="user-edit-info" data-type="docs-info">Documents</button>
                                    <button class="user-edit-info" data-type="security-info">Security</button>
                                </div>

                            <?php else: ?>
                                <div class="user-info-center">
                                    <h3>User Information</h3>
                                    <span class="user-info-sub">Login to explore more</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div id="user-info-content">
                            <!-- PERSONAL INFO -->
                            <div class="info-card">
                                <h3>Personal Information</h3>

                                <div class="info-grid">

                                    <div class="info-item">
                                        <span class="info-label">Username</span>
                                        <span class="info-value"><?php echo esc_html($username); ?></span>
                                    </div>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Email</span>
                                        <span class="info-value"><?php echo esc_html($email); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <div class="info-item">
                                        <span class="info-label">First Name</span>
                                        <span class="info-value"><?php echo esc_html($meta['first_name']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Last Name</span>
                                        <span class="info-value"><?php echo esc_html($meta['last_name']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Gender</span>
                                        <span class="info-value"><?php echo esc_html($meta['gender']); ?></span>
                                    </div>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Birthdate</span>
                                        <span class="info-value"><?php echo esc_html($meta['birthdate']); ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($is_owner): ?>
                                    <div class="info-item">
                                        <span class="info-label">Phone</span>
                                        <span class="info-value"><?php echo esc_html($phone); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <div class="info-item">
                                        <span class="info-label">LinkedIn</span>
                                        <span class="info-value"><?php echo esc_html($meta['linkedin_id']); ?></span>
                                    </div>

                                </div>

                                <div class="info-full">
                                    <span class="info-label">Bio</span>
                                    <p class="info-value"><?php echo esc_html($meta['bio']); ?></p>
                                </div>
                            </div>

                            <!-- ADDRESS INFO -->
                            <?php if ($is_owner): ?>
                            <div class="info-card">
                                <h3>Address Information</h3>

                                <!-- PERMANENT -->
                                <div class="info-section">
                                    <h4>Permanent Address</h4>

                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Address</span>
                                            <span class="info-value"><?php echo esc_html($meta['perm_address']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">City</span>
                                            <span class="info-value"><?php echo esc_html($meta['perm_city']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">State</span>
                                            <span class="info-value"><?php echo esc_html($meta['perm_state']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">Pincode</span>
                                            <span class="info-value"><?php echo esc_html($meta['perm_pincode']); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <!-- CORRESPONDENCE -->
                                <div class="info-section">
                                    <h4>Correspondence Address</h4>

                                    <div class="info-grid">
                                        <div class="info-item">
                                            <span class="info-label">Address</span>
                                            <span class="info-value"><?php echo esc_html($meta['corr_address']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">City</span>
                                            <span class="info-value"><?php echo esc_html($meta['corr_city']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">State</span>
                                            <span class="info-value"><?php echo esc_html($meta['corr_state']); ?></span>
                                        </div>

                                        <div class="info-item">
                                            <span class="info-label">Pincode</span>
                                            <span class="info-value"><?php echo esc_html($meta['corr_pincode']); ?></span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <!-- WORK INFO -->
                            <div class="info-card">
                                <h3>Work Information</h3>

                                <div class="info-grid">

                                    <div class="info-item">
                                        <span class="info-label">Company Name</span>
                                        <span class="info-value"><?php echo esc_html($meta['company_name']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Designation</span>
                                        <span class="info-value"><?php echo esc_html($meta['designation']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Email</span>
                                        <span class="info-value"><?php echo esc_html($meta['company_email']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Phone</span>
                                        <span class="info-value"><?php echo esc_html($meta['company_phone']); ?></span>
                                    </div>

                                    <div class="info-item">
                                        <span class="info-label">Company Address</span>
                                        <span class="info-value"><?php echo esc_html($meta['company_address']); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- DOCUMENTS -->
                            <div class="info-card">
                                <h3>Documents</h3>

                                <div class="doc-grid">

                                    <?php foreach ($docs as $doc): ?>

                                        <div class="doc-card">
                                            <span class="doc-title"><?php echo esc_html($doc['label']); ?></span>

                                            <?php if ($doc['url']): ?>
                                                <a href="<?php echo esc_url($doc['url']); ?>" target="_blank">
                                                    <img src="<?php echo esc_url($doc['url']); ?>" class="doc-img">
                                                </a>
                                            <?php else: ?>
                                                <div class="doc-empty-box">No File</div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>

