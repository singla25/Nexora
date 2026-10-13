<?php
/**
 * Sample Elementor layouts for Nexora: header, footer, 404 and the pages.
 *
 * Every function receives a context array:
 *   'img' => array( 'url' => ..., 'id' => ... )   the sample image
 *   'u'   => callable( $slug )                    URL of a site page
 *   'date'=> string                               "last updated" date
 *
 * Numbers and site details are dynamic: they come from shortcodes
 * ([nexora_stat], [nexora_auth_buttons], [nxt_setting], [nxt_logo]).
 *
 * @package Nexora_Theme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ===========================================================
   Reusable pieces
=========================================================== */

function nxt_tpl_page_hero( $eyebrow, $title, $lede = '' ) {
	$items = array(
		NXT_EL::heading( $eyebrow, 'p', 'nxe-eyebrow' ),
		NXT_EL::heading( $title, 'h1', 'nxe-h1' ),
	);

	if ( $lede ) {
		$items[] = NXT_EL::text( '<p>' . $lede . '</p>', 'nxe-lede' );
	}

	return NXT_EL::container( array( NXT_EL::wrap( $items ) ), 'nxe-page-hero' );
}

function nxt_tpl_section_title( $eyebrow, $title, $lede = '' ) {
	$items = array(
		NXT_EL::heading( $eyebrow, 'p', 'nxe-eyebrow nxe-eyebrow--center' ),
		NXT_EL::heading( $title, 'h2', 'nxe-h2 nxe-center' ),
	);

	if ( $lede ) {
		$items[] = NXT_EL::text( '<p>' . $lede . '</p>', 'nxe-lede nxe-center' );
	}

	return NXT_EL::container( $items, 'nxe-section-title' );
}

function nxt_tpl_card( $icon, $title, $text ) {
	return NXT_EL::container(
		array(
			NXT_EL::heading( $icon, 'p', 'nxe-card-icon' ),
			NXT_EL::heading( $title, 'h3', 'nxe-h3' ),
			NXT_EL::text( '<p>' . $text . '</p>' ),
		),
		'nxe-card'
	);
}

function nxt_tpl_stat( $type, $label ) {
	return NXT_EL::container(
		array(
			NXT_EL::shortcode( '[nexora_stat type="' . $type . '"]', 'nxe-stat__num' ),
			NXT_EL::text( '<p>' . $label . '</p>', 'nxe-stat__label' ),
		),
		'nxe-stat'
	);
}

function nxt_tpl_step( $n, $title, $text ) {
	return NXT_EL::container(
		array(
			NXT_EL::heading( (string) $n, 'p', 'nxe-step__num' ),
			NXT_EL::heading( $title, 'h3', 'nxe-h3 nxe-center' ),
			NXT_EL::text( '<p>' . $text . '</p>', 'nxe-text nxe-center' ),
		),
		'nxe-step'
	);
}

function nxt_tpl_quote( $quote, $name, $role ) {
	return NXT_EL::container(
		array(
			NXT_EL::text( '<p>&ldquo;' . $quote . '&rdquo;</p>', 'nxe-quote__text' ),
			NXT_EL::text( '<p><strong>' . $name . '</strong>' . $role . '</p>', 'nxe-quote__who' ),
		),
		'nxe-quote'
	);
}

function nxt_tpl_cta( $heading, $lede, $buttons ) {
	$btns = array();
	foreach ( $buttons as $b ) {
		$btns[] = NXT_EL::button( $b[0], $b[1], $b[2] );
	}

	return NXT_EL::container(
		array(
			NXT_EL::wrap(
				array(
					NXT_EL::heading( $heading, 'h2', 'nxe-h2 nxe-center' ),
					NXT_EL::text( '<p>' . $lede . '</p>', 'nxe-lede nxe-center' ),
					NXT_EL::container( $btns, 'nxe-btn-row' ),
				)
			),
		),
		'nxe-cta'
	);
}

/**
 * Standard sign-up banner used on most pages.
 */
function nxt_tpl_signup_cta( $c ) {
	return nxt_tpl_cta(
		'Join Nexora today',
		'Create your free account, build your profile and start connecting in minutes.',
		array(
			array( 'Create your account', call_user_func( $c['u'], 'registration-page' ), 'inverse' ),
			array( 'Log in', call_user_func( $c['u'], 'login-page' ), 'inverse' ),
		)
	);
}

