<?php
/**
 * @var string $action   approve | reactivate | suspend | reject.
 * @var string $greeting Applicant's display name.
 * @var string $business Business name.
 */
?>
<p>
	<?php
	/* translators: %s: the applicant's name. */
	echo esc_html( sprintf( __( 'Hello %s,', 'nexora' ), $greeting ) );
	?>
</p>
<p>
	<?php
	if ( 'approve' === $action ) {
		/* translators: %s: the business name. */
		echo esc_html( sprintf( __( 'Good news: "%s" is approved. You can now list products from your profile.', 'nexora' ), $business ) );
	} elseif ( 'reactivate' === $action ) {
		/* translators: %s: the business name. */
		echo esc_html( sprintf( __( 'Your seller account "%s" is active again.', 'nexora' ), $business ) );
	} elseif ( 'suspend' === $action ) {
		/* translators: %s: the business name. */
		echo esc_html( sprintf( __( 'Your seller account "%s" has been suspended. Your products are hidden and you cannot list or sell until it is reactivated. You can still see your earnings history.', 'nexora' ), $business ) );
	} else {
		/* translators: %s: the business name. */
		echo esc_html( sprintf( __( 'Your seller application for "%s" has been closed and your account is a regular member account again. You are welcome to apply again with corrected details.', 'nexora' ), $business ) );
	}
	?>
</p>
