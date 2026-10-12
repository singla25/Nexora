<?php /** @var object[] $threads  admin thread rows plus user1, user2, other_user, last_message_text */ ?>

		<div class="wrap">
			<h1><?php esc_html_e( '💬 Nexora Chat (Admin)', 'nexora' ); ?></h1>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Thread ID', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Connection ID', 'nexora' ); ?></th> <!-- ✅ NEW -->
						<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>        <!-- ✅ NEW -->
						<th><?php esc_html_e( 'User 1', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'User 2', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Subject', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Last Message', 'nexora' ); ?></th>
						<th><?php esc_html_e( 'Action', 'nexora' ); ?></th>
					</tr>
				</thead>
				<tbody>

				<?php
				if ( $threads ) :
					foreach ( $threads as $thread ) :
						?>

					<tr>
						<td><?php echo esc_html( $thread->id ); ?></td>
						<td><?php echo esc_html( ! empty( $thread->connection_id ) ? $thread->connection_id : '-' ); ?></td>
						<td>
												<?php if ( 'active' === $thread->status ) : ?>
								<span style="color: green; font-weight: 600;"><?php esc_html_e( 'Active', 'nexora' ); ?></span>
							<?php else : ?>
								<span style="color: red; font-weight: 600;"><?php esc_html_e( 'Inactive', 'nexora' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $thread->user1 ); ?></td>
						<td><?php echo esc_html( $thread->user2 ); ?></td>
						<td><?php echo esc_html( ! empty( $thread->subject ) ? $thread->subject : '-' ); ?></td>
						<td><?php echo esc_html( $thread->last_message_text ); ?></td>

						<td>
							<button 
								class="button button-primary nexora-open-chat"
								data-thread="<?php echo esc_attr( $thread->id ); ?>"
								data-user="<?php echo esc_attr( $thread->other_user ); ?>"
								data-name="<?php echo esc_attr( $thread->user1 . ' and ' . $thread->user2 ); ?>"
							>
								<?php esc_html_e( 'View Chat', 'nexora' ); ?>
							</button>
						</td>
					</tr>

									<?php endforeach; else : ?>

					<tr>
						<td colspan="5" style="text-align:center;"><?php esc_html_e( 'No chats found', 'nexora' ); ?></td>
					</tr>

				<?php endif; ?>

				</tbody>
			</table>
		</div>

