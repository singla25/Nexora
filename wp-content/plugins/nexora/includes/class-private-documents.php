<?php

if (!defined('ABSPATH')) exit;

/**
 * ID documents (Aadhaar, driving licence, company ID) are never served from the
 * public uploads folder. When one is linked to a profile its files are moved to
 * uploads/nexora-private/ under unguessable names, and every URL WordPress builds
 * for it becomes a gated download (wp_ajax_nexora_document) that only the owner
 * or an administrator can open.
 *
 * Attachment IDs and profile meta keys are unchanged, so the profile UI, the
 * admin screens and existing data keep working.
 */
class Nexora_Private_Documents {

    const DIR      = 'nexora-private';
    const FLAG     = '_nexora_private';
    const ACTION   = 'nexora_document';

    /** Profile meta keys whose files must be private. */
    const DOC_KEYS = ['aadhaar_card', 'driving_license', 'company_id_card'];

    /** Profile meta keys whose files are shown to other members. */
    const PUBLIC_KEYS = ['profile_image', 'cover_image'];

    public function __construct() {

        // Protect on every path that links a document (front end, admin, imports)
        add_action('added_post_meta',   [$this, 'on_meta_change'], 10, 4);
        add_action('updated_post_meta', [$this, 'on_meta_change'], 10, 4);

        // Everything WordPress builds for a private attachment points at the gate
        add_filter('wp_get_attachment_url',       [$this, 'filter_url'], 10, 2);
        add_filter('wp_get_attachment_image_src', [$this, 'filter_image_src'], 10, 4);
        add_filter('wp_prepare_attachment_for_js', [$this, 'filter_js'], 10, 2);
        add_filter('wp_calculate_image_srcset',   [$this, 'filter_srcset'], 10, 5);

        add_action('wp_ajax_' . self::ACTION, [$this, 'serve']);

        if (defined('WP_CLI') && WP_CLI) {
            WP_CLI::add_command('nexora migrate-documents', [$this, 'cli_migrate']);
        }
    }

    /* ===============================
       STATE
    =============================== */
    public static function is_private($attachment_id) {
        return get_post_meta((int) $attachment_id, self::FLAG, true) === '1';
    }

    public static function dir() {
        $up = wp_upload_dir();
        return $up['basedir'] . '/' . self::DIR;
    }

    public static function url_for($attachment_id, $size = '') {

        $args = ['action' => self::ACTION, 'id' => (int) $attachment_id];

        if ($size !== '') {
            $args['size'] = $size;
        }

        return add_query_arg($args, admin_url('admin-ajax.php'));
    }

