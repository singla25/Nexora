<?php
/**
 * Appearance > Nexora Sample Content
 *
 * One click creates, using Elementor's own document API:
 *  - Elementor library templates for every page layout (insert them into any page)
 *  - the pages themselves (Home, About, Contact, FAQs, Privacy, Terms, Guidelines)
 *  - Elementor Pro Theme Builder templates: Header, Footer and 404
 *  - the login / registration / profile pages for the Nexora plugin
 *  - menus (main, footer company, footer legal) and the front page setting
 *
 * It is safe to run more than once: existing pages are never touched unless
 * they were created by this installer AND "replace" is ticked.
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NXT_SAMPLE_MARK = '_nxt_sample';

/* ===========================================================
   Admin page
=========================================================== */

function nxt_sample_menu() {
	add_theme_page(
		__( 'Nexora Sample Content', 'nexora-theme' ),
		__( 'Sample Content', 'nexora-theme' ),
		'manage_options',
		'nxt-sample-content',
		'nxt_sample_page'
	);
}
add_action( 'admin_menu', 'nxt_sample_menu' );

function nxt_sample_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$elementor = class_exists( '\Elementor\Plugin' );
	$pro       = class_exists( '\ElementorPro\Plugin' );
	$report    = get_transient( 'nxt_sample_report_' . get_current_user_id() );

	if ( $report ) {
		delete_transient( 'nxt_sample_report_' . get_current_user_id() );
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Nexora Sample Content', 'nexora-theme' ); ?></h1>

		<?php if ( $report ) : ?>
			<div class="notice notice-info">
				<p><strong><?php esc_html_e( 'Result', 'nexora-theme' ); ?></strong></p>
				<ul style="list-style:disc;margin-left:20px;">
					<?php foreach ( $report as $line ) : ?>
						<li><?php echo esc_html( $line ); ?></li>
					<?php endforeach; ?>
				</ul>
			</div>
		<?php endif; ?>

		<?php if ( ! $elementor ) : ?>
			<div class="notice notice-error"><p><?php esc_html_e( 'Elementor is not active. Activate Elementor first.', 'nexora-theme' ); ?></p></div>
		<?php elseif ( ! $pro ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'Elementor Pro is not active: pages and library templates will be created, but the Header, Footer and 404 templates need Elementor Pro (the theme\'s built-in header and footer are used meanwhile).', 'nexora-theme' ); ?></p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'This creates sample Elementor pages and templates in the Nexora design. Everything uses one sample image that you can replace in the editor.', 'nexora-theme' ); ?></p>

		<ul style="list-style:disc;margin-left:20px;">
			<li><?php esc_html_e( 'Pages: Home (set as front page), About, Contact, FAQs, Privacy Policy, Terms of Use, Community Guidelines', 'nexora-theme' ); ?></li>
			<li><?php esc_html_e( 'Elementor library: one saved template per page layout (Templates > Saved Templates)', 'nexora-theme' ); ?></li>
			<li><?php esc_html_e( 'Theme Builder (Elementor Pro): Header, Footer, 404', 'nexora-theme' ); ?></li>
			<li><?php esc_html_e( 'Nexora plugin pages if missing: Login, Registration, Profile', 'nexora-theme' ); ?></li>
			<li><?php esc_html_e( 'Menus: Nexora Main, Nexora Footer Company, Nexora Footer Legal', 'nexora-theme' ); ?></li>
		</ul>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="nxt_install_sample">
			<?php wp_nonce_field( 'nxt_install_sample', 'nxt_nonce' ); ?>

			<p>
				<label>
					<input type="checkbox" name="nxt_overwrite" value="1">
					<?php esc_html_e( 'Replace sample pages and templates created by a previous run (your edits to them will be lost). Pages you made yourself are never touched.', 'nexora-theme' ); ?>
				</label>
			</p>

			<?php submit_button( __( 'Install sample content', 'nexora-theme' ), 'primary', 'submit', true, $elementor ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>
	</div>
	<?php
}

