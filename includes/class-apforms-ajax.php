<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_Ajax {
    public static function init() {
        add_action( 'wp_ajax_apforms_submit', [ __CLASS__, 'submit' ] );
        add_action( 'wp_ajax_nopriv_apforms_submit', [ __CLASS__, 'submit' ] );

        add_action( 'wp_ajax_apforms_save_form', [ __CLASS__, 'save_form' ] );
        add_action( 'wp_ajax_apforms_get_form', [ __CLASS__, 'get_form' ] );
        add_action( 'wp_ajax_apforms_delete_form', [ __CLASS__, 'delete_form' ] );
        add_action( 'wp_ajax_apforms_duplicate_form', [ __CLASS__, 'duplicate_form' ] );

        add_action( 'wp_ajax_apforms_get_entries', [ __CLASS__, 'get_entries' ] );
        add_action( 'wp_ajax_apforms_delete_entry', [ __CLASS__, 'delete_entry' ] );
        add_action( 'wp_ajax_apforms_export_entries', [ __CLASS__, 'export_entries' ] );

        add_action( 'wp_ajax_apforms_save_notification', [ __CLASS__, 'save_notification' ] );
        add_action( 'wp_ajax_apforms_delete_notification', [ __CLASS__, 'delete_notification' ] );

        add_action( 'wp_ajax_apforms_portal_save_form', [ __CLASS__, 'portal_save_form' ] );
        add_action( 'wp_ajax_apforms_portal_get_forms', [ __CLASS__, 'portal_get_forms' ] );
        add_action( 'wp_ajax_apforms_portal_get_entries', [ __CLASS__, 'portal_get_entries' ] );
    }

    public static function submit() {
        $form_id = (int) ( $_POST['apf_form_id'] ?? 0 );
        if ( ! $form_id ) {
            wp_send_json_error( [ 'message' => 'Invalid form.' ] );
        }

        if ( ! check_ajax_referer( 'apforms_submit_' . $form_id, 'apf_nonce', false ) ) {
            wp_send_json_error( [ 'message' => 'Security check failed. Please refresh and try again.' ] );
        }

        if ( ! empty( $_POST['apf_hp'] ) ) {
            wp_send_json_success( [ 'message' => 'Thank you!' ] );
        }

        $form = APForms_Form::get_form( $form_id );
        if ( ! $form || $form->status !== 'active' ) {
            wp_send_json_error( [ 'message' => 'Form not found or inactive.' ] );
        }

        foreach ( $form->fields as $field ) {
            if ( ! empty( $field['conditions']['rules'] ) && ! has_filter( 'apforms_field_visibility' ) ) {
                wp_send_json_error( [ 'message' => 'This form requires apForms Pro. Please contact the site administrator.' ] );
            }
            if ( ( $field['type'] ?? '' ) === 'file' && ! has_filter( 'apforms_validate_upload' ) ) {
                wp_send_json_error( [ 'message' => 'This form requires apForms Pro. Please contact the site administrator.' ] );
            }
        }

        $posted = is_array( $_POST['apf_fields'] ?? null ) ? wp_unslash( $_POST['apf_fields'] ) : [];
        $errors = [];
        $values = [];
        $labels = [];
        $raw_files = $_FILES['apf_files'] ?? [];
        $files = [];

        foreach ( $form->fields as $field ) {
            $fid   = $field['id'];
            $ftype = $field['type'];

            if ( ! apply_filters( 'apforms_field_visibility', true, $field, $posted ) ) {
                continue;
            }

            if ( in_array( $ftype, [ 'section', 'html' ], true ) ) {
                continue;
            }

            if ( $ftype === 'file' ) {
                $result = apply_filters( 'apforms_validate_upload', null, $field, $raw_files );
                if ( ! is_array( $result ) ) {
                    wp_send_json_error( [ 'message' => 'This form requires the apForms Pro file upload add-on. Please contact the site administrator.' ] );
                }
                $labels[ $fid ] = $field['label'];
                if ( ! empty( $result['error'] ) ) $errors[ $fid ] = $result['error'];
                if ( ! empty( $result['files'] ) ) $files[ $fid ] = $result['files'];
                continue;
            }

            if ( $ftype === 'name' ) {
                $first  = sanitize_text_field( $posted[ $fid . '_first' ] ?? '' );
                $middle = sanitize_text_field( $posted[ $fid . '_middle' ] ?? '' );
                $last   = sanitize_text_field( $posted[ $fid . '_last' ] ?? '' );
                $full   = trim( "{$first} {$middle} {$last}" );

                $values[ $fid ] = $full;
                $labels[ $fid ] = $field['label'];

                if ( ! empty( $field['required'] ) && empty( trim( "{$first} {$last}" ) ) ) {
                    $errors[ $fid ] = $field['label'] . ' is required.';
                }
                continue;
            }

            if ( $ftype === 'checkbox' ) {
                $val = array_map( 'sanitize_text_field', (array) ( $posted[ $fid ] ?? [] ) );
            } else {
                $val = sanitize_textarea_field( $posted[ $fid ] ?? '' );
            }

            $err = APForms_Fields::validate( $field, $val );
            if ( $err ) {
                $errors[ $fid ] = $err;
            }

            $values[ $fid ] = $val;
            $labels[ $fid ] = $field['label'];
        }

        if ( ! empty( $errors ) ) {
            wp_send_json_error( [ 'errors' => $errors, 'message' => 'Please correct the errors below.' ] );
        }

        $values['__labels'] = $labels;

        $magic_token = sanitize_text_field( $_POST['apf_magic_token'] ?? '' );
        $current_user_id = get_current_user_id();
        $ip_address = self::get_ip_address();

        $dedupe_args = [];
        if ( $magic_token !== '' ) {
            $dedupe_args['magic_token'] = $magic_token;
        } elseif ( $current_user_id ) {
            $dedupe_args['created_by'] = $current_user_id;
        } else {
            $dedupe_args['ip'] = $ip_address;
        }

        if ( ! empty( $form->settings['limit_one_submission'] ) && APForms_Entry::has_prior_submission( $form_id, $dedupe_args ) ) {
            wp_send_json_error( [ 'message' => 'We already have a submission recorded for this access link or device.' ] );
        }

        $save_args = [
            'magic_token' => $magic_token !== '' ? $magic_token : null,
            'ip'          => $ip_address,
            'user_agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
            'source_url'  => esc_url_raw( $_SERVER['HTTP_REFERER'] ?? '' ),
            'created_by'  => $current_user_id ?: null,
        ];

        $entry_id = APForms_Entry::save( $form_id, $values, $files, $save_args );
        if ( ! $entry_id ) {
            wp_send_json_error( [ 'message' => 'Could not save submission. Please try again.' ] );
        }

        APForms_Notification::send_all( $form_id, $entry_id );

        // A distributable form must never contain site-specific behavior. Redirects
        // are opt-in per form and restricted to a WordPress-approved destination.
        $redirect_url = wp_validate_redirect(
            esc_url_raw( $form->settings['confirmation_redirect_url'] ?? '' ),
            ''
        );

        wp_send_json_success( [
            'message'  => 'Submission Successful!',
            'redirect' => $redirect_url,
            'entry_id' => $entry_id,
        ] );
    }

    public static function save_form() {
        self::require_admin();
        self::require_admin_nonce();
        $data = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! $data ) {
            wp_send_json_error( 'No data' );
        }
        $id = APForms_Form::save_form( $data );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function get_form() {
        self::require_admin();
        self::require_admin_nonce();
        $id   = self::request_nonnegative_int( $_GET, 'id', 0 );
        $form = APForms_Form::get_form( $id );
        if ( ! $form ) {
            wp_send_json_error( 'Not found' );
        }
        $form->notifications = APForms_Form::get_notifications( $id );
        wp_send_json_success( $form );
    }

    public static function delete_form() {
        self::require_admin();
        self::require_admin_nonce();
        $id = self::request_nonnegative_int( $_POST, 'id', 0 );
        APForms_Form::delete_form( $id );
        wp_send_json_success();
    }

    public static function duplicate_form() {
        self::require_admin();
        self::require_admin_nonce();
        $id = self::request_nonnegative_int( $_POST, 'id', 0 );
        $new_id = APForms_Form::duplicate_form( $id );
        wp_send_json_success( [ 'id' => $new_id ] );
    }

    public static function get_entries() {
        self::require_admin();
        self::require_admin_nonce();
        $form_id  = self::request_nonnegative_int( $_GET, 'form_id', 0 );
        $per_page = min( 500, max( 1, self::request_nonnegative_int( $_GET, 'per_page', 25 ) ) );
        $page     = max( 1, self::request_nonnegative_int( $_GET, 'page', 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $result   = APForms_Entry::get_entries( $form_id, compact( 'per_page', 'offset' ) );
        wp_send_json_success( $result );
    }

    public static function delete_entry() {
        self::require_admin();
        self::require_admin_nonce();
        $id = self::request_nonnegative_int( $_POST, 'id', 0 );
        APForms_Entry::delete_entry( $id );
        wp_send_json_success();
    }

    public static function export_entries() {
        self::require_admin();
        self::require_admin_nonce();
        $form_id = self::request_nonnegative_int( $_GET, 'form_id', 0 );
        APForms_Entry::export_csv( $form_id );
    }

    public static function save_notification() {
        self::require_admin();
        self::require_admin_nonce();
        $data = json_decode( file_get_contents( 'php://input' ), true );
        $id   = APForms_Form::save_notification( $data );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function delete_notification() {
        self::require_admin();
        self::require_admin_nonce();
        $id = self::request_nonnegative_int( $_POST, 'id', 0 );
        global $wpdb;
        $wpdb->delete( $wpdb->prefix . 'apf_notifications', [ 'id' => $id ] );
        wp_send_json_success();
    }

    public static function portal_get_forms() {
        self::require_admin();
        self::require_admin_nonce();
        $forms = APForms_Form::get_forms();
        foreach ( $forms as $f ) {
            $f->entry_count = APForms_Form::get_entry_count( $f->id );
        }
        wp_send_json_success( $forms );
    }

    public static function portal_save_form() {
        self::require_admin();
        self::require_admin_nonce();
        $data = json_decode( file_get_contents( 'php://input' ), true );
        if ( ! $data ) {
            wp_send_json_error( 'No data' );
        }
        $id = APForms_Form::save_form( $data );
        wp_send_json_success( [ 'id' => $id ] );
    }

    public static function portal_get_entries() {
        self::require_admin();
        self::require_admin_nonce();
        $form_id  = self::request_nonnegative_int( $_GET, 'form_id', 0 );
        $per_page = min( 500, max( 1, self::request_nonnegative_int( $_GET, 'per_page', 50 ) ) );
        $page     = max( 1, self::request_nonnegative_int( $_GET, 'page', 1 ) );
        $offset   = ( $page - 1 ) * $per_page;
        $result   = APForms_Entry::get_entries( $form_id, compact( 'per_page', 'offset' ) );
        wp_send_json_success( $result );
    }

    private static function request_nonnegative_int( $source, $key, $default ) {
        if ( ! isset( $source[ $key ] ) || ! is_scalar( $source[ $key ] ) ) return $default;
        return absint( wp_unslash( (string) $source[ $key ] ) );
    }

    private static function require_admin() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Access denied' );
        }
    }

    private static function require_admin_nonce() {
        if ( ! check_ajax_referer( 'apforms_admin', 'nonce', false ) ) {
            wp_send_json_error( 'Security check failed.' );
        }
    }

    private static function get_ip_address() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( trim( explode( ',', (string) $_SERVER[ $key ] )[0] ) );
            }
        }
        return '0.0.0.0';
    }
}