/* ===========================================================
   Header / footer / 404
=========================================================== */

function nxt_tpl_header( $c ) {
	return array(
		NXT_EL::container(
			array(
				NXT_EL::container(
					array(
						NXT_EL::shortcode( '[nxt_logo]' ),
						NXT_EL::container(
							array(
								NXT_EL::nav_menu( 'nexora-main', 'horizontal' ),
								NXT_EL::shortcode( '[nexora_auth_buttons]' ),
							),
							'nxe-header__right'
						),
					),
					'nxe-wrap nxe-header__inner'
				),
			),
			'nxe-header'
		),
	);
}

function nxt_tpl_footer( $c ) {
	return array(
		NXT_EL::container(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								NXT_EL::container(
									array(
										NXT_EL::shortcode( '[nxt_logo]' ),
										NXT_EL::text( '<p>[nxt_setting key="tagline" default="Connect, share and grow your network."]</p>' ),
									)
								),
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Company', 'h4', 'nxe-footer__title' ),
										NXT_EL::nav_menu( 'nexora-footer-company', 'vertical' ),
									)
								),
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Legal', 'h4', 'nxe-footer__title' ),
										NXT_EL::nav_menu( 'nexora-footer-legal', 'vertical' ),
									)
								),
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Contact', 'h4', 'nxe-footer__title' ),
										NXT_EL::text(
											'<p>[nxt_setting key="email" default="hello@example.com" link="mailto"]<br>[nxt_setting key="phone" default="+00 000 000 0000" link="tel"]<br>[nxt_setting key="address" default="Your city, Country"]</p>'
										),
									)
								),
							),
							'nxe-footer__grid'
						),
						NXT_EL::container(
							array( NXT_EL::text( '<p>&copy; [nxt_year] [nxt_setting key="site_name"]. All rights reserved.</p>' ) ),
							'nxe-footer__bottom'
						),
					)
				),
			),
			'nxe-footer'
		),
	);
}

function nxt_tpl_404( $c ) {
	return array(
		NXT_EL::container(
			array(
				NXT_EL::heading( '404', 'p', 'nxe-eyebrow nxe-eyebrow--center' ),
				NXT_EL::heading( 'Page not found', 'h1', 'nxe-h1 nxe-center' ),
				NXT_EL::text( '<p>The page you are looking for does not exist or may have moved.</p>', 'nxe-lede nxe-center' ),
				NXT_EL::container(
					array(
						NXT_EL::button( 'Go to homepage', home_url( '/' ), 'primary' ),
						NXT_EL::button( 'Contact us', call_user_func( $c['u'], 'contact' ), 'secondary' ),
					),
					'nxe-btn-row'
				),
			),
			'nxe-404 nxe-center'
		),
	);
}

/* ===========================================================
   Pages
=========================================================== */

