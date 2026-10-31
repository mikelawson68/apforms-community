<?php
/**
 * Plugin Name: apForms
 * Plugin URI:  https://artistpro.media
 * Description: Build forms with notifications, confirmations, and entry management. Built by artistpro.
 * Version:     1.1.6
 * Requires at least: 6.4
 * Requires PHP: 8.0
 * Author:      artistpro Media
 * Author URI:  https://artistpro.media
 * License: GPL-2.0-or-later
 * Text Domain: artistpro-forms
  * License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Editions share PHP classes and database tables and must not run together.
$apforms_this_plugin = plugin_basename( __FILE__ );
$apforms_active_plugins = (array) get_option( 'active_plugins', [] );
if ( is_multisite() ) $apforms_active_plugins = array_merge( $apforms_active_plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
$apforms_other_edition = false;
foreach ( $apforms_active_plugins as $apforms_plugin ) {
    $apforms_plugin_directory = explode( '/', $apforms_plugin, 2 )[0];
    if ( $apforms_plugin !== $apforms_this_plugin && in_array( $apforms_plugin_directory, [ 'apforms', 'apforms-free', 'artistpro-forms' ], true ) ) $apforms_other_edition = true;
}
if ( $apforms_other_edition ) {
    register_activation_hook( __FILE__, static function() { wp_die( esc_html__( 'Deactivate the other apForms edition before activating this one. Your forms and entries remain in the database.', 'artistpro-forms' ) ); } );
    add_action( 'admin_notices', static function() { if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-warning"><p>' . esc_html__( 'This apForms edition is paused because another edition is active.', 'artistpro-forms' ) . '</p></div>'; } );
    return;
}

define( 'APFORMS_VERSION',   '1.1.6' );
define( 'APFORMS_FILE',      __FILE__ );
define( 'APFORMS_DIR',       plugin_dir_path( __FILE__ ) );
define( 'APFORMS_URL',       plugin_dir_url( __FILE__ ) );
define( 'APFORMS_UPLOAD_DIR', WP_CONTENT_DIR . '/apforms-uploads' );
define( 'APFORMS_UPLOAD_URL', WP_CONTENT_URL . '/apforms-uploads' );

require_once APFORMS_DIR . 'includes/class-apforms-db.php';
require_once APFORMS_DIR . 'includes/class-apforms-form.php';
require_once APFORMS_DIR . 'includes/class-apforms-entry.php';
require_once APFORMS_DIR . 'includes/class-apforms-notification.php';
require_once APFORMS_DIR . 'includes/class-apforms-fields.php';
require_once APFORMS_DIR . 'includes/class-apforms-renderer.php';
require_once APFORMS_DIR . 'includes/class-apforms-ajax.php';
require_once APFORMS_DIR . 'includes/class-apforms-export.php';

APForms_Ajax::init();
add_action( 'admin_post_apforms_download', [ 'APForms_Entry', 'download_private_attachment' ] );

// Older releases stored attachments under wp-content; keep administrators informed
// until each site's existing files can be moved and independently verified.
add_action( 'admin_notices', function() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    global $wpdb;
    $table = $wpdb->prefix . 'apf_entry_meta';
    $legacy = $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM %i WHERE value LIKE %s",
        $table,
        '%' . $wpdb->esc_like( '/apforms-uploads/' ) . '%'
    ) );
    if ( (int) $legacy > 0 ) {
        echo '<div class="notice notice-error"><p>' . esc_html__( 'apForms found attachments saved by an older version. These files may be publicly accessible. Contact artistpro support to move and protect them; updating alone does not protect existing files.', 'artistpro-forms' ) . '</p></div>';
    }
} );

// ── Activation / Deactivation ─────────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'APForms_DB', 'create_tables' ] );

// ── Admin Menu ────────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_menu_page(
        'apForms',
        'apForms',
        'manage_options',
        'apforms',
        'apforms_admin_forms_list',
        'dashicons-feedback',
        30
    );
    add_submenu_page( 'apforms', 'All Forms',    'All Forms',    'manage_options', 'apforms',              'apforms_admin_forms_list' );
    add_submenu_page( 'apforms', 'New Form',     'New Form',     'manage_options', 'apforms-new',         'apforms_admin_form_builder' );
    add_submenu_page( 'apforms', 'Form Builder', 'Form Builder', 'manage_options', 'apforms-builder',     'apforms_admin_form_builder' );
    add_submenu_page( 'apforms', 'Entries',      'Entries',      'manage_options', 'apforms-entries',     'apforms_admin_entries' );
} );

if ( ! function_exists( 'apforms_admin_forms_list' ) ) {
    function apforms_admin_forms_list()   { require APFORMS_DIR . 'admin/pages/forms-list.php'; }
    function apforms_admin_form_builder() { require APFORMS_DIR . 'admin/pages/form-builder.php'; }
    function apforms_admin_entries()      { require APFORMS_DIR . 'admin/pages/entries.php'; }
}

// ── Scripts & Styles ──────────────────────────────────────────────────────────
add_action( 'admin_enqueue_scripts', function( $hook ) {
    if ( strpos( $hook, 'apforms' ) === false ) return;
    wp_enqueue_style(  'apforms-admin',   APFORMS_URL . 'assets/css/apforms-admin.css',  [], APFORMS_VERSION );
    wp_enqueue_script( 'sortablejs',      APFORMS_URL . 'assets/vendor/sortablejs/Sortable.min.js', [], '1.15.2', true );
    wp_enqueue_script( 'apforms-builder', APFORMS_URL . 'assets/js/apforms-builder.js',  [ 'jquery', 'sortablejs' ], APFORMS_VERSION, true );
    wp_localize_script( 'apforms-builder', 'apformsAdmin', [
        'ajaxurl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'apforms_admin' ),
        'assetsUrl' => APFORMS_URL . 'assets/',
    ]);
} );

add_action( 'wp_enqueue_scripts', function() {
    global $post;
    if ( ! is_a( $post, 'WP_Post' ) || strpos( $post->post_content, '[apform' ) === false ) return;
    wp_enqueue_style(  'apforms-public',   APFORMS_URL . 'assets/css/apforms-public.css',  [], APFORMS_VERSION );
    wp_enqueue_script( 'apforms-public',   APFORMS_URL . 'assets/js/apforms-public.js',    [], APFORMS_VERSION, true );
    do_action( 'apforms_enqueue_public_extensions' );
    wp_localize_script( 'apforms-public', 'apformsPublic', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'apforms_submit' ),
    ]);
} );

// ── Shortcode ─────────────────────────────────────────────────────────────────
add_shortcode( 'apform', function( $atts ) {
    $atts = shortcode_atts( [ 'id' => 0, 'title' => 'true', 'description' => 'true' ], $atts );
    if ( ! $atts['id'] ) return '<p>apForms: No form ID specified.</p>';

    wp_enqueue_style(  'apforms-public',   APFORMS_URL . 'assets/css/apforms-public.css',  [], APFORMS_VERSION );
    wp_enqueue_script( 'apforms-public',   APFORMS_URL . 'assets/js/apforms-public.js',    [], APFORMS_VERSION, true );
    do_action( 'apforms_enqueue_public_extensions' );
    wp_localize_script( 'apforms-public', 'apformsPublic', [
        'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        'nonce'   => wp_create_nonce( 'apforms_submit' ),
    ]);

    return APForms_Renderer::render( (int) $atts['id'], [
        'show_title'       => $atts['title'] !== 'false',
        'show_description' => $atts['description'] !== 'false',
    ]);
} );

// ── Upload directory security ─────────────────────────────────────────────────
add_action( 'init', function() {
    if ( ! is_dir( APFORMS_UPLOAD_DIR ) ) {
        wp_mkdir_p( APFORMS_UPLOAD_DIR );
        file_put_contents( APFORMS_UPLOAD_DIR . '/.htaccess', "Options -Indexes\n" );
        file_put_contents( APFORMS_UPLOAD_DIR . '/index.php', '<?php // silence' );
    }
} );
