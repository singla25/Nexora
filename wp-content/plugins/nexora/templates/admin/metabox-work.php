<?php /** @var array $meta  the profile's meta values, keyed by field */ ?>
		<input type="text" name="company_name" placeholder="<?php echo esc_attr__( 'Company Name', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['company_name'] ); ?>" class="widefat"><br><br>
		<input type="text" name="designation" placeholder="<?php echo esc_attr__( 'Designation', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['designation'] ); ?>" class="widefat"><br><br>
		<input type="email" name="company_email" placeholder="<?php echo esc_attr__( 'Company Email', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['company_email'] ); ?>" class="widefat"><br><br>
		<input type="text" name="company_phone" placeholder="<?php echo esc_attr__( 'Company Phone', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['company_phone'] ); ?>" class="widefat"><br><br>
		<input type="text" name="company_address" placeholder="<?php echo esc_attr__( 'Company Address', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['company_address'] ); ?>" class="widefat"><br><br>

		