function nxt_tpl_home( $c ) {
	$reg   = call_user_func( $c['u'], 'registration-page' );
	$login = call_user_func( $c['u'], 'login-page' );
	$about = call_user_func( $c['u'], 'about' );

	return array(

		// Hero
		NXT_EL::container(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Your professional network', 'p', 'nxe-eyebrow' ),
										NXT_EL::heading( 'Connect. Grow. Discover.', 'h1', 'nxe-h1' ),
										NXT_EL::text( '<p>Nexora helps you build a meaningful network. Create a profile, connect with people you trust and chat in organised, subject-based conversations.</p>', 'nxe-lede' ),
										NXT_EL::container(
											array(
												NXT_EL::button( 'Get started', $reg, 'primary' ),
												NXT_EL::button( 'Log in', $login, 'secondary' ),
											),
											'nxe-btn-row'
										),
									),
									'nxe-hero__text'
								),
								NXT_EL::image( $c['img'], 'nxe-media', 'Nexora preview' ),
							),
							'nxe-hero__grid'
						),
					)
				),
			),
			'nxe-hero'
		),

		// Live stats
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								nxt_tpl_stat( 'members', 'Members' ),
								nxt_tpl_stat( 'connections', 'Connections' ),
								nxt_tpl_stat( 'posts', 'Posts shared' ),
								nxt_tpl_stat( 'chats', 'Conversations' ),
							),
							'nxe-grid nxe-grid--4'
						),
					)
				),
			),
			'nxe-section--tint nxe-section--tight'
		),

		// Features
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						nxt_tpl_section_title( 'Why Nexora', 'Everything you need to grow your network', 'Simple tools that keep conversations organised and your information in your hands.' ),
						NXT_EL::container(
							array(
								nxt_tpl_card( '💬', 'Real-time chat', 'Instant conversations with subject-based threads, so nothing gets lost.' ),
								nxt_tpl_card( '🤝', 'Smart connections', 'Send and accept requests, see mutual connections and build a network you trust.' ),
								nxt_tpl_card( '📝', 'Share content', 'Post updates with images and keep a personal history of what you share.' ),
								nxt_tpl_card( '🔔', 'Notifications', 'Stay up to date on requests and responses without checking everywhere.' ),
								nxt_tpl_card( '👤', 'Your profile', 'Showcase your identity, work and documents. You decide what others see.' ),
								nxt_tpl_card( '🔒', 'Privacy first', 'Your contact details and documents are visible to you only.' ),
							),
							'nxe-grid nxe-grid--3'
						),
					)
				),
			)
		),

		// Split: profile
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								NXT_EL::image( $c['img'], 'nxe-media', 'Profile preview' ),
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Your profile', 'p', 'nxe-eyebrow' ),
										NXT_EL::heading( 'A profile that works for you', 'h2', 'nxe-h2' ),
										NXT_EL::text( '<p>Add your personal, address and work details, upload a photo and a cover image, and keep everything up to date from one dashboard.</p>', 'nxe-text' ),
										NXT_EL::text( '<ul><li>Personal, address and work sections</li><li>Profile and cover images</li><li>Secure document uploads</li><li>Password and account controls</li></ul>', 'nxe-check' ),
										NXT_EL::button( 'Learn more about us', $about, 'secondary' ),
									),
									'nxe-split__text'
								),
							),
							'nxe-split'
						),
					)
				),
			),
			'nxe-section--tint'
		),

		// How it works
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						nxt_tpl_section_title( 'How it works', 'Up and running in four steps' ),
						NXT_EL::container(
							array(
								nxt_tpl_step( 1, 'Sign up', 'Create your free account in a minute.' ),
								nxt_tpl_step( 2, 'Build your profile', 'Add your details and a photo.' ),
								nxt_tpl_step( 3, 'Connect', 'Find people and send requests.' ),
								nxt_tpl_step( 4, 'Share and chat', 'Post updates and start conversations.' ),
							),
							'nxe-grid nxe-grid--4'
						),
					)
				),
			)
		),

		// Testimonials (sample copy: replace with real feedback)
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						nxt_tpl_section_title( 'Loved by members', 'What people are saying' ),
						NXT_EL::container(
							array(
								nxt_tpl_quote( 'Nexora helped me grow my network faster than I expected.', 'Sample Name', 'Entrepreneur' ),
								nxt_tpl_quote( 'Clean, simple and the subject-based chats keep me organised.', 'Sample Name', 'Developer' ),
								nxt_tpl_quote( 'I found collaborators in my first week.', 'Sample Name', 'Designer' ),
							),
							'nxe-grid nxe-grid--3'
						),
					)
				),
			),
			'nxe-section--tint'
		),

		nxt_tpl_signup_cta( $c ),
	);
}

