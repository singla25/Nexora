<?php /** @var array $meta  the profile's meta values, keyed by field */ ?>
		<h3><?php esc_html_e( 'Permanent Address', 'nexora' ); ?></h3>

		<input type="text" name="perm_address" placeholder="<?php echo esc_attr__( 'Address', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['perm_address'] ); ?>" class="widefat"><br><br>
		<input type="text" name="perm_city" placeholder="<?php echo esc_attr__( 'City', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['perm_city'] ); ?>" class="widefat"><br><br>
		<input type="text" name="perm_state" placeholder="<?php echo esc_attr__( 'State', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['perm_state'] ); ?>" class="widefat"><br><br>
		<input type="text" name="perm_pincode" placeholder="<?php echo esc_attr__( 'Pincode', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['perm_pincode'] ); ?>" class="widefat"><br><br>

		<h3><?php esc_html_e( 'Correspondence Address', 'nexora' ); ?></h3>

		<input type="text" name="corr_address" placeholder="<?php echo esc_attr__( 'Address', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['corr_address'] ); ?>" class="widefat"><br><br>
		<input type="text" name="corr_city" placeholder="<?php echo esc_attr__( 'City', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['corr_city'] ); ?>" class="widefat"><br><br>
		<input type="text" name="corr_state" placeholder="<?php echo esc_attr__( 'State', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['corr_state'] ); ?>" class="widefat"><br><br>
		<input type="text" name="corr_pincode" placeholder="<?php echo esc_attr__( 'Pincode', 'nexora' ); ?>" value="<?php echo esc_attr( $meta['corr_pincode'] ); ?>" class="widefat"><br><br>

		