function nxt_sample_handle() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'nexora-theme' ), 403 );
	}

	check_admin_referer( 'nxt_install_sample', 'nxt_nonce' );

	$overwrite = ! empty( $_POST['nxt_overwrite'] );
	$report    = nxt_install_sample_content( $overwrite );

	set_transient( 'nxt_sample_report_' . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

	wp_safe_redirect( admin_url( 'themes.php?page=nxt-sample-content' ) );
	exit;
}
add_action( 'admin_post_nxt_install_sample', 'nxt_sample_handle' );

/* ===========================================================
   Installer
=========================================================== */

/**
 * The sample image as a Media Library item (falls back to the theme file).
 */
function nxt_sample_image() {
	$fallback = array(
		'id'  => 0,
		'url' => NXT_URI . '/assets/images/sample.webp',
	);

	$id = (int) get_option( 'nxt_sample_image_id' );

	if ( $id && 'attachment' === get_post_type( $id ) ) {
		return array(
			'id'  => $id,
			'url' => wp_get_attachment_url( $id ),
		);
	}

	$file = NXT_DIR . '/assets/images/sample.webp';

	if ( ! file_exists( $file ) ) {
		return $fallback;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = wp_tempnam( 'nexora-sample.webp' );

	if ( ! $tmp || ! copy( $file, $tmp ) ) {
		return $fallback;
	}

	$id = media_handle_sideload(
		array(
			'name'     => 'nexora-sample.webp',
			'tmp_name' => $tmp,
		),
		0,
		'Nexora sample image'
	);

	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return $fallback;
	}

	update_option( 'nxt_sample_image_id', (int) $id );

	return array(
		'id'  => (int) $id,
		'url' => wp_get_attachment_url( $id ),
	);
}

function nxt_find_sample_post( $post_type, $meta_value ) {
	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => NXT_SAMPLE_MARK,
			'meta_value'     => $meta_value,
		)
	);

	return $posts ? (int) $posts[0] : 0;
}

/**
 * Create or update an Elementor-backed page post (no layout yet).
 *
 * @return int|false post ID, or false when a page the user made already uses the slug.
 */
function nxt_sample_ensure_page( $slug, $title, $content, $template, &$report, $mark = true ) {
	$existing = get_page_by_path( $slug, OBJECT, 'page' );

	if ( $existing ) {
		$is_ours = get_post_meta( $existing->ID, NXT_SAMPLE_MARK, true ) === $slug;

		// WordPress ships an unpublished draft "Privacy Policy" page. Adopt it
		// (publish + mark) instead of leaving the slug blocked.
		if (
			! $is_ours
			&& $mark
			&& 'privacy-policy' === $slug
			&& 'draft' === $existing->post_status
			&& ! get_post_meta( $existing->ID, '_elementor_data', true )
		) {
			wp_update_post(
				array(
					'ID'          => $existing->ID,
					'post_status' => 'publish',
					'post_title'  => $title,
				)
			);
			update_post_meta( $existing->ID, NXT_SAMPLE_MARK, $slug );

			if ( $template ) {
				update_post_meta( $existing->ID, '_wp_page_template', $template );
			}

			$report[] = __( 'Adopted the default WordPress draft Privacy Policy page and published it with the new layout.', 'nexora-theme' );

			return (int) $existing->ID;
		}

		if ( ! $is_ours ) {
			/* translators: %s: page slug */
			$report[] = sprintf( __( 'Page "%s" already exists, left untouched.', 'nexora-theme' ), $slug );
			return (int) $existing->ID;
		}

		return (int) $existing->ID;
	}

	$meta = array();

	if ( $mark ) {
		$meta[ NXT_SAMPLE_MARK ] = $slug;
	}

	if ( $template ) {
		$meta['_wp_page_template'] = $template;
	}

	$id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $content,
			'meta_input'   => $meta,
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		$report[] = sprintf( 'Could not create page "%s": %s', $slug, $id->get_error_message() );
		return false;
	}

	/* translators: %s: page title */
	$report[] = sprintf( __( 'Created page: %s', 'nexora-theme' ), $title );

	return (int) $id;
}

/**
 * Save elements into an Elementor document.
 */
