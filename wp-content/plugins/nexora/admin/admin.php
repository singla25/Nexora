<?php
/**
 * admin/admin.php
 *
 * Single entry point for the entire admin module.
 * Drop ONE line in your main plugin file:
 *   require_once __DIR__ . '/admin/admin.php';
 *
 * Load order (dependency order):
 *   field-renderer  →  no deps (pure render helpers)
 *   metabox-user    →  depends on field-renderer
 *   metabox-vendor  →  depends on field-renderer
 *   cpt-columns     →  no deps
 *   admin-pages     →  no deps
 *   cpt-register    →  depends on all of the above (registers hooks + delegates)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/class-cpt-register.php';
require_once __DIR__ . '/class-cpt-field-renderer.php';
require_once __DIR__ . '/class-metabox-user.php';
require_once __DIR__ . '/class-metabox-vendor.php';
require_once __DIR__ . '/class-cpt-columns.php';
require_once __DIR__ . '/class-admin-pages.php';


// Boot — all hooks live inside the constructors
new NEXORA_CPT_Register();
