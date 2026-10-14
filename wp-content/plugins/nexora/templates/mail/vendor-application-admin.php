<?php
/**
 * @var string $account  Applicant's username.
 * @var array  $data     Business details.
 * @var array  $types    Business type labels.
 * @var string $edit_url Admin edit screen of the application.
 */
?>
<h2><?php esc_html_e( 'New seller application', 'nexora' ); ?></h2>
<p><strong><?php esc_html_e( 'Account:', 'nexora' ); ?></strong> <?php echo esc_html( $account ); ?></p>
<p><strong><?php esc_html_e( 'Business:', 'nexora' ); ?></strong> <?php echo esc_html( $data['business_name'] ); ?></p>
<p><strong><?php esc_html_e( 'Type:', 'nexora' ); ?></strong> <?php echo esc_html( isset( $types[ $data['business_type'] ] ) ? $types[ $data['business_type'] ] : $data['business_type'] ); ?></p>
<p><strong><?php esc_html_e( 'GSTIN:', 'nexora' ); ?></strong> <?php echo esc_html( $data['gstin'] ); ?></p>
<p><strong><?php esc_html_e( 'Business email:', 'nexora' ); ?></strong> <?php echo esc_html( $data['business_email'] ); ?></p>
<p><strong><?php esc_html_e( 'Business phone:', 'nexora' ); ?></strong> <?php echo esc_html( $data['business_phone'] ); ?></p>
<p><a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Review the application', 'nexora' ); ?></a></p>
