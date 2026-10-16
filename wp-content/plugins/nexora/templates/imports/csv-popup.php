<?php /** The CSV import popup (inside a <template>). Nothing here is sent anywhere until the vendor clicks Preview. */ ?>
<div class="nx-import-popup" data-kind="csv">
	<p><?php esc_html_e( 'Upload a .csv file (UTF-8, up to 2 MB and 500 rows). The first row must name the columns:', 'nexora' ); ?></p>
	<p class="nx-import-columns"><code>sku</code>, <code>name</code>, <code>description</code>, <code>price</code>, <code>stock</code>, <code>category</code>, <code>image_url</code></p>
	<ul class="nx-import-notes">
		<li><?php esc_html_e( 'sku, name, price, stock and category are required. description and image_url are optional.', 'nexora' ); ?></li>
		<li><?php esc_html_e( 'The SKU identifies your product: a SKU you already have is updated, a new one is created. Other sellers\' products are never touched.', 'nexora' ); ?></li>
		<li><?php esc_html_e( 'image_url must be the address of an image in your own Media Library. Leave it empty for no image.', 'nexora' ); ?></li>
		<li><?php esc_html_e( 'Preview first: nothing is saved until you confirm.', 'nexora' ); ?></li>
	</ul>
	<label for="nx-import-file" class="nx-import-label"><?php esc_html_e( 'CSV file', 'nexora' ); ?></label>
	<input type="file" id="nx-import-file" name="file" accept=".csv,text/csv">
	<div class="nx-import-buttons">
		<button type="button" class="nx-import-preview"><?php esc_html_e( 'Preview', 'nexora' ); ?></button>
		<button type="button" class="nx-import-confirm" hidden><?php esc_html_e( 'Import', 'nexora' ); ?></button>
	</div>
	<div id="nx-import-report" class="nx-import-report" aria-live="polite"></div>
</div>
