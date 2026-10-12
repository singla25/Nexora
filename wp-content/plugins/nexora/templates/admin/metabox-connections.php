<?php /** @var array[] $received  @var array[] $sent  rows: profile_id, user_name, status */ ?>

		<h2><?php esc_html_e( '📥 Received Requests', 'nexora' ); ?></h2>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Sender Profile ID', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Sender Username', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>

			<?php
			if ( $received ) :
				foreach ( $received as $row ) :
					?>

				<tr>
					<td><?php echo esc_html( $row['profile_id'] ); ?></td>
					<td><?php echo esc_html( $row['user_name'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>

							<?php endforeach; else : ?>

				<tr><td colspan="3"><?php esc_html_e( 'No received requests', 'nexora' ); ?></td></tr>

			<?php endif; ?>

			</tbody>
		</table>


		<br><br>

		<h2><?php esc_html_e( '📤 Sent Requests', 'nexora' ); ?></h2>

		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Receiver Profile ID', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Receiver Username', 'nexora' ); ?></th>
					<th><?php esc_html_e( 'Status', 'nexora' ); ?></th>
				</tr>
			</thead>
			<tbody>

			<?php
			if ( $sent ) :
				foreach ( $sent as $row ) :
					?>

				<tr>
					<td><?php echo esc_html( $row['profile_id'] ); ?></td>
					<td><?php echo esc_html( $row['user_name'] ); ?></td>
					<td><?php echo esc_html( $row['status'] ); ?></td>
				</tr>

							<?php endforeach; else : ?>

				<tr><td colspan="3"><?php esc_html_e( 'No sent requests', 'nexora' ); ?></td></tr>

			<?php endif; ?>

			</tbody>
		</table>

		