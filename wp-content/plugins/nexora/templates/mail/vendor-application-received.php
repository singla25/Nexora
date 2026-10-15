<?php
/**
 * @var string $greeting Applicant's display name.
 * @var string $name     Business name.
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
	/* translators: %s: the business name. */
	echo esc_html( sprintf( __( 'Thank you for applying to sell on Nexora as "%s". We will review your application and email you the decision.', 'nexora' ), $name ) );
	?>
</p>
<p><?php esc_html_e( 'Until then your account works exactly as before.', 'nexora' ); ?></p>
