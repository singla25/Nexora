<?php
/**
 * The "Application decision" box.
 *
 * @var string $status   Current status label.
 * @var string $name     Applicant's name.
 * @var string $account  Applicant's username.
 * @var string $business Business name.
 * @var array  $links    Decision buttons (anchors already built and escaped by Vendors\Admin).
 */
?>
<div class="nx-vendor-decision">
	<p><strong><?php esc_html_e( 'Status:', 'nexora' ); ?></strong> <?php echo esc_html( $status ); ?></p>
	<p><strong><?php esc_html_e( 'Applicant:', 'nexora' ); ?></strong> <?php echo esc_html( $name ); ?> (<?php echo esc_html( $account ); ?>)</p>
	<p><strong><?php esc_html_e( 'Business:', 'nexora' ); ?></strong> <?php echo esc_html( $business ); ?></p>
	<?php if ( $links ) : ?>
		<p class="nx-vendor-decision-buttons">
			<?php echo implode( ' ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- anchors are built and escaped in Vendors\Admin::decision_links(). ?>
		</p>
	<?php else : ?>
		<p><em><?php esc_html_e( 'No decision is available for this application.', 'nexora' ); ?></em></p>
	<?php endif; ?>
</div>