function nxt_tpl_about( $c ) {
	$reg     = call_user_func( $c['u'], 'registration-page' );
	$contact = call_user_func( $c['u'], 'contact' );

	return array(
		nxt_tpl_page_hero( 'About us', 'Built to help people connect', 'Nexora is a networking platform where members build a profile, make trusted connections and talk in organised conversations.' ),

		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								NXT_EL::container(
									array(
										NXT_EL::heading( 'Our story', 'p', 'nxe-eyebrow' ),
										NXT_EL::heading( 'Networking without the noise', 'h2', 'nxe-h2' ),
										NXT_EL::text( '<p>We started Nexora because most networks reward volume over relationships. Here you connect with people you actually want to hear from, and every conversation has a subject, so it is easy to find again.</p><p>This is sample text. Replace it with your own story, mission and milestones.</p>', 'nxe-text' ),
										NXT_EL::button( 'Join Nexora', $reg, 'primary' ),
									),
									'nxe-split__text'
								),
								NXT_EL::image( $c['img'], 'nxe-media', 'Our story' ),
							),
							'nxe-split'
						),
					)
				),
			)
		),

		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						nxt_tpl_section_title( 'What we stand for', 'Our values' ),
						NXT_EL::container(
							array(
								nxt_tpl_card( '🤝', 'Trust', 'Connections are mutual. You only talk to people who accepted your request.' ),
								nxt_tpl_card( '🔒', 'Privacy', 'Your contact details and documents stay private to you.' ),
								nxt_tpl_card( '🌱', 'Community', 'We build features that help members support each other and grow.' ),
							),
							'nxe-grid nxe-grid--3'
						),
					)
				),
			),
			'nxe-section--tint'
		),

		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								nxt_tpl_stat( 'members', 'Members' ),
								nxt_tpl_stat( 'connections', 'Connections' ),
								nxt_tpl_stat( 'posts', 'Posts shared' ),
								nxt_tpl_stat( 'chats', 'Conversations' ),
							),
							'nxe-grid nxe-grid--4'
						),
					)
				),
			),
			'nxe-section--tight'
		),

		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						nxt_tpl_section_title( 'The team', 'People behind Nexora', 'Sample names and photos. Replace them with your team.' ),
						NXT_EL::container(
							array(
								nxt_tpl_person( $c, 'Sample Name', 'Founder' ),
								nxt_tpl_person( $c, 'Sample Name', 'Product' ),
								nxt_tpl_person( $c, 'Sample Name', 'Engineering' ),
							),
							'nxe-grid nxe-grid--3'
						),
					)
				),
			),
			'nxe-section--tint'
		),

		nxt_tpl_cta(
			'Have a question?',
			'We would love to hear from you.',
			array(
				array( 'Contact us', $contact, 'inverse' ),
			)
		),
	);
}

function nxt_tpl_person( $c, $name, $role ) {
	return NXT_EL::container(
		array(
			NXT_EL::image( $c['img'], 'nxe-avatar', $name ),
			NXT_EL::heading( $name, 'h3', 'nxe-h3 nxe-center' ),
			NXT_EL::text( '<p>' . $role . '</p>', 'nxe-text nxe-center' ),
		),
		'nxe-person nxe-card'
	);
}

function nxt_tpl_contact( $c ) {
	return array(
		nxt_tpl_page_hero( 'Contact', 'Get in touch', 'Questions, feedback or need help? Send us a message and we will reply as soon as we can.' ),

		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::container(
							array(
								NXT_EL::container(
									array(
										nxt_tpl_info( 'Email', '[nxt_setting key="email" default="hello@example.com" link="mailto"]' ),
										nxt_tpl_info( 'Phone', '[nxt_setting key="phone" default="+00 000 000 0000" link="tel"]' ),
										nxt_tpl_info( 'Address', '[nxt_setting key="address" default="Your city, Country"]' ),
									),
									'nxe-contact__info'
								),
								NXT_EL::shortcode( '[nexora_contact_form]' ),
							),
							'nxe-contact'
						),
					)
				),
			)
		),
	);
}

function nxt_tpl_info( $label, $value ) {
	return NXT_EL::container(
		array(
			NXT_EL::text( '<p><small>' . $label . '</small>' . $value . '</p>', 'nxe-info-item' ),
		),
		'nxe-card'
	);
}

