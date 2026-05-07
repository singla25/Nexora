<?php
/**
 * dashboard/dashboard.php
 *
 * Single include point for the entire Dashboard module.
 * Drop ONE line in your main plugin file:  require_once NEXORA_PATH . '/dashboard/dashboard.php';
 * 
 * Load order (dependency order):
 *   helper  →  no deps (pure static utilities)
 *   ajax    →  depends on helper
 *   core    →  depends on helper
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ── Module constants ──────────────────────────────────────────────────────────
if ( ! defined( 'NEXORA_DASHBOARD_TEMPLATES' ) ) {
    define( 'NEXORA_DASHBOARD_TEMPLATES', NEXORA_PATH . 'templates/' );
}

// ── Autoload classes ──────────────────────────────────────────────────────────
require_once __DIR__ . '/class-dashboard-helper.php';
require_once __DIR__ . '/class-dashboard-ajax.php';
require_once __DIR__ . '/class-dashboard-core.php';
require_once __DIR__ . '/class-dashboard-admin-ajax.php';

// ── Instantiate (hooks registered inside constructors) ────────────────────────
new NEXORA_DASHBOARD_CORE();
new NEXORA_DASHBOARD_AJAX();
new NEXORA_ADMIN_PROFILE_AJAX();

/*
 * ── How to extend this module ─────────────────────────────────────────────────
 *
 * ADD A ROLE
 *   1. Register the WP role in your plugin setup.
 *   2. Create the profile CPT.
 *   3. Add ONE entry to NEXORA_DASHBOARD_HELPER::ROLE_MAP.
 *   4. Update get_visible_tabs() for the new role's visibility rules.
 *   5. Update get_info_subtabs() if the role needs different sub-tabs.
 *   Done — routing, JS payload, rendering all work automatically.
 *
 *   NOTE: administrator is a built-in role with no CPT. It is handled
 *   as a special case in NEXORA_DASHBOARD_HELPER via resolve_admin_context()
 *   and build_user_data( 0, $ctx ). Do not add it to ROLE_MAP.
 *
 * INJECT MARKETPLACE CONTENT
 *   add_filter( 'nexora_marketplace_content', fn($html, $ctx) =>
 *       do_shortcode('[your_marketplace]'), 10, 2 );
 *
 * ADD EXTRA INFO SECTIONS
 *   add_action( 'nexora_dashboard_user_info_after_docs', fn($ctx) => ... );
 *
 * MODIFY TAB LABELS
 *   add_filter( 'nexora_dashboard_tab_labels', fn($labels) => array_merge($labels, ['my-tab' => 'My Tab']) );
 */
