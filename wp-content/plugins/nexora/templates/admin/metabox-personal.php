<?php /** @var array $meta  the profile's meta values, keyed by field */ ?>
		<input type="text" name="user_name" placeholder="<?php echo esc_attr__( 'User Name', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['user_name'] ); ?>" class="widefat"><br><br>
		<input type="text" name="first_name" placeholder="<?php echo esc_attr__( 'First Name', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['first_name'] ); ?>" class="widefat"><br><br>
		<input type="text" name="last_name" placeholder="<?php echo esc_attr__( 'Last Name', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['last_name'] ); ?>" class="widefat"><br><br>
		<input type="email" name="email" placeholder="<?php echo esc_attr__( 'Email', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['email'] ); ?>" class="widefat"><br><br>
		<input type="text" name="phone" placeholder="<?php echo esc_attr__( 'Phone', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['phone'] ); ?>" class="widefat"><br><br>
		<input type="text" name="linkedin_id" placeholder="<?php echo esc_attr__( 'LinkedIn', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['linkedin_id'] ); ?>" class="widefat"><br><br>

		<label><?php esc_html_e( 'Gender', 'nexora' ); ?></label>
		<select name="gender" class="widefat">
			<option value=""><?php esc_html_e( 'Select Gender', 'nexora' ); ?></option>
			<option value="male" <?php selected( $meta['gender'], 'male' ); ?>><?php esc_html_e( 'Male', 'nexora' ); ?></option>
			<option value="female" <?php selected( $meta['gender'], 'female' ); ?>><?php esc_html_e( 'Female', 'nexora' ); ?></option>
			<option value="other" <?php selected( $meta['gender'], 'other' ); ?>><?php esc_html_e( 'Other', 'nexora' ); ?></option>
		</select><br><br>

		<label><?php esc_html_e( 'Birthdate', 'nexora' ); ?></label>
		<input type="date" name="birthdate"
			value="<?php echo esc_attr( $meta['birthdate'] ); ?>"
			class="widefat"><br><br>

		<textarea name="bio" placeholder="<?php echo esc_attr__( 'Bio', 'nexora' ); ?>" class="widefat"><?php echo esc_textarea( $meta['bio'] ); ?></textarea>

		