function nxt_sample_save_elements( $post_id, $elements, $template_type = 'wp-page' ) {
	$documents = \Elementor\Plugin::$instance->documents;

	update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $post_id, '_elementor_template_type', $template_type );

	$doc = $documents->get( $post_id, false );

	if ( ! $doc ) {
		return false;
	}

	$doc->save(
		array(
			'elements' => $elements,
			'settings' => array(),
		)
	);

	return true;
}

function nxt_sample_menu_ensure( $name, $page_slugs, &$report ) {
	$menu = wp_get_nav_menu_object( $name );

	if ( $menu ) {
		return (int) $menu->term_id;
	}

	$menu_id = wp_create_nav_menu( $name );

	if ( is_wp_error( $menu_id ) ) {
		return 0;
	}

	foreach ( $page_slugs as $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );

		if ( ! $page ) {
			continue;
		}

		wp_update_nav_menu_item(
			$menu_id,
			0,
			array(
				'menu-item-title'     => get_the_title( $page ),
				'menu-item-object'    => 'page',
				'menu-item-object-id' => $page->ID,
				'menu-item-type'      => 'post_type',
				'menu-item-status'    => 'publish',
			)
		);
	}

	/* translators: %s: menu name */
	$report[] = sprintf( __( 'Created menu: %s', 'nexora-theme' ), $name );

	return (int) $menu_id;
}

/**
 * Run the installer. Returns a list of human readable result lines.
 */
