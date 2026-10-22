<?php
/**
 * Owner-only card on the profile. A customer sees "Become a seller"; anyone with an application sees their
 * Business Details as read-only text (edited through the Business sub-tab of User Information). Both forms live in <template> elements and open in a SweetAlert2 popup
 * (assets/js/vendor.js), like the other sections.
 *
 * @var array $vendor status, data, types, locked (from Vendors\Service::card_data()).
 */
$state  = $vendor['status'];
$labels = \Nexora\Vendors\Fields::labels();
$apply  = in_array( $state, array( 'none', 'rejected' ), true ); // A rejected applicant sees the application again (their earlier details filled in).
?>
<div class="info-card nx-vendor-card" data-status="<?php echo esc_attr( $state ); ?>"<?php echo 'none' !== $state ? ' id="business-details"' : ''; ?>>
	<?php if ( $apply ) : ?>
		<h3><?php esc_html_e( 'Become a seller', 'nexora' ); ?></h3>
		<?php if ( 'rejected' === $state ) : ?>
			<p class="nx-vendor-status" role="status"><?php esc_html_e( 'Your earlier application was not approved. You can correct your details and apply again.', 'nexora' ); ?></p>
		<?php endif; ?>
		<p><?php esc_html_e( 'Sell your products on the Nexora marketplace. Tell us about your business; we review every application.', 'nexora' ); ?></p>
		<button type="button" class="nx-vendor-open" data-template="nx-vendor-tpl-apply" data-title="<?php echo esc_attr__( 'Become a seller', 'nexora' ); ?>"><?php esc_html_e( 'Become a seller', 'nexora' ); ?></button>

		<template id="nx-vendor-tpl-apply">
			<form class="nx-vendor-form" data-action="nexora_vendor_apply" novalidate>
				<?php
				\Nexora\Core\View::output(
					'seller/business-fields',
					array(
						'data'   => $vendor['data'],
						'types'  => $vendor['types'],
						'locked' => array(),
						'prefix' => 'nx-apply',
					)
				);
				?>
				<button type="submit" class="nx-vendor-submit"><?php esc_html_e( 'Send application', 'nexora' ); ?></button>
			</form>
		</template>
	<?php else : ?>
		<h3><?php esc_html_e( 'Business Details', 'nexora' ); ?></h3>
		<p class="nx-vendor-status" role="status">
			<?php
			if ( 'pending' === $state ) {
				esc_html_e( 'Your application is under review. Your account works as before in the meantime.', 'nexora' );
			} elseif ( 'approved' === $state ) {
				esc_html_e( 'You are an approved seller.', 'nexora' );
			} else {
				esc_html_e( 'Your seller account is suspended. You cannot list or sell until it is reactivated.', 'nexora' );
			}
			?>
		</p>

		<div class="info-grid">
			<?php foreach ( $vendor['data'] as $key => $value ) : ?>
				<div class="info-item">
					<span class="info-label"><?php echo esc_html( $labels[ $key ] ); ?></span>
					<span class="info-value"><?php echo esc_html( 'business_type' === $key && isset( $vendor['types'][ $value ] ) ? $vendor['types'][ $value ] : $value ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<template id="nx-vendor-tpl-edit">
			<form class="nx-vendor-form" data-action="nexora_vendor_update" novalidate>
				<?php if ( $vendor['locked'] ) : ?>
					<p class="nx-vendor-locked-note"><?php esc_html_e( 'Business name, business type and GSTIN cannot be changed once your application has been decided. Contact the site administrator if they need correcting.', 'nexora' ); ?></p>
				<?php endif; ?>
				<?php
				\Nexora\Core\View::output(
					'seller/business-fields',
					array(
						'data'   => $vendor['data'],
						'types'  => $vendor['types'],
						'locked' => $vendor['locked'],
						'prefix' => 'nx-edit',
					)
				);
				?>
				<button type="submit" class="nx-vendor-submit"><?php esc_html_e( 'Save details', 'nexora' ); ?></button>
			</form>
		</template>
	<?php endif; ?>
</div>
