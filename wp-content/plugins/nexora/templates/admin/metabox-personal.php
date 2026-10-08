<?php /** @var array $meta  the profile's meta values, keyed by field */ ?>
		<input type="text" name="user_name" placeholder="User Name" value="<?php echo esc_attr( $meta['user_name'] ); ?>" class="widefat"><br><br>
		<input type="text" name="first_name" placeholder="First Name" value="<?php echo esc_attr( $meta['first_name'] ); ?>" class="widefat"><br><br>
		<input type="text" name="last_name" placeholder="Last Name" value="<?php echo esc_attr( $meta['last_name'] ); ?>" class="widefat"><br><br>
		<input type="email" name="email" placeholder="Email" value="<?php echo esc_attr( $meta['email'] ); ?>" class="widefat"><br><br>
		<input type="text" name="phone" placeholder="Phone" value="<?php echo esc_attr( $meta['phone'] ); ?>" class="widefat"><br><br>
		<input type="text" name="linkedin_id" placeholder="LinkedIn" value="<?php echo esc_attr( $meta['linkedin_id'] ); ?>" class="widefat"><br><br>

		<label>Gender</label>
		<select name="gender" class="widefat">
			<option value="">Select Gender</option>
			<option value="male" <?php selected( $meta['gender'], 'male' ); ?>>Male</option>
			<option value="female" <?php selected( $meta['gender'], 'female' ); ?>>Female</option>
			<option value="other" <?php selected( $meta['gender'], 'other' ); ?>>Other</option>
		</select><br><br>

		<label>Birthdate</label>
		<input type="date" name="birthdate"
			value="<?php echo esc_attr( $meta['birthdate'] ); ?>"
			class="widefat"><br><br>

		<textarea name="bio" placeholder="Bio" class="widefat"><?php echo esc_textarea( $meta['bio'] ); ?></textarea>

		