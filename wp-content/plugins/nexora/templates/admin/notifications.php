<?php /** @var object[] $notifications  rows from the notifications table */ ?>
<?php $nx_can_edit = current_user_can( 'manage_options' ); ?>
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
						<?php if ( $nx_can_edit ) : ?>
						<th><?php esc_html_e( 'Edit', 'nexora' ); ?></th>
						<?php endif; ?>
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
						<?php if ( $nx_can_edit ) : ?>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="nexora_notification_update">
								<input type="hidden" name="id" value="<?php echo esc_attr( $n->id ); ?>">
								<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'nexora_notification_update_' . $n->id ) ); ?>">
								<textarea name="message" rows="2" maxlength="1000" class="widefat"><?php echo esc_textarea( $n->message ); ?></textarea>
								<select name="is_read">
									<option value="0" <?php selected( (int) $n->is_read, 0 ); ?>><?php esc_html_e( 'Unread', 'nexora' ); ?></option>
									<option value="1" <?php selected( (int) $n->is_read, 1 ); ?>><?php esc_html_e( 'Read', 'nexora' ); ?></option>
								</select>
								<button type="submit" class="button button-small"><?php esc_html_e( 'Save', 'nexora' ); ?></button>
								<a class="submitdelete" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nexora_notification_delete&id=' . (int) $n->id ), 'nexora_notification_delete_' . $n->id ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Delete this notification for good?', 'nexora' ) ); ?>');"><?php esc_html_e( 'Delete', 'nexora' ); ?></a>
							</form>
						</td>
						<?php endif; ?>
					</tr>

									<?php endforeach; else : ?>

					<tr><td colspan="<?php echo $nx_can_edit ? 8 : 7; ?>" style="text-align: center;"><?php esc_html_e( 'No notifications found', 'nexora' ); ?></td></tr>

				<?php endif; ?>

				</tbody>
			</table>
		</div>
