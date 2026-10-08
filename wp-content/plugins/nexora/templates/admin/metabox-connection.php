<?php /** @var array $m  sender_/receiver_ user_id, profile_id, user_name and status */ ?>

		<table class="form-table">

			<tr>
				<th>Sender User ID</th>
				<td><input type="number" name="sender_user_id" value="<?php echo esc_attr( $m['sender_user_id'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Sender Profile ID</th>
				<td><input type="number" name="sender_profile_id" value="<?php echo esc_attr( $m['sender_profile_id'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Sender User Name</th>
				<td><input type="text" name="sender_user_name" value="<?php echo esc_attr( $m['sender_user_name'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Receiver User ID</th>
				<td><input type="number" name="receiver_user_id" value="<?php echo esc_attr( $m['receiver_user_id'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Receiver Profile ID</th>
				<td><input type="number" name="receiver_profile_id" value="<?php echo esc_attr( $m['receiver_profile_id'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Receiver User Name</th>
				<td><input type="text" name="receiver_user_name" value="<?php echo esc_attr( $m['receiver_user_name'] ); ?>" class="widefat"></td>
			</tr>

			<tr>
				<th>Status</th>
				<td>
					<select name="status" class="widefat">
						<option value="pending" <?php selected( $m['status'], 'pending' ); ?>>Pending</option>
						<option value="accepted" <?php selected( $m['status'], 'accepted' ); ?>>Accepted</option>
						<option value="rejected" <?php selected( $m['status'], 'rejected' ); ?>>Rejected</option>
						<option value="removed" <?php selected( $m['status'], 'removed' ); ?>>Removed</option>
					</select>
				</td>
			</tr>

		</table>

		