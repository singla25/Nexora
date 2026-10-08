<?php /** @var WP_User $user */ ?>
<div class="register-state-wrapper">

    <div class="register-state-card">

        <div class="register-avatar">
            <span><?php echo esc_html(mb_strtoupper(mb_substr($user->display_name, 0, 1))); ?></span>
        </div>

        <h2>Hey <?php echo esc_html($user->display_name); ?> 👋</h2>
        <p>You are already logged in</p>

        <a href="<?php echo esc_url($profile_url); ?>" class="btn-primary">
            Go to Profile
        </a>

    </div>

</div>
