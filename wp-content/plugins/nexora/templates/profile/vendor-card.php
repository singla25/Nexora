<?php
/**
 * Owner-only card on the profile: become a seller, or the state of the application.
 *
 * @var array $vendor status, data, types, locked (from Vendors\Service::card_data()).
 */
$state  = $vendor['status'];
$labels = \Nexora\Vendors\Fields::labels();
?>
<div class="info-card nx-vendor-card" data-status="<?php echo esc_attr( $state ); ?>">
	<?php if ( 'none' === $state ) : ?>
		<h3><?php esc_html_e( 'Become a seller', 'nexora' ); ?></h3>
		<p><?php esc_html_e( 'Sell your products on the Nexora marketplace. Tell us about your business; we review every application.', 'nexora' ); ?></p>
		<button type="button" class="nx-vendor-open" aria-expanded="false" aria-controls="nexora-vendor-apply-form"><?php esc_html_e( 'Become a seller', 'nexora' ); ?></button>
		<form id="nexora-vendor-apply-form" class="nx-vendor-form" data-action="nexora_vendor_apply" hidden novalidate>
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
	<?php else : ?>
		<h3><?php esc_html_e( 'Seller account', 'nexora' ); ?></h3>
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
		<form id="nexora-vendor-edit-form" class="nx-vendor-form" data-action="nexora_vendor_update" novalidate>
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
	<?php endif; ?>
</div>
