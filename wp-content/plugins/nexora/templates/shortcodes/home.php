<?php
/**
 * [nexora_home]
 *
 * @var bool     $logged_in
 * @var WP_User  $user          current user (when logged in)
 * @var string   $primary_url   where the logged-in call to action goes
 * @var string   $login_url
 * @var string   $reg_url
 * @var string   $eyebrow
 * @var string   $title
 * @var string   $subtitle
 * @var string   $cover         hero image URL ('' for none)
 * @var string[] $stats         members, connections, posts, chats as display strings (e.g. 1.2K)
 * @var array[]  $features      [title, description]
 * @var array[]  $shots         [image url, caption]
 * @var array[]  $testimonials  [name, role, quote]
 */
?>

		<div class="nexora">

			<!-- HERO -->
			<section class="nx-hero">
				<div class="nx-container nx-hero-inner">

					<div class="nx-hero-content">
						<p class="nx-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>

						<h1 class="nx-title"><?php echo esc_html( $title ); ?></h1>

						<p class="nx-subtitle"><?php echo esc_html( $subtitle ); ?></p>

						<div class="nx-cta">
							<?php if ( $logged_in ) : ?>
								<a href="<?php echo esc_url( $primary_url ); ?>" class="nx-btn nx-primary">
									<?php echo esc_html( sprintf( 'Welcome back, %s', $user->display_name ) ); ?> &rarr;
								</a>
							<?php else : ?>
								<a href="<?php echo esc_url( $reg_url ); ?>" class="nx-btn nx-primary">Get Started</a>
								<a href="<?php echo esc_url( $login_url ); ?>" class="nx-btn nx-outline">Login</a>
							<?php endif; ?>
						</div>
					</div>

					<?php if ( $cover ) : ?>
						<div class="nx-hero-preview">
							<div class="nx-glass-card">
								<img src="<?php echo esc_url( $cover ); ?>" alt="">
							</div>
						</div>
					<?php endif; ?>

				</div>
			</section>

			<!-- LIVE STATS -->
			<section class="nx-section nx-stats">
				<div class="nx-container">
					<div class="nx-stats-grid">
						<div class="nx-stat-box">
							<h3><?php echo esc_html( $stats['members'] ); ?></h3>
							<p>Members</p>
						</div>
						<div class="nx-stat-box">
							<h3><?php echo esc_html( $stats['connections'] ); ?></h3>
							<p>Connections</p>
						</div>
						<div class="nx-stat-box">
							<h3><?php echo esc_html( $stats['posts'] ); ?></h3>
							<p>Posts shared</p>
						</div>
						<div class="nx-stat-box">
							<h3><?php echo esc_html( $stats['chats'] ); ?></h3>
							<p>Conversations</p>
						</div>
					</div>
				</div>
			</section>

			<!-- FEATURES (editable in Nexora > Settings) -->
			<section class="nx-section">
				<div class="nx-container">
					<h2 class="nx-section-title">Why Nexora?</h2>

					<div class="nx-grid">
						<?php foreach ( $features as $feature ) : ?>
							<div class="nx-card">
								<h4><?php echo esc_html( $feature[0] ); ?></h4>
								<p><?php echo esc_html( $feature[1] ); ?></p>
							</div>
						<?php endforeach; ?>
					</div>
				</div>
			</section>

			<!-- HOW IT WORKS -->
			<section class="nx-section nx-steps">
				<div class="nx-container">
					<h2 class="nx-section-title">How it works</h2>

					<div class="nx-steps-grid">
						<div class="nx-step"><span>1</span><p>Sign up</p></div>
						<div class="nx-step"><span>2</span><p>Build your profile</p></div>
						<div class="nx-step"><span>3</span><p>Connect</p></div>
						<div class="nx-step"><span>4</span><p>Share &amp; chat</p></div>
					</div>
				</div>
			</section>


			<?php if ( $shots ) : ?>
				<!-- PREVIEW (only the images set in Settings) -->
				<section class="nx-section">
					<div class="nx-container">
						<h2 class="nx-section-title">A look inside</h2>

						<div class="nx-demo-grid">
							<?php foreach ( $shots as $shot ) : ?>
								<div class="nx-demo-card">
									<img src="<?php echo esc_url( $shot[0] ); ?>" alt="<?php echo esc_attr( $shot[1] ); ?>">
									<p><?php echo esc_html( $shot[1] ); ?></p>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( $testimonials ) : ?>
				<!-- TESTIMONIALS (editable in Nexora > Settings; hidden when empty) -->
				<section class="nx-section">
					<div class="nx-container">
						<h2 class="nx-section-title">Loved by members</h2>

						<div class="nx-test-grid">
							<?php foreach ( $testimonials as $t ) : ?>
								<div class="nx-test-card">
									<p class="nx-quote">&ldquo;<?php echo esc_html( $t[2] ); ?>&rdquo;</p>
									<div class="nx-test-top">
										<strong><?php echo esc_html( $t[0] ); ?></strong>
										<?php if ( $t[1] !== '' ) : ?>
											<span><?php echo esc_html( $t[1] ); ?></span>
										<?php endif; ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</div>
				</section>
			<?php endif; ?>

			<?php if ( ! $logged_in ) : ?>
				<!-- CTA -->
				<section class="nx-final-cta">
					<h2>Join Nexora today</h2>
					<a href="<?php echo esc_url( $reg_url ); ?>" class="nx-btn nx-btn--inverse">Get Started</a>
				</section>
			<?php endif; ?>

		</div>

