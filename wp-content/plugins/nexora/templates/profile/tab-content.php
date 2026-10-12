<?php /** Variables come from Nexora\Profile\Page::view_data(). */ ?>
					<!-- CONTENT -->
					<div class="tab-content" id="content">
						<div class="content-header">
							<div class="content-left">
								<h3><?php esc_html_e( 'Content', 'nexora' ); ?></h3>
								<span class="content-sub"><?php esc_html_e( 'See Content of Other Users', 'nexora' ); ?></span>
							</div>

							<div class="content-right">
								<button class="content-tab" data-type="add"><?php esc_html_e( 'Add New', 'nexora' ); ?></button>
								<button class="content-tab" data-type="history"><?php esc_html_e( 'History', 'nexora' ); ?></button>
							</div>
						</div>

						<div class="content-box">

							<?php if ( $any_posts ) : ?>

								<?php foreach ( $feed as $item ) : ?>

							<div class="content-card"
								data-title="<?php echo esc_attr( $item['title'] ); ?>"
								data-content="<?php echo esc_attr( $item['content'] ); ?>"
								data-image="<?php echo esc_url( $item['image'] ); ?>"
								data-username="<?php echo esc_attr( $item['user_name'] ); ?>"
								data-fullname="<?php echo esc_attr( $item['full_name'] ); ?>"
								data-date="<?php echo esc_attr( $item['date'] ); ?>"
								data-profile="<?php echo esc_url( $item['profile_link'] ); ?>"
							>

								<img src="<?php echo esc_url( $item['image'] ); ?>" class="content-img">

								<div class="content-body">
									<a href="<?php echo esc_url( $item['profile_link'] ); ?>" 
										class="content-user" target="_blank"
										onclick="event.stopPropagation();">
										<?php echo esc_html( $item['user_name'] ); ?>
									</a>

									<h4 class="content-title view-post"><?php echo esc_html( $item['title'] ); ?></h4>
								</div>

							</div>

							<?php endforeach; ?>

								<?php if ( ! $feed ) : ?>

								<!-- EMPTY STATE -->
								<div class="empty-content">
									<div class="empty-icon">📭</div>
									<h3><?php esc_html_e( 'No Content Yet', 'nexora' ); ?></h3>
									<p><?php esc_html_e( 'No one else has posted anything yet.', 'nexora' ); ?></p>
								</div>

							<?php endif; ?>

							<?php else : ?>

								<!-- OPTIONAL: if literally no posts exist at all -->
								<div class="empty-content">
									<div class="empty-icon">📭</div>
									<h3><?php esc_html_e( 'No Content Yet', 'nexora' ); ?></h3>
									<p><?php esc_html_e( 'No one else has posted anything yet.', 'nexora' ); ?></p>
								</div>

							<?php endif; ?>
						</div>
					</div>