    /**
     * True when the attachment is already used by something other members can see
     * (profile / cover image, post thumbnail), so moving it would break that page.
     */
    public static function in_public_use($attachment_id) {

        global $wpdb;

        return (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->postmeta}
             WHERE meta_value = %s AND meta_key IN ('profile_image','cover_image','_thumbnail_id') LIMIT 1",
            (string) (int) $attachment_id
        ));
    }

    /* ===============================
       STORAGE
    =============================== */
    private static function ensure_dir() {

        $dir = self::dir();

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return false;
        }

        $htaccess = $dir . '/.htaccess';

        if (!file_exists($htaccess)) {
            file_put_contents(
                $htaccess,
                "# Nexora private documents: never served directly.\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
            );
        }

        if (!file_exists($dir . '/index.php')) {
            file_put_contents($dir . '/index.php', "<?php\n// Silence is golden.\n");
        }

        return true;
    }

    /**
     * Move an attachment (all sizes) into the private folder. Copy first, verify,
     * switch the database, and only then delete the originals.
     */
    public static function protect($attachment_id) {

        $attachment_id = (int) $attachment_id;

        if (get_post_type($attachment_id) !== 'attachment') {
            return false;
        }

        if (self::is_private($attachment_id)) {
            return true;
        }

        $file = get_attached_file($attachment_id);

        if (!$file || !file_exists($file) || !self::ensure_dir()) {
            return false;
        }

        $meta  = wp_get_attachment_metadata($attachment_id);
        $meta  = is_array($meta) ? $meta : [];
        $src_dir = dirname($file);
        $ext   = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $token = bin2hex(random_bytes(16));
        $base  = $attachment_id . '-' . $token;

        // old absolute path => new absolute path (every file that belongs to the attachment)
        $map = [$file => self::dir() . '/' . $base . ($ext ? '.' . $ext : '')];
        $new_sizes = [];

        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $name => $size) {
                if (empty($size['file'])) continue;
                $old = $src_dir . '/' . $size['file'];
                $new_name = $base . '-' . $name . ($ext ? '.' . $ext : '');
                if (file_exists($old)) {
                    $map[$old] = self::dir() . '/' . $new_name;
                    $size['file'] = $new_name;
                    $new_sizes[$name] = $size;
                }
            }
        }

        $new_original = null;
        if (!empty($meta['original_image'])) {
            $old = $src_dir . '/' . $meta['original_image'];
            if (file_exists($old)) {
                $new_original = $base . '-original' . ($ext ? '.' . $ext : '');
                $map[$old] = self::dir() . '/' . $new_original;
            }
        }

        // 1. copy and verify
        $copied = [];
        foreach ($map as $old => $new) {
            if (!@copy($old, $new) || filesize($old) !== filesize($new)) {
                foreach ($copied as $c) { @unlink($c); }
                @unlink($new);
                return false;
            }
            $copied[] = $new;
        }

        // 2. switch the database
        update_post_meta($attachment_id, '_wp_attached_file', self::DIR . '/' . basename($map[$file]));

        if (!empty($meta)) {
            $meta['file'] = self::DIR . '/' . basename($map[$file]);
            if (isset($meta['sizes'])) {
                $meta['sizes'] = $new_sizes;
            }
            if ($new_original) {
                $meta['original_image'] = $new_original;
            }
            wp_update_attachment_metadata($attachment_id, $meta);
        }

        update_post_meta($attachment_id, self::FLAG, '1');

        // 3. delete the public originals
        foreach (array_keys($map) as $old) {
            @unlink($old);
        }

        return true;
    }

    /* ===============================
       HOOKS
    =============================== */
    public function on_meta_change($meta_id, $post_id, $key, $value) {

        if (!in_array($key, self::DOC_KEYS, true) || get_post_type($post_id) !== 'user_profile') {
            return;
        }

        $attachment_id = absint($value);

        if ($attachment_id) {
            self::protect($attachment_id);
        }
    }

    public function filter_url($url, $attachment_id) {
        return self::is_private($attachment_id) ? self::url_for($attachment_id) : $url;
    }

    public function filter_image_src($image, $attachment_id, $size, $icon) {

        if (!$image || !self::is_private($attachment_id)) {
            return $image;
        }

        $name = is_string($size) ? $size : '';
        $image[0] = self::url_for($attachment_id, $name);

        return $image;
    }

    public function filter_js($response, $attachment) {

        if (!is_array($response) || !self::is_private($attachment->ID)) {
            return $response;
        }

        $response['url'] = self::url_for($attachment->ID);

        if (!empty($response['sizes']) && is_array($response['sizes'])) {
            foreach ($response['sizes'] as $name => &$size) {
                $size['url'] = self::url_for($attachment->ID, $name);
            }
            unset($size);
        }

        return $response;
    }

    public function filter_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        return self::is_private($attachment_id) ? false : $sources;
    }

    /* ===============================
       AUTHORIZATION + DOWNLOAD
    =============================== */
    public static function can_view($attachment_id, $user_id) {

        $attachment_id = (int) $attachment_id;
        $user_id       = (int) $user_id;

        if (!$user_id || get_post_type($attachment_id) !== 'attachment' || !self::is_private($attachment_id)) {
            return false;
        }

        if (user_can($user_id, 'manage_options')) {
            return true;
        }

        $profile_id = (int) get_user_meta($user_id, '_profile_id', true);

        if ($profile_id) {
            foreach (self::DOC_KEYS as $key) {
                if ((int) get_post_meta($profile_id, $key, true) === $attachment_id) {
                    return true;
                }
            }
        }

        // Uploaded by this member and not yet (or no longer) linked
        return (int) get_post_field('post_author', $attachment_id) === $user_id;
    }

    /**
     * Absolute path of the full file or of a named size; null when the size is
     * unknown. Only names that exist in the attachment metadata are accepted.
     */
    public static function resolve_path($attachment_id, $size = '') {

        $file = get_attached_file((int) $attachment_id);

        if (!$file) {
            return null;
        }

        if ($size === '') {
            return $file;
        }

        $meta = wp_get_attachment_metadata((int) $attachment_id);

        if (is_array($meta) && !empty($meta['sizes'][$size]['file'])) {
            $path = dirname($file) . '/' . basename($meta['sizes'][$size]['file']);
            return file_exists($path) ? $path : null;
        }

        return null;
    }

    public function serve() {

        $id   = absint($_GET['id'] ?? 0);
        $size = isset($_GET['size']) ? sanitize_key(wp_unslash($_GET['size'])) : '';

        if (!is_user_logged_in()) {
            wp_die('Please log in.', '', ['response' => 401]);
            return;
        }

        if (!self::can_view($id, get_current_user_id())) {
            wp_die('You cannot view this document.', '', ['response' => 403]);
            return;
        }

        $path = self::resolve_path($id, $size);

        if (!$path || !is_file($path)) {
            wp_die('File not found.', '', ['response' => 404]);
            return;
        }

        $type = wp_check_filetype($path);
        $mime = $type['type'] ?: 'application/octet-stream';
        $inline = strpos($mime, 'image/') === 0;

        $headers = [
            'Content-Type'           => $mime,
            'Content-Length'         => (string) filesize($path),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control'          => 'private, no-store, max-age=0',
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="document-' . $id . ($type['ext'] ? '.' . $type['ext'] : '') . '"',
        ];

        nocache_headers();

        foreach (apply_filters('nexora_document_headers', $headers, $id) as $name => $value) {
            header($name . ': ' . $value);
        }

        readfile($path);
        exit;
    }

    /* ===============================
       MIGRATION OF EXISTING FILES
    =============================== */

    /**
     * Move every ID document that is still public. Idempotent: private ones are skipped.
     *
     * @return array{moved:int,failed:int,skipped:int,would_move:int,errors:string[]}
     */
    public static function migrate_all($dry_run = false) {

        global $wpdb;

        $result = ['moved' => 0, 'failed' => 0, 'skipped' => 0, 'would_move' => 0, 'errors' => []];

        $placeholders = implode(',', array_fill(0, count(self::DOC_KEYS), '%s'));

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'user_profile'
             WHERE pm.meta_key IN ($placeholders) AND pm.meta_value REGEXP '^[0-9]+$' AND pm.meta_value <> '0'",
            self::DOC_KEYS
        ));

        foreach ($ids as $id) {

            $id = (int) $id;

            if (get_post_type($id) !== 'attachment' || self::is_private($id)) {
                $result['skipped']++;
                continue;
            }

            if ($dry_run) {
                $result['would_move']++;
                continue;
            }

            if (self::protect($id)) {
                $result['moved']++;
            } else {
                $result['failed']++;
                $result['errors'][] = "attachment $id could not be moved (missing file or unwritable folder)";
            }
        }

        return $result;
    }

    /**
     * wp nexora migrate-documents [--dry-run]
     */
    public function cli_migrate($args, $assoc_args) {

        $dry = !empty($assoc_args['dry-run']);
        $res = self::migrate_all($dry);

        WP_CLI::log(sprintf('moved=%d failed=%d skipped=%d would_move=%d', $res['moved'], $res['failed'], $res['skipped'], $res['would_move']));

        foreach ($res['errors'] as $e) {
            WP_CLI::warning($e);
        }

        if ($res['failed']) {
            WP_CLI::halt(1);
        }

        WP_CLI::success($dry ? 'Dry run finished, nothing changed.' : 'Done.');
    }
}
