<?php
/**
 * The business-detail inputs, shared by the sign-up form, "Become a seller" and the edit form.
 *
 * @var array  $data   Current values (field => value).
 * @var array  $types  Business type labels.
 * @var array  $locked Fields shown read-only.
 * @var string $prefix Unique id prefix for the inputs.
 */
$labels = \Nexora\Vendors\Fields::labels();
?>
<div class="nx-vendor-fields">
	<?php foreach ( \Nexora\Vendors\Fields::keys() as $key ) : ?>
		<?php
		$id        = $prefix . '-' . $key;
		$is_locked = in_array( $key, $locked, true );
		?>
		<div class="nx-vendor-field <?php echo in_array( $key, array( 'business_name', 'business_address' ), true ) ? 'full-width' : ''; ?>">
			<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $labels[ $key ] ); ?> *</label>
			<?php if ( 'business_type' === $key ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" <?php disabled( $is_locked ); ?> aria-describedby="<?php echo esc_attr( $id ); ?>-error">
					<option value=""><?php esc_html_e( 'Select business type', 'nexora' ); ?></option>
					<?php foreach ( $types as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $data[ $key ], $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			<?php else : ?>
				<input type="<?php echo 'business_email' === $key ? 'email' : 'text'; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $data[ $key ] ); ?>" <?php echo $is_locked ? 'readonly' : ''; ?>
					<?php echo 'gstin' === $key ? 'maxlength="15" autocapitalize="characters" pattern="[0-9]{2}[A-Za-z]{5}[0-9]{4}[A-Za-z][1-9A-Za-z]Z[0-9A-Za-z]"' : ''; ?>
					<?php echo 'business_pincode' === $key ? 'maxlength="6" inputmode="numeric"' : ''; ?>
					aria-describedby="<?php echo esc_attr( $id ); ?>-error">
			<?php endif; ?>
			<span class="nx-field-error" id="<?php echo esc_attr( $id ); ?>-error" role="alert"></span>
		</div>
	<?php endforeach; ?>
</div>
