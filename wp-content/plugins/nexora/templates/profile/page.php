<?php /** Variables come from Nexora\Profile\Page::view_data(). */ ?>
		<div class="profile-container">
			<div class="profile-wrapper">

				<!-- COVER -->
				<div class="profile-cover" style="background-image:url('<?php echo esc_url( $cover_image ); ?>')"></div>

				<!-- HEADER -->
				<div class="profile-header">
					<img src="<?php echo esc_url( $profile_image ); ?>" class="profile-avatar">
					<h2><?php echo esc_html( $username ); ?></h2>
					<h4><?php echo esc_html( $name ); ?></h4>
					<?php if ( $is_owner ) : ?>
						<p><?php echo esc_html( $email ); ?> | <?php echo esc_html( $phone ); ?></p>
						<p class="profile-welcome">
							<?php
							/* translators: %s: the member's first name. */
							echo esc_html( sprintf( __( 'Welcome back, %s', 'nexora' ), $welcome_name ) );
							?>
						</p>
						<div class="nx-stat-bar profile-stats">
							<?php foreach ( $stats as $nx_stat ) : ?>
								<span class="nx-stat" data-stat="<?php echo esc_attr( $nx_stat['key'] ); ?>"><strong><?php echo (int) $nx_stat['value']; ?></strong> <?php echo esc_html( $nx_stat['label'] ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>

				<!-- TABS -->
				<div class="profile-tabs">
					<?php foreach ( $tabs as $nx_tab ) : ?>
						<?php if ( 'user-info' === $nx_tab ) : ?>
							<button class="tab-btn active" data-tab="user-info"><?php esc_html_e( 'User Information', 'nexora' ); ?></button>
						<?php elseif ( 'connections' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="connections"><?php esc_html_e( 'Connections', 'nexora' ); ?></button>
						<?php elseif ( 'content' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="content"><?php esc_html_e( 'Content', 'nexora' ); ?></button>
						<?php elseif ( 'marketplace' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="marketplace"><?php esc_html_e( 'Marketplace', 'nexora' ); ?></button>
						<?php elseif ( 'my-products' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="my-products"><?php esc_html_e( 'My Products', 'nexora' ); ?></button>
						<?php elseif ( 'my-orders' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="my-orders"><?php esc_html_e( 'My Orders', 'nexora' ); ?></button>
						<?php elseif ( 'sales' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="sales"><?php esc_html_e( 'Sales', 'nexora' ); ?></button>
						<?php elseif ( 'notifications' === $nx_tab ) : ?>
							<button class="tab-btn" data-tab="notifications">
								<?php esc_html_e( 'Notifications', 'nexora' ); ?>
								<?php if ( $unread_count > 0 ) : ?>
									<span class="noti-badge">
										<?php echo (int) $unread_count; ?>
									</span>
								<?php endif; ?>
							</button>
						<?php endif; ?>
					<?php endforeach; ?>
				</div>

				<!-- MAIN CONTENT -->
				<div class="profile-content">
<?php foreach ( array( 'tab-info', 'tab-connections', 'tab-notifications', 'tab-content' ) as $partial ) : ?>
	<?php \Nexora\Core\View::output( 'profile/' . $partial, $vars ); ?>
<?php endforeach; ?>
<?php
foreach ( array(
	'marketplace' => 'tab-marketplace',
	'my-products' => 'tab-my-products',
	'my-orders'   => 'tab-my-orders',
	'sales'       => 'tab-sales',
) as $nx_tab => $nx_partial ) :
	?>
	<?php if ( in_array( $nx_tab, $tabs, true ) ) : ?>
		<?php \Nexora\Core\View::output( 'profile/' . $nx_partial, $vars ); ?>
	<?php endif; ?>
<?php endforeach; ?>
				</div>
			</div>
		</div>

