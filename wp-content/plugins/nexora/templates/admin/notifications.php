<?php /** @var object[] $notifications  rows from the notifications table */ ?>
		<div class="wrap">
			<h1><?php esc_html_e( '🔔 Notifications', 'nexora' ); ?></h1>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Actor', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Receiver', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Type', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Message', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Date', 'nexora' ); ?></th>
					</tr>
				</thead>
				<tbody>

				<?php
				if ( $notifications ) :
					foreach ( $notifications as $n ) :
						?>

					<tr>
						<td><?php echo esc_html( $n->id ); ?></td>
						<td><?php echo esc_html( $n->actor_user_name ); ?></td>
						<td><?php echo esc_html( $n->receiver_user_name ); ?></td>
						<td><?php echo esc_html( $n->type ); ?></td>
						<td><?php echo esc_html( $n->message ); ?></td>
						<td>
												<?php if ( $n->is_read ) : ?>
								<span style="color: grey; font-weight: 600;"><?php esc_html_e( 'Read', 'nexora' ); ?></span>
							<?php else : ?>
								<span style="color: green; font-weight: 600;"><?php esc_html_e( 'Unread', 'nexora' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $n->created_at ); ?></td>
					</tr>

									<?php endforeach; else : ?>

					<tr><td colspan="7" style="text-align: center;"><?php esc_html_e( 'No notifications found', 'nexora' ); ?></td></tr>

				<?php endif; ?>

				</tbody>
			</table>
		</div>
