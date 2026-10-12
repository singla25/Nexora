<?php /** @var array $m  user_id, user_profile_id, user_name */ ?>

		<table class="form-table">

			<tr>
				<th><?php esc_html_e( 'User ID', 'nexora' ); ?></th>
				<td><input type="number" name="user_id" value="<?php echo esc_attr( $m['user_id'] ); ?>" class="regular-text"></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'User Profile ID', 'nexora' ); ?></th>
				<td><input type="number" name="user_profile_id" value="<?php echo esc_attr( $m['user_profile_id'] ); ?>" class="regular-text"></td>
			</tr>

			<tr>
				<th><?php esc_html_e( 'User Name', 'nexora' ); ?></th>
				<td><input type="text" name="user_name" value="<?php echo esc_attr( $m['user_name'] ); ?>" class="regular-text"></td>
			</tr>

		</table>

		