function nxt_install_sample_content( $overwrite = false ) {
	$report = array();

	if ( ! class_exists( '\Elementor\Plugin' ) ) {
		return array( __( 'Elementor is not active. Nothing was created.', 'nexora-theme' ) );
	}

	$registry  = nxt_sample_registry();
	$img       = nxt_sample_image();
	$documents = \Elementor\Plugin::$instance->documents;

	$ctx = array(
		'img'  => $img,
		'date' => wp_date( 'j F Y' ),
		'u'    => function ( $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			return $page ? get_permalink( $page ) : home_url( '/' . $slug . '/' );
		},
	);

	/* ---- Pass 1: make sure every page exists, so links resolve ---- */
	$page_ids = array();

	foreach ( $registry['pages'] as $slug => $def ) {
		$page_ids[ $slug ] = nxt_sample_ensure_page( $slug, $def[0], '', 'elementor_header_footer', $report );
	}

	// Nexora plugin pages (plain shortcode pages, no Elementor needed)
	$plugin_pages = array(
		'login-page'        => array( 'Login', '[profile_login]' ),
		'registration-page' => array( 'Registration', '[profile_registration]' ),
		'profile-page'      => array( 'Profile', '[profile_dashboard]' ),
	);

	foreach ( $plugin_pages as $slug => $def ) {
		nxt_sample_ensure_page( $slug, $def[0], $def[1], '', $report, false );
	}

	/* ---- Pass 2: fill pages with their Elementor layouts ---- */
	foreach ( $registry['pages'] as $slug => $def ) {
		$post_id = $page_ids[ $slug ];

		if ( ! $post_id ) {
			continue;
		}

		$is_ours  = get_post_meta( $post_id, NXT_SAMPLE_MARK, true ) === $slug;
		$has_data = (bool) get_post_meta( $post_id, '_elementor_data', true );

		if ( ! $is_ours ) {
			continue; // a page the user made: never touched
		}

		if ( $has_data && ! $overwrite ) {
			$report[] = sprintf( 'Page "%s" already has a layout, kept (tick "replace" to rebuild it).', $slug );
			continue;
		}

		$elements = call_user_func( $def[1], $ctx );

		if ( nxt_sample_save_elements( $post_id, $elements ) ) {
			$report[] = sprintf( 'Built the Elementor layout for: %s', $def[0] );
		} else {
			$report[] = sprintf( 'Could not build the Elementor layout for: %s', $def[0] );
		}
	}

	/* ---- Library templates (one per page layout) ---- */
	foreach ( $registry['pages'] as $slug => $def ) {
		$tpl_id = nxt_find_sample_post( 'elementor_library', 'page:' . $slug );

		if ( $tpl_id && ! $overwrite ) {
			continue;
		}

		$elements = call_user_func( $def[1], $ctx );

		if ( ! $tpl_id ) {
			$doc = $documents->create(
				'page',
				array(
					'post_title'  => 'Nexora - ' . $def[0],
					'post_status' => 'publish',
				),
				array( NXT_SAMPLE_MARK => 'page:' . $slug )
			);

			if ( is_wp_error( $doc ) ) {
				$report[] = 'Library template failed: ' . $doc->get_error_message();
				continue;
			}

			$tpl_id = $doc->get_main_id();
		}

		nxt_sample_save_elements( $tpl_id, $elements, 'page' );
		$report[] = sprintf( 'Saved library template: Nexora - %s', $def[0] );
	}

	/* ---- Theme Builder: header, footer, 404 (Elementor Pro) ---- */
	$pro = class_exists( '\ElementorPro\Plugin' ) && $documents->get_document_type( 'header', false );

	if ( $pro ) {
		$conditions = array(
			'header'    => array( 'include/general' ),
			'footer'    => array( 'include/general' ),
			'error-404' => array( 'include/singular/not_found404' ),
		);

		foreach ( $registry['theme'] as $type => $def ) {
			$tpl_id = nxt_find_sample_post( 'elementor_library', $type );

			if ( $tpl_id && ! $overwrite ) {
				$report[] = sprintf( 'Theme Builder %s already exists, kept.', $type );
				continue;
			}

			$elements = call_user_func( $def[1], $ctx );

			if ( ! $tpl_id ) {
				$doc = $documents->create(
					$type,
					array(
						'post_title'  => $def[0],
						'post_status' => 'publish',
					),
					array( NXT_SAMPLE_MARK => $type )
				);

				if ( is_wp_error( $doc ) ) {
					$report[] = sprintf( 'Theme Builder %s failed: %s', $type, $doc->get_error_message() );
					continue;
				}

				$tpl_id = $doc->get_main_id();
				$doc->update_meta( '_elementor_conditions', $conditions[ $type ] );
			}

			nxt_sample_save_elements( $tpl_id, $elements, $type );
			$report[] = sprintf( 'Theme Builder template ready: %s', $def[0] );
		}

		try {
			\ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager()->get_cache()->regenerate();
		} catch ( \Throwable $e ) {
			$report[] = 'Could not refresh Theme Builder conditions: ' . $e->getMessage();
		}
	} else {
		$report[] = __( 'Elementor Pro (Theme Builder) not available: Header, Footer and 404 templates were skipped.', 'nexora-theme' );
	}

	/* ---- Menus ---- */
	$main      = nxt_sample_menu_ensure( 'Nexora Main', array( 'home', 'about', 'faqs', 'contact' ), $report );
	$company   = nxt_sample_menu_ensure( 'Nexora Footer Company', array( 'about', 'faqs', 'contact' ), $report );
	nxt_sample_menu_ensure( 'Nexora Footer Legal', array( 'privacy-policy', 'terms-of-use', 'community-guidelines' ), $report );

	$locations = get_theme_mod( 'nav_menu_locations', array() );

	if ( $main && empty( $locations['primary'] ) ) {
		$locations['primary'] = $main;
	}

	if ( $company && empty( $locations['footer'] ) ) {
		$locations['footer'] = $company;
	}

	set_theme_mod( 'nav_menu_locations', $locations );

	/* ---- Site settings ---- */
	if ( ! empty( $page_ids['home'] ) && ( 'page' !== get_option( 'show_on_front' ) || ! (int) get_option( 'page_on_front' ) ) ) {
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $page_ids['home'] );
		$report[] = __( 'Home page set as the front page.', 'nexora-theme' );
	}

	if ( ! empty( $page_ids['privacy-policy'] ) && ! (int) get_option( 'wp_page_for_privacy_policy' ) ) {
		update_option( 'wp_page_for_privacy_policy', $page_ids['privacy-policy'] );
	}

	$settings = get_option( 'nxt_settings', array() );

	if ( empty( $settings['tagline'] ) ) {
		$settings['tagline'] = 'Connect, share and grow your network.';
		update_option( 'nxt_settings', $settings );
	}

	\Elementor\Plugin::$instance->files_manager->clear_cache();

	$report[] = __( 'Done. Open any page with "Edit with Elementor" to customise it. Visit Settings > Permalinks and press Save once if page links do not load.', 'nexora-theme' );

	return $report;
}
