<?php
/**
 * Help Center - Main Entry Point
 * @version 3.10.10
 */

if (!defined('ABSPATH')) exit;

// Optional Composer dependency for RFC 8291 encrypted Web Push delivery.
$hc_push_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($hc_push_autoload)) require_once $hc_push_autoload;

// Load the companion HCK ticket system from the same child-theme root. require_once
// keeps this safe when the theme already loads hck-loader.php separately.
$hc_hck_loader = get_stylesheet_directory() . '/hck-ticket/hck-loader.php';
if (file_exists($hc_hck_loader) && !defined('HCK_VERSION')) require_once $hc_hck_loader;

define('HC_VERSION', '3.10.10');
define('HC_PATH',       get_stylesheet_directory() . '/helpcenter/');
define('HC_URL',        get_stylesheet_directory_uri() . '/helpcenter/');
define('HC_TEMPLATES',  HC_PATH . 'templates/');
define('HC_ADMIN_PATH', HC_PATH . 'admin/');
define('HC_ASSETS',     HC_URL  . 'assets/');
define('HC_ADMIN_URL',  HC_URL  . 'admin/');

$hc_files = array(
    'class-hc-database.php',
    'class-hc-core.php',
    'class-hc-lifecycle.php',
    'class-hc-rewrite.php',
    'class-hc-schema.php',
    'class-hc-seo.php',
    'class-hc-ajax.php',
    'class-hc-assets.php',
    'class-hc-admin-assets.php',
    'class-hc-admin.php',
    'class-hc-ai-platform.php',
    'class-hc-web-push.php',
    'class-hc-ai.php',
    'class-hc-live-support.php',
    'class-hc-realtime.php',
    'class-hc-agent-portal.php',
);

foreach ($hc_files as $file) {
    $path = HC_PATH . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}

add_action('init', 'hc_boot', 5);
function hc_boot() {
    if (class_exists('HC_Database')) {
        HC_Database::init();
    }
    if (class_exists('HC_Rewrite')) {
        HC_Rewrite::register();
    }
    if (class_exists('HC_Ajax')) {
        HC_Ajax::register();
    }
    if (class_exists('HC_AI_Platform')) { HC_AI_Platform::init(); }
    if (class_exists('HC_Web_Push')) { HC_Web_Push::register(); }
    if (class_exists('HC_AI')) { HC_AI::register(); }
    if (class_exists('HC_Live_Support')) { HC_Live_Support::register(); }
    if (class_exists('HC_Realtime')) { HC_Realtime::register(); }
    if (class_exists('HC_Agent_Portal')) { HC_Agent_Portal::register(); }
    if (class_exists('HC_SEO')) {
        HC_SEO::register();
    }
}

add_action('template_redirect', 'hc_router', 1);
function hc_router() {
    $page = get_query_var('hc_page');
    if (!$page) return;

    global $wp_query;
    $wp_query->is_404 = false;
    status_header(200);

    $admin_pages = array(
        'admin-dashboard', 'admin-hub', 'admin-articles', 'admin-article-edit',
        'admin-categories', 'admin-category-edit', 'admin-popular',
        'admin-faqs', 'admin-faq-edit', 'admin-revisions',
        'admin-search-log', 'admin-seo-audit', 'admin-performance-audit', 'admin-settings', 'admin-ai-platform', 'admin-human-support', 'admin-agents', 'admin-web-push',
    );

    if (in_array($page, $admin_pages) && !current_user_can('manage_options') && !(class_exists('HC_Live_Support') && HC_Live_Support::can_manage() && ($page === 'admin-human-support' || ($page === 'admin-hub' && sanitize_key(get_query_var('hc_hub')) === 'support-operations')))) {
        wp_safe_redirect(home_url('/helpcenter/'));
        exit;
    }

    if (in_array($page, $admin_pages)) {
        include HC_ADMIN_PATH . 'wrapper.php';
    } else {
        include HC_TEMPLATES . 'wrapper.php';
    }
    exit;
}

add_action('after_switch_theme', function() {
    if (class_exists('HC_Rewrite')) {
        HC_Rewrite::register();
    }
    flush_rewrite_rules();
});

add_action('init', function() {
    $installed_version = get_option('hc_installed_version', '');
    if ($installed_version !== HC_VERSION) {
        if (class_exists('HC_Rewrite')) HC_Rewrite::register();
        flush_rewrite_rules(false);
        update_option('hc_installed_version', HC_VERSION, false);
    }
}, 999);

add_action('init', function() {
    if (!current_user_can('manage_options')) return;
    if (!isset($_GET['hc_flush'])) return;
    if (class_exists('HC_Rewrite')) HC_Rewrite::register();
    flush_rewrite_rules();
    wp_die('Help Center rewrites flushed. Remove ?hc_flush=1 from the URL.');
}, 999);