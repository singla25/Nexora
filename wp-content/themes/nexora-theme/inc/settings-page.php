<?php
/**
 * Appearance > Nexora Settings. Everything the header / footer / CTA show
 * is read from this option (nxt_settings) instead of being hard-coded.
 *
 * Uses the Settings API, which handles the nonce and capability check.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function nxt_settings_fields() {
	return array(
		'tagline'          => array( __( 'Footer tagline', 'nexora-theme' ), 'text' ),
		'email'            => array( __( 'Contact email', 'nexora-theme' ), 'email' ),
		'phone'            => array( __( 'Contact phone', 'nexora-theme' ), 'text' ),
		'address'          => array( __( 'Address', 'nexora-theme' ), 'text' ),
		'cta_heading'      => array( __( 'Sign-up banner heading', 'nexora-theme' ), 'text' ),
		'cta_button_label' => array( __( 'Sign-up banner button label', 'nexora-theme' ), 'text' ),
		'facebook'         => array( __( 'Facebook URL', 'nexora-theme' ), 'url' ),
		'twitter'          => array( __( 'X / Twitter URL', 'nexora-theme' ), 'url' ),
		'linkedin'         => array( __( 'LinkedIn URL', 'nexora-theme' ), 'url' ),
		'instagram'        => array( __( 'Instagram URL', 'nexora-theme' ), 'url' ),
	);
}

function nxt_sanitize_settings( $input ) {
	$clean = array();
	$input = is_array( $input ) ? $input : array();

	foreach ( nxt_settings_fields() as $key => $field ) {
		$value = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : '';

		switch ( $field[1] ) {
			case 'email':
				$clean[ $key ] = sanitize_email( $value );
				break;
			case 'url':
				$clean[ $key ] = esc_url_raw( $value );
				break;
			default:
				$clean[ $key ] = sanitize_text_field( $value );
		}
	}

	return $clean;
}

function nxt_register_settings() {
	register_setting( 'nxt_settings_group', 'nxt_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'nxt_sanitize_settings',
	) );
}
add_action( 'admin_init', 'nxt_register_settings' );

function nxt_add_settings_page() {
	add_theme_page(
		__( 'Nexora Settings', 'nexora-theme' ),
		__( 'Nexora Settings', 'nexora-theme' ),
		'manage_options',
		'nxt-settings',
		'nxt_render_settings_page'
	);
}
add_action( 'admin_menu', 'nxt_add_settings_page' );

function nxt_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$options = get_option( 'nxt_settings', array() );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Nexora Settings', 'nexora-theme' ); ?></h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'nxt_settings_group' ); ?>
			<table class="form-table" role="presentation">
				<?php foreach ( nxt_settings_fields() as $key => $field ) : ?>
					<tr>
						<th scope="row"><label for="nxt-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field[0] ); ?></label></th>
						<td>
							<input
								type="<?php echo esc_attr( $field[1] ); ?>"
								id="nxt-<?php echo esc_attr( $key ); ?>"
								name="nxt_settings[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( isset( $options[ $key ] ) ? $options[ $key ] : '' ); ?>"
								class="regular-text"
							/>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