function nxt_tpl_faqs( $c ) {
	$contact = call_user_func( $c['u'], 'contact' );

	$groups = array(
		'Account'     => array(
			array( 'How do I create an account?', 'Click Sign up, fill in your details and submit the form. You are logged in straight away.' ),
			array( 'I forgot my password. What now?', 'Use Forgot password on the login page. We email a one-time code that lets you set a new password.' ),
			array( 'Can I change my username?', 'Usernames are permanent because they are part of your profile address. You can edit everything else from your dashboard.' ),
		),
		'Connections' => array(
			array( 'How do connections work?', 'Send a request from your Connections tab. The other person accepts or declines. Once accepted, you can chat.' ),
			array( 'Can I remove a connection?', 'Yes. Remove it from your connections list. Conversations with that person are closed.' ),
		),
		'Chat'        => array(
			array( 'What are subject-based chats?', 'Every conversation starts with a subject, like an email thread, so you can find it again later.' ),
			array( 'Who can message me?', 'Only people you are connected with.' ),
		),
		'Privacy'     => array(
			array( 'Who can see my details?', 'Your email, phone, address and ID documents are visible to you only. Others see your name, photo and bio.' ),
			array( 'How do I delete my account?', 'Contact us from the Contact page and we will remove your account and data.' ),
		),
	);

	$html = '';
	foreach ( $groups as $title => $items ) {
		$html .= '<h2>' . esc_html( $title ) . '</h2>';
		foreach ( $items as $item ) {
			$html .= '<details><summary>' . esc_html( $item[0] ) . '</summary><p>' . esc_html( $item[1] ) . '</p></details>';
		}
	}

	return array(
		nxt_tpl_page_hero( 'FAQs', 'Frequently asked questions', 'Quick answers to common questions. This is sample content: edit it to match your platform.' ),
		NXT_EL::section( array( NXT_EL::wrap( array( NXT_EL::html( $html, 'nxe-faq' ) ) ) ) ),
		nxt_tpl_cta( 'Still have questions?', 'Our team is happy to help.', array( array( 'Contact us', $contact, 'inverse' ) ) ),
	);
}

function nxt_tpl_legal_page( $c, $eyebrow, $title, $lede, $html ) {
	$note = '<p class="nxe-note"><strong>Sample content.</strong> This text is a starting template, not legal advice. Have it reviewed by a qualified professional and adapt it to your business and jurisdiction before publishing. Last updated: ' . esc_html( $c['date'] ) . '.</p>';

	return array(
		nxt_tpl_page_hero( $eyebrow, $title, $lede ),
		NXT_EL::section(
			array(
				NXT_EL::wrap(
					array(
						NXT_EL::html( '<div class="nxe-prose">' . $note . $html . '</div>' ),
					)
				),
			)
		),
	);
}

function nxt_tpl_privacy( $c ) {
	$html = '
<h2>1. Who we are</h2>
<p>Nexora ("we", "us") operates this networking platform. This policy explains what personal information we collect, how we use it and the choices you have.</p>
<h2>2. Information we collect</h2>
<ul>
<li><strong>Account details:</strong> username, email address, password (stored hashed), name, phone number, gender and date of birth.</li>
<li><strong>Profile details you add:</strong> address, work information, profile and cover images and identity documents you choose to upload.</li>
<li><strong>Activity:</strong> connection requests, messages, posts and notifications.</li>
<li><strong>Technical data:</strong> IP address and cookies needed to keep you logged in and protect the service from abuse.</li>
</ul>
<h2>3. How we use your information</h2>
<ul>
<li>To create and run your account and show your profile to other members.</li>
<li>To send one-time codes, notifications and service emails.</li>
<li>To keep the platform secure and prevent spam and fraud.</li>
<li>To respond to your messages and improve the service.</li>
</ul>
<h2>4. What other members can see</h2>
<p>Your email address, phone number, date of birth, address and identity documents are visible only to you. Other members can see your username, name, profile and cover images and bio.</p>
<h2>5. Sharing</h2>
<p>We do not sell your personal information. We share it only with service providers who help us run the platform (such as hosting and email delivery), when required by law, or with your consent.</p>
<h2>6. Cookies</h2>
<p>We use essential cookies to keep you signed in and to protect forms. If you enable optional services (for example reCAPTCHA), they may set their own cookies.</p>
<h2>7. Retention and security</h2>
<p>We keep your information while your account is active. We use technical measures such as hashed passwords, access controls and rate limiting to protect it, but no system is completely secure.</p>
<h2>8. Your rights</h2>
<p>You can view and edit your information from your dashboard, and you can ask us to export or delete your data at any time using the <a href="' . esc_url( call_user_func( $c['u'], 'contact' ) ) . '">contact page</a>.</p>
<h2>9. Changes</h2>
<p>We may update this policy. We will change the date at the top of the page when we do.</p>
<h2>10. Contact</h2>
<p>Questions about this policy? <a href="' . esc_url( call_user_func( $c['u'], 'contact' ) ) . '">Contact us</a>.</p>';

	return nxt_tpl_legal_page( $c, 'Legal', 'Privacy Policy', 'How we collect, use and protect your personal information.', $html );
}

