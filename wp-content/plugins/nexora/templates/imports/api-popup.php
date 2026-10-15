<?php /** The API import popup (inside a <template>). There is nothing to configure: the source is fixed on the server. */ ?>
<div class="nx-import-popup" data-kind="api">
	<p><?php esc_html_e( 'Import the sample catalog from DummyJSON (up to 500 products) into your own products.', 'nexora' ); ?></p>
	<ul class="nx-import-notes">
		<li><?php esc_html_e( 'Products are matched by their id in the source: importing again updates them instead of adding copies.', 'nexora' ); ?></li>
		<li><?php esc_html_e( 'Prices are taken as they are (no currency conversion). Images stay on the source\'s server.', 'nexora' ); ?></li>
		<li><?php esc_html_e( 'Preview first: nothing is saved until you confirm.', 'nexora' ); ?></li>
	</ul>
	<div class="nx-import-buttons">
		<button type="button" class="nx-import-preview"><?php esc_html_e( 'Preview', 'nexora' ); ?></button>
		<button type="button" class="nx-import-confirm" hidden><?php esc_html_e( 'Import', 'nexora' ); ?></button>
	</div>
	<div id="nx-import-report" class="nx-import-report" aria-live="polite"></div>
</div>
