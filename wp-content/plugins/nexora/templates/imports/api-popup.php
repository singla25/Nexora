<?php
/**
 * The "Import from API" popup (inside a <template>). Nothing is sent anywhere until the vendor clicks a button; keys are
 * typed here and sent once, and are never printed back.
 *
 * @var array $api_import platform [ id, name, format ], connections [ id, name, url, auth_type, auth_header, has_secret ], own, max.
 */
$nx_own = ! empty( $api_import['own'] );
?>
<div class="nx-import-popup" data-kind="api" data-own="<?php echo $nx_own ? '1' : '0'; ?>" data-max="<?php echo (int) ( $api_import['max'] ?? 5 ); ?>">
	<p><?php esc_html_e( 'Import products from a catalog your site or a seller platform offers, or from your own API. Preview first: nothing is saved until you confirm.', 'nexora' ); ?></p>

	<label for="nx-api-source" class="nx-import-label"><?php esc_html_e( 'Source', 'nexora' ); ?></label>
	<select id="nx-api-source" class="nx-api-source">
		<?php if ( $api_import['platform'] ) : ?>
			<optgroup label="<?php echo esc_attr__( 'Platform sources', 'nexora' ); ?>">
				<?php foreach ( $api_import['platform'] as $nx_p ) : ?>
					<option value="platform:<?php echo esc_attr( $nx_p['id'] ); ?>"><?php echo esc_html( $nx_p['name'] ); ?></option>
				<?php endforeach; ?>
			</optgroup>
		<?php endif; ?>
		<?php if ( $nx_own ) : ?>
			<optgroup label="<?php echo esc_attr__( 'My connections', 'nexora' ); ?>" class="nx-api-conn-group">
				<?php foreach ( $api_import['connections'] as $nx_c ) : ?>
					<option value="conn:<?php echo (int) $nx_c['id']; ?>"><?php echo esc_html( $nx_c['name'] ); ?></option>
				<?php endforeach; ?>
			</optgroup>
			<option value="adhoc"><?php esc_html_e( 'My own API...', 'nexora' ); ?></option>
		<?php endif; ?>
	</select>

	<label for="nx-api-limit" class="nx-import-label"><?php esc_html_e( 'How many products', 'nexora' ); ?></label>
	<select id="nx-api-limit" class="nx-api-limit">
		<option value="20" selected>20</option>
		<option value="40">40</option>
		<option value="100">100</option>
		<option value="500"><?php esc_html_e( 'All (up to 500)', 'nexora' ); ?></option>
	</select>

	<?php if ( $nx_own ) : ?>
		<div class="nx-api-own" hidden>
			<input type="hidden" class="nx-api-conn-id" value="">
			<div class="nx-api-grid">
				<div class="nx-vendor-field"><label for="nx-api-name"><?php esc_html_e( 'Connection name (to save it)', 'nexora' ); ?></label><input type="text" id="nx-api-name" class="nx-api-name" maxlength="60"><span class="nx-field-error" data-field="name" role="alert"></span></div>
				<div class="nx-vendor-field"><label for="nx-api-url"><?php esc_html_e( 'Address (https)', 'nexora' ); ?></label><input type="url" id="nx-api-url" class="nx-api-url" placeholder="https://shop.example.com/api/products"><span class="nx-field-error" data-field="url" role="alert"></span></div>
				<div class="nx-vendor-field">
					<label for="nx-api-auth"><?php esc_html_e( 'Authentication', 'nexora' ); ?></label>
					<select id="nx-api-auth" class="nx-api-auth">
						<option value="none"><?php esc_html_e( 'None', 'nexora' ); ?></option>
						<option value="header"><?php esc_html_e( 'Header key', 'nexora' ); ?></option>
						<option value="bearer"><?php esc_html_e( 'Bearer token', 'nexora' ); ?></option>
					</select>
				</div>
				<div class="nx-vendor-field nx-api-header-field" hidden><label for="nx-api-header"><?php esc_html_e( 'Header name', 'nexora' ); ?></label><input type="text" id="nx-api-header" class="nx-api-header" placeholder="X-API-Key"><span class="nx-field-error" data-field="auth_header" role="alert"></span></div>
				<div class="nx-vendor-field nx-api-key-field" hidden><label for="nx-api-key"><?php esc_html_e( 'Key or token', 'nexora' ); ?></label><input type="password" id="nx-api-key" class="nx-api-key" autocomplete="new-password"><span class="nx-api-key-note"></span><span class="nx-field-error" data-field="secret" role="alert"></span></div>
			</div>
			<div class="nx-import-buttons">
				<button type="button" class="nx-api-test"><?php esc_html_e( 'Test my API', 'nexora' ); ?></button>
				<button type="button" class="nx-api-save"><?php esc_html_e( 'Save connection', 'nexora' ); ?></button>
			</div>
		</div>

		<div class="nx-api-saved">
			<p class="nx-import-label">
				<?php
				/* translators: %d: how many connections can be saved. */
				echo esc_html( sprintf( __( 'Saved connections (up to %d)', 'nexora' ), (int) $api_import['max'] ) );
				?>
			</p>
			<ul class="nx-api-list">
				<?php foreach ( $api_import['connections'] as $nx_c ) : ?>
					<li data-id="<?php echo (int) $nx_c['id']; ?>" data-name="<?php echo esc_attr( $nx_c['name'] ); ?>" data-url="<?php echo esc_attr( $nx_c['url'] ); ?>" data-auth="<?php echo esc_attr( $nx_c['auth_type'] ); ?>" data-header="<?php echo esc_attr( $nx_c['auth_header'] ); ?>" data-key="<?php echo $nx_c['has_secret'] ? '1' : '0'; ?>">
						<span class="nx-api-list-name"><?php echo esc_html( $nx_c['name'] ); ?></span>
						<span class="nx-api-list-url"><?php echo esc_html( $nx_c['url'] ); ?></span>
						<button type="button" class="nx-api-edit"><?php esc_html_e( 'Edit', 'nexora' ); ?></button>
						<button type="button" class="nx-api-delete"><?php esc_html_e( 'Delete', 'nexora' ); ?></button>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="nx-api-empty" <?php echo $api_import['connections'] ? 'hidden' : ''; ?>><?php esc_html_e( 'No saved connections yet. Choose "My own API..." to add one.', 'nexora' ); ?></p>
		</div>
	<?php endif; ?>

	<?php \Nexora\Core\View::output( 'imports/format-guide' ); ?>

	<div class="nx-import-buttons">
		<button type="button" class="nx-import-preview"><?php esc_html_e( 'Preview', 'nexora' ); ?></button>
		<button type="button" class="nx-import-confirm" hidden><?php esc_html_e( 'Import', 'nexora' ); ?></button>
	</div>
	<div id="nx-import-report" class="nx-import-report" aria-live="polite"></div>
</div>