function nxt_tpl_terms( $c ) {
	$html = '
<h2>1. Agreement</h2>
<p>By creating an account or using Nexora you agree to these terms. If you do not agree, please do not use the service.</p>
<h2>2. Your account</h2>
<ul>
<li>You must provide accurate information and keep your password secret.</li>
<li>You are responsible for activity under your account.</li>
<li>You must be old enough to enter a binding agreement in your country.</li>
</ul>
<h2>3. Acceptable use</h2>
<p>You agree not to harass others, post unlawful or misleading content, upload malware, scrape the service, or try to access other members\' accounts or data. See our <a href="' . esc_url( call_user_func( $c['u'], 'community-guidelines' ) ) . '">Community Guidelines</a>.</p>
<h2>4. Your content</h2>
<p>You keep ownership of what you post. You give us permission to store and display it as needed to run the service. You are responsible for having the right to share it.</p>
<h2>5. Connections and messages</h2>
<p>Messages can only be exchanged between connected members. You can remove a connection at any time, which closes shared conversations.</p>
<h2>6. Suspension and termination</h2>
<p>We may suspend or remove accounts that break these terms. You may stop using the service and ask us to delete your account at any time.</p>
<h2>7. Disclaimer and liability</h2>
<p>The service is provided "as is". To the extent permitted by law we are not liable for indirect or consequential losses arising from your use of it.</p>
<h2>8. Changes</h2>
<p>We may update these terms. Continuing to use the service after a change means you accept the new terms.</p>
<h2>9. Contact</h2>
<p>Questions? <a href="' . esc_url( call_user_func( $c['u'], 'contact' ) ) . '">Contact us</a>.</p>';

	return nxt_tpl_legal_page( $c, 'Legal', 'Terms of Use', 'The rules for using Nexora.', $html );
}

function nxt_tpl_guidelines( $c ) {
	$html = '
<h2>Be respectful</h2>
<p>Treat other members the way you would like to be treated. No harassment, hate speech or threats.</p>
<h2>Be honest</h2>
<p>Use your real identity and keep your profile accurate. Do not impersonate others or share misleading information.</p>
<h2>Protect privacy</h2>
<p>Do not share other people\'s private details without their permission, and never ask for passwords or one-time codes.</p>
<h2>Keep it relevant</h2>
<ul>
<li>No spam, unsolicited promotions or chain messages.</li>
<li>Send connection requests to people you actually know or want to work with.</li>
<li>Give conversations a clear subject.</li>
</ul>
<h2>Report problems</h2>
<p>If something looks wrong, tell us on the <a href="' . esc_url( call_user_func( $c['u'], 'contact' ) ) . '">contact page</a>. We may remove content or suspend accounts that break these guidelines.</p>';

	return nxt_tpl_legal_page( $c, 'Community', 'Community Guidelines', 'Help us keep Nexora a safe and useful place.', $html );
}

/**
 * Registry used by the installer.
 *
 * slug => array( title, builder, type )  type: page | header | footer | error-404
 */
function nxt_sample_registry() {
	return array(
		'pages' => array(
			'home'                 => array( 'Home', 'nxt_tpl_home' ),
			'about'                => array( 'About', 'nxt_tpl_about' ),
			'contact'              => array( 'Contact', 'nxt_tpl_contact' ),
			'faqs'                 => array( 'FAQs', 'nxt_tpl_faqs' ),
			'privacy-policy'       => array( 'Privacy Policy', 'nxt_tpl_privacy' ),
			'terms-of-use'         => array( 'Terms of Use', 'nxt_tpl_terms' ),
			'community-guidelines' => array( 'Community Guidelines', 'nxt_tpl_guidelines' ),
		),
		'theme' => array(
			'header'    => array( 'Nexora Header', 'nxt_tpl_header' ),
			'footer'    => array( 'Nexora Footer', 'nxt_tpl_footer' ),
			'error-404' => array( 'Nexora 404', 'nxt_tpl_404' ),
		),
	);
}
