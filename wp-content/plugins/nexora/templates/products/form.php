<?php
/**
 * The add / edit product form (inside the popup template). The browser sends only these fields and the
 * product being edited; the vendor, owner and status always come from the server.
 *
 * @var array $categories slug => label.
 */
?>
<form class="nx-product-form" data-action="nexora_product_save" novalidate>
	<input type="hidden" name="product_id" value="">
	<div class="nx-vendor-fields">
		<div class="nx-vendor-field full-width">
			<label for="nx-prod-name"><?php esc_html_e( 'Product name', 'nexora' ); ?> *</label>
			<input type="text" id="nx-prod-name" name="name" maxlength="120" aria-describedby="nx-prod-name-error">
			<span class="nx-field-error" id="nx-prod-name-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field full-width">
			<label for="nx-prod-description"><?php esc_html_e( 'Description', 'nexora' ); ?></label>
			<textarea id="nx-prod-description" name="description" rows="4" maxlength="2000" aria-describedby="nx-prod-description-error"></textarea>
			<span class="nx-field-error" id="nx-prod-description-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field">
			<label for="nx-prod-price"><?php esc_html_e( 'Price', 'nexora' ); ?> *</label>
			<input type="text" id="nx-prod-price" name="price" inputmode="decimal" aria-describedby="nx-prod-price-error">
			<span class="nx-field-error" id="nx-prod-price-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field">
			<label for="nx-prod-stock"><?php esc_html_e( 'Stock', 'nexora' ); ?> *</label>
			<input type="text" id="nx-prod-stock" name="stock" inputmode="numeric" aria-describedby="nx-prod-stock-error">
			<span class="nx-field-error" id="nx-prod-stock-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field">
			<label for="nx-prod-sku"><?php esc_html_e( 'SKU', 'nexora' ); ?> *</label>
			<input type="text" id="nx-prod-sku" name="sku" maxlength="40" aria-describedby="nx-prod-sku-error">
			<span class="nx-field-error" id="nx-prod-sku-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field">
			<label for="nx-prod-category"><?php esc_html_e( 'Category', 'nexora' ); ?> *</label>
			<select id="nx-prod-category" name="category" aria-describedby="nx-prod-category-error">
				<option value=""><?php esc_html_e( 'Select category', 'nexora' ); ?></option>
				<?php foreach ( $categories as $nx_slug => $nx_label ) : ?>
					<option value="<?php echo esc_attr( $nx_slug ); ?>"><?php echo esc_html( $nx_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="nx-field-error" id="nx-prod-category-error" role="alert"></span>
		</div>
		<div class="nx-vendor-field full-width nx-product-image-field">
			<span class="nx-product-image-label"><?php esc_html_e( 'Image', 'nexora' ); ?></span>
			<div class="nx-product-image-preview" aria-hidden="true"></div>
			<input type="hidden" name="image_id" value="0">
			<div class="nx-product-image-buttons">
				<button type="button" class="nx-product-image-pick"><?php esc_html_e( 'Choose image', 'nexora' ); ?></button>
				<button type="button" class="nx-product-image-remove"><?php esc_html_e( 'Remove', 'nexora' ); ?></button>
			</div>
			<span class="nx-field-error" id="nx-prod-image_id-error" role="alert"></span>
		</div>
	</div>
	<button type="submit" class="nx-vendor-submit"><?php esc_html_e( 'Save product', 'nexora' ); ?></button>
</form>
