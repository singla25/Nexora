<?php /** @var array $meta  the profile's meta values, keyed by field */ ?>
        <h3>Permanent Address</h3>

        <input type="text" name="perm_address" placeholder="Address" value="<?php echo esc_attr($meta['perm_address']); ?>" class="widefat"><br><br>
        <input type="text" name="perm_city" placeholder="City" value="<?php echo esc_attr($meta['perm_city']); ?>" class="widefat"><br><br>
        <input type="text" name="perm_state" placeholder="State" value="<?php echo esc_attr($meta['perm_state']); ?>" class="widefat"><br><br>
        <input type="text" name="perm_pincode" placeholder="Pincode" value="<?php echo esc_attr($meta['perm_pincode']); ?>" class="widefat"><br><br>

        <h3>Correspondence Address</h3>

        <input type="text" name="corr_address" placeholder="Address" value="<?php echo esc_attr($meta['corr_address']); ?>" class="widefat"><br><br>
        <input type="text" name="corr_city" placeholder="City" value="<?php echo esc_attr($meta['corr_city']); ?>" class="widefat"><br><br>
        <input type="text" name="corr_state" placeholder="State" value="<?php echo esc_attr($meta['corr_state']); ?>" class="widefat"><br><br>
        <input type="text" name="corr_pincode" placeholder="Pincode" value="<?php echo esc_attr($meta['corr_pincode']); ?>" class="widefat"><br><br>

        