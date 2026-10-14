<?php
/**
 * @var array  $data    Business details.
 * @var array  $labels  Field labels.
 * @var array  $types   Business type labels.
 * @var string $account Applicant's username.
 * @var string $email   Applicant's account email.
 * @var string $status  Status label.
 * @var bool   $trashed Whether the application was rejected (trashed).
 * @var array  $links   Decision links (already-escaped anchors).
 */
?>
<table class="form-table" role="presentation">
	<tr><th><?php esc_html_e( 'Status', 'nexora' ); ?></th><td><strong><?php echo esc_html( $trashed ? __( 'Rejected', 'nexora' ) : $status ); ?></strong></td></tr>
	<tr><th><?php esc_html_e( 'Account', 'nexora' ); ?></th><td><?php echo esc_html( $account ); ?> (<?php echo esc_html( $email ); ?>)</td></tr>
	<?php foreach ( $data as $key => $value ) : ?>
		<tr>
			<th><?php echo esc_html( $labels[ $key ] ); ?></th>
			<td><?php echo esc_html( 'business_type' === $key && isset( $types[ $value ] ) ? $types[ $value ] : $value ); ?></td>
		</tr>
	<?php endforeach; ?>
</table>
<?php if ( $links ) : ?>
	<p class="nx-vendor-decisions">
		<?php echo implode( ' &nbsp;|&nbsp; ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput -- anchors are built and escaped in Vendors\Admin::decision_links(). ?>
	</p>
<?php endif; ?>
