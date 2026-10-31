<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_Entry {

    public static function save( $form_id, $values, $files = [], $args = [] ) {
        global $wpdb;

        $magic_token = isset( $args['magic_token'] ) ? sanitize_text_field( (string) $args['magic_token'] ) : null;
        $ip_address  = isset( $args['ip'] ) ? sanitize_text_field( (string) $args['ip'] ) : self::get_ip();
        $user_agent  = isset( $args['user_agent'] ) ? sanitize_text_field( (string) $args['user_agent'] ) : sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
        $source_url  = isset( $args['source_url'] ) ? esc_url_raw( (string) $args['source_url'] ) : esc_url_raw( $_SERVER['HTTP_REFERER'] ?? '' );
        $created_by  = array_key_exists( 'created_by', $args ) ? ( $args['created_by'] !== null ? (int) $args['created_by'] : null ) : ( get_current_user_id() ?: null );

        $insert_data = [
            'form_id'    => (int) $form_id,
            'status'     => 'active',
            'ip'         => $ip_address,
            'user_agent' => $user_agent,
            'source_url' => $source_url,
            'created_by' => $created_by,
        ];

        $insert_format = [ '%d', '%s', '%s', '%s', '%s', '%d' ];

        if ( self::has_magic_token_column() ) {
            $insert_data['magic_token'] = $magic_token ?: null;
            $insert_format[] = '%s';
        }

        $ok = $wpdb->insert( $wpdb->prefix . 'apf_entries', $insert_data, $insert_format );
        if ( ! $ok ) {
            return false;
        }

        $entry_id = (int) $wpdb->insert_id;
        if ( ! $entry_id ) {
            return false;
        }

        $labels = isset( $values['__labels'] ) && is_array( $values['__labels'] ) ? $values['__labels'] : [];

        foreach ( $values as $field_id => $value ) {
            if ( $field_id === '__labels' ) {
                continue;
            }

            $label = $labels[ $field_id ] ?? $field_id;

            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'sanitize_text_field', $value ) );
            }

            $saved = $wpdb->insert(
                $wpdb->prefix . 'apf_entry_meta',
                [
                    'entry_id'    => $entry_id,
                    'form_id'     => (int) $form_id,
                    'field_id'    => sanitize_text_field( (string) $field_id ),
                    'field_label' => sanitize_text_field( (string) $label ),
                    'value'       => sanitize_textarea_field( (string) $value ),
                ],
                [ '%d', '%d', '%s', '%s', '%s' ]
            );
            if ( ! $saved ) {
                $wpdb->update( $wpdb->prefix . 'apf_entries', [ 'status' => 'failed' ], [ 'id' => $entry_id ] );
                return false;
            }
        }

        $stored_uploads = [];
        foreach ( $files as $field_id => $file_list ) {
            $urls = [];
            foreach ( $file_list as $file_info ) {
                $url = self::handle_upload( $file_info, $form_id, $entry_id );
                if ( ! $url ) {
                    // Remove only files created for this incomplete submission.
                    self::cleanup_failed_uploads( $stored_uploads, $form_id, $entry_id );
                    // Do not report success or fire submission hooks for an incomplete upload.
                    $wpdb->update( $wpdb->prefix . 'apf_entries', [ 'status' => 'failed' ], [ 'id' => $entry_id ] );
                    return false;
                }
                $urls[] = $url;
                $stored_uploads[] = $url;
            }
            $label = $labels[ $field_id ] ?? $field_id;
            $saved = $wpdb->insert(
                $wpdb->prefix . 'apf_entry_meta',
                [
                    'entry_id' => $entry_id,
                    'form_id' => (int) $form_id,
                    'field_id' => sanitize_text_field( (string) $field_id ),
                    'field_label' => sanitize_text_field( (string) $label ),
                    'value' => sanitize_textarea_field( implode( ', ', $urls ) ),
                ],
                [ '%d', '%d', '%s', '%s', '%s' ]
            );
            if ( ! $saved ) {
                self::cleanup_failed_uploads( $stored_uploads, $form_id, $entry_id );
                $wpdb->update( $wpdb->prefix . 'apf_entries', [ 'status' => 'failed' ], [ 'id' => $entry_id ] );
                return false;
            }
        }

        /**
         * Fires only after the entry and every text/file value are durable.
         * Consumers receive the original values; AP Forms remains their owner.
         */
        $submitted_values = [];
        foreach ( self::get_entry_values( $entry_id ) as $field_id => $item ) {
            $submitted_values[ $field_id ] = $item['value'] ?? '';
        }
        do_action( 'apforms_submission_created', $entry_id, (int) $form_id, 0, [ 'values' => $submitted_values ], [ 'success' => true, 'submission_id' => $entry_id ] );

        return $entry_id;
    }

    public static function has_prior_submission( $form_id, $args = [] ) {
        global $wpdb;

        $table = $wpdb->prefix . 'apf_entries';

        if ( ! empty( $args['magic_token'] ) && self::has_magic_token_column() ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE form_id = %d AND status = 'active' AND magic_token = %s",
                    $table,
                    (int) $form_id,
                    sanitize_text_field( (string) $args['magic_token'] )
                )
            );
            return $count > 0;
        }

        if ( ! empty( $args['created_by'] ) ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE form_id = %d AND status = 'active' AND created_by = %d",
                    $table,
                    (int) $form_id,
                    (int) $args['created_by']
                )
            );
            return $count > 0;
        }

        if ( ! empty( $args['ip'] ) ) {
            $count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM %i WHERE form_id = %d AND status = 'active' AND ip = %s",
                    $table,
                    (int) $form_id,
                    sanitize_text_field( (string) $args['ip'] )
                )
            );
            return $count > 0;
        }

        return false;
    }

    public static function find_entry_id_by_magic_token( $form_id, $magic_token ) {
        global $wpdb;

        if ( ! self::has_magic_token_column() || empty( $magic_token ) ) {
            return 0;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}apf_entries WHERE form_id = %d AND status = 'active' AND magic_token = %s ORDER BY id DESC LIMIT 1",
                (int) $form_id,
                sanitize_text_field( (string) $magic_token )
            )
        );
    }

    public static function get_entries( $form_id, $args = [] ) {
        global $wpdb;

        $t      = $wpdb->prefix . 'apf_entries';
        $limit  = (int) ( $args['per_page'] ?? 50 );
        $offset = (int) ( $args['offset'] ?? 0 );
        $status = $args['status'] ?? 'active';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM %i e WHERE e.form_id = %d AND e.status = %s",
            $t, (int) $form_id, sanitize_text_field( (string) $status )
        ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.* FROM %i e WHERE e.form_id = %d AND e.status = %s ORDER BY e.created_at DESC, e.id DESC LIMIT %d OFFSET %d",
            $t, (int) $form_id, sanitize_text_field( (string) $status ), max( 0, $limit ), max( 0, $offset )
        ) );

        foreach ( $rows as $row ) {
            $row->values = self::get_entry_values( $row->id );
        }

        return compact( 'rows', 'total' );
    }

    public static function get_entry( $entry_id ) {
        global $wpdb;

        $entry = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}apf_entries WHERE id = %d",
                (int) $entry_id
            )
        );

        if ( ! $entry ) {
            return null;
        }

        $entry->values = self::get_entry_values( $entry_id );
        return $entry;
    }

    public static function get_entry_values( $entry_id ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT field_id, field_label, value FROM {$wpdb->prefix}apf_entry_meta WHERE entry_id = %d ORDER BY id",
                (int) $entry_id
            )
        );

        $values = [];
        foreach ( $rows as $row ) {
            $value = $row->value;
            if ( strpos( $value, 'apforms-private:' ) === 0 ) {
                $parts = array_map( 'trim', explode( ',', $value ) );
                $links = [];
                foreach ( $parts as $index => $part ) {
                    if ( strpos( $part, 'apforms-private:' ) !== 0 ) continue;
                    if ( current_user_can( 'manage_options' ) ) {
                        $field = sanitize_text_field( $row->field_id );
                        $url = add_query_arg( [ 'action' => 'apforms_download', 'entry_id' => (int) $entry_id, 'field' => $field, 'index' => $index ], admin_url( 'admin-post.php' ) );
                        $links[] = html_entity_decode( wp_nonce_url( $url, 'apforms_download_' . (int) $entry_id . '_' . $field . '_' . $index ), ENT_QUOTES, 'UTF-8' );
                    } else {
                        $links[] = '[Private attachment]';
                    }
                }
                $value = implode( ', ', $links );
            }
            $values[ $row->field_id ] = [
                'label' => $row->field_label,
                'value' => $value,
            ];
        }

        return $values;
    }

    public static function delete_entry( $entry_id ) {
        global $wpdb;

        $wpdb->delete( $wpdb->prefix . 'apf_entry_meta', [ 'entry_id' => (int) $entry_id ], [ '%d' ] );
        $wpdb->delete( $wpdb->prefix . 'apf_entries', [ 'id' => (int) $entry_id ], [ '%d' ] );

        return true;
    }

    public static function trash_entry( $entry_id ) {
        global $wpdb;

        return (bool) $wpdb->update(
            $wpdb->prefix . 'apf_entries',
            [ 'status' => 'trash' ],
            [ 'id' => (int) $entry_id ],
            [ '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Prevent spreadsheet applications from evaluating exported user data.
     */
    private static function csv_safe_cell( $value ) {
        if ( is_array( $value ) ) $value = implode( ', ', array_map( 'strval', $value ) );
        $value = is_scalar( $value ) ? (string) $value : '';
        return preg_match( '/^[\x00-\x20]*[=+@\-]/', $value ) || preg_match( '/^[\t\r\n]/', $value ) ? "'" . $value : $value;
    }

    public static function export_csv( $form_id ) {
        $form = APForms_Form::get_form( $form_id );
        if ( ! $form ) {
            wp_die( 'Form not found' );
        }

        $entries = self::get_entries( $form_id, [ 'per_page' => 9999 ] );
        $rows    = $entries['rows'];

        $cols = [ 'Entry ID', 'Date Submitted', 'IP Address', 'Source URL' ];
        if ( self::has_magic_token_column() ) {
            $cols[] = 'Magic Token';
        }

        foreach ( $form->fields as $f ) {
            if ( in_array( $f['type'], [ 'section', 'html' ], true ) ) {
                continue;
            }
            $cols[] = $f['label'] ?? $f['id'];
        }

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $form->title ) . '-entries-' . gmdate( 'Y-m-d' ) . '.csv"' );

        $fh = fopen( 'php://output', 'w' );
        fputcsv( $fh, array_map( [ __CLASS__, 'csv_safe_cell' ], $cols ) );

        foreach ( $rows as $entry ) {
            $row_data = [ $entry->id, $entry->created_at, $entry->ip, $entry->source_url ];

            if ( self::has_magic_token_column() ) {
                $row_data[] = $entry->magic_token ?? '';
            }

            foreach ( $form->fields as $f ) {
                if ( in_array( $f['type'], [ 'section', 'html' ], true ) ) {
                    continue;
                }
                $row_data[] = $entry->values[ $f['id'] ]['value'] ?? '';
            }

            fputcsv( $fh, array_map( [ __CLASS__, 'csv_safe_cell' ], $row_data ) );
        }

        // PHP closes the output stream at the end of this response.
        exit;
    }

    /**
     * Private files are local to this WordPress installation; remote transports
     * cannot safely enforce the same outside-document-root checks.
     */
    private static function private_filesystem() {
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
        return new WP_Filesystem_Direct( null );
    }

    /** Remove files produced by this failed entry only; never traverse symlinks. */
    private static function cleanup_failed_uploads( $markers, $form_id, $entry_id ) {
        if ( ! $markers ) return;
        $root = self::private_upload_root();
        if ( ! $root ) return;
        $prefix = 'apforms-private:' . (int) $form_id . '/' . (int) $entry_id . '/';
        $expected_dir = $root . '/' . (int) $form_id . '/' . (int) $entry_id;
        if ( realpath( $expected_dir ) !== $expected_dir ) return;
        $filesystem = self::private_filesystem();
        foreach ( $markers as $marker ) {
            if ( strpos( $marker, $prefix ) !== 0 ) continue;
            $name = substr( $marker, strlen( $prefix ) );
            if ( ! preg_match( '/^[a-f0-9-]+\.[a-z0-9]+$/', $name ) ) continue;
            $path = $expected_dir . '/' . $name;
            if ( is_link( $path ) || ! is_file( $path ) || realpath( $path ) !== $path ) continue;
            $filesystem->delete( $path, false, 'f' );
        }
    }

    private static function handle_upload( $file, $form_id, $entry_id ) {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return false;
        }
        $private_root = self::private_upload_root();
        if ( ! $private_root ) return false;
        $upload_dir = $private_root . "/{$form_id}/{$entry_id}/";
        if ( ! wp_mkdir_p( $upload_dir ) ) return false;
        $real_dir = realpath( $upload_dir );
        if ( ! $real_dir || strpos( $real_dir . '/', $private_root . '/' ) !== 0 ) return false;

        $allowed = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'mp3', 'mp4', 'zip' ];
        $ext     = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );

        if ( ! in_array( $ext, $allowed, true ) ) {
            return false;
        }

        $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        if ( empty( $filetype['ext'] ) || strtolower( $filetype['ext'] ) !== $ext ) {
            return false;
        }

        if ( (int) $file['size'] > 10 * 1024 * 1024 ) {
            return false;
        }

        $filename = wp_generate_uuid4() . '.' . $ext;
        $dest     = $upload_dir . $filename;

        if ( self::private_filesystem()->move( $file['tmp_name'], $dest, false ) ) {
            return 'apforms-private:' . (int) $form_id . '/' . (int) $entry_id . '/' . $filename;
        }

        return false;
    }

    // Attachments must be stored outside the web server document root.
    private static function private_upload_root() {
        $parent = realpath( dirname( untrailingslashit( ABSPATH ) ) );
        $docroot = realpath( $_SERVER['DOCUMENT_ROOT'] ?? untrailingslashit( ABSPATH ) );
        if ( ! $parent || ! $docroot || $parent === $docroot || strpos( $parent . '/', $docroot . '/' ) === 0 ) return false;
        $dir = $parent . '/.apforms-private-' . substr( hash( 'sha256', ABSPATH ), 0, 16 );
        $created = ! is_dir( $dir );
        if ( ! wp_mkdir_p( $dir ) ) return false;
        if ( $created && ! self::private_filesystem()->chmod( $dir, 0700 ) ) return false;
        clearstatcache( true, $dir );
        if ( ( fileperms( $dir ) & 0077 ) !== 0 ) return false;
        $actual = realpath( $dir );
        if ( ! $actual || strpos( $actual . '/', $parent . '/' ) !== 0 || strpos( $actual . '/', $docroot . '/' ) === 0 ) return false;
        return $actual;
    }

    public static function download_private_attachment() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Access denied.', '', [ 'response' => 403 ] );
        $entry_id = absint( $_GET['entry_id'] ?? 0 );
        $field = sanitize_text_field( wp_unslash( $_GET['field'] ?? '' ) );
        $index = absint( $_GET['index'] ?? 0 );
        check_admin_referer( 'apforms_download_' . $entry_id . '_' . $field . '_' . $index );
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT m.value, e.form_id FROM %i m INNER JOIN %i e ON e.id = m.entry_id WHERE m.entry_id = %d AND m.field_id = %s AND e.status = %s",
            $wpdb->prefix . 'apf_entry_meta', $wpdb->prefix . 'apf_entries', $entry_id, $field, 'active'
        ) );
        if ( ! $row ) wp_die( 'Attachment not found.', '', [ 'response' => 404 ] );
        $items = array_map( 'trim', explode( ',', $row->value ) );
        $marker = $items[ $index ] ?? '';
        $prefix = 'apforms-private:' . (int) $row->form_id . '/' . $entry_id . '/';
        if ( strpos( $marker, $prefix ) !== 0 ) wp_die( 'Attachment not found.', '', [ 'response' => 404 ] );
        $name = substr( $marker, strlen( $prefix ) );
        if ( ! preg_match( '/^[a-f0-9-]+\.[a-z0-9]+$/', $name ) ) wp_die( 'Attachment not found.', '', [ 'response' => 404 ] );
        $root = self::private_upload_root();
        $file = $root ? realpath( $root . '/' . (int) $row->form_id . '/' . $entry_id . '/' . $name ) : false;
        if ( ! $file || strpos( $file, $root . '/' ) !== 0 || ! is_file( $file ) ) wp_die( 'Attachment not found.', '', [ 'response' => 404 ] );
        $contents = self::private_filesystem()->get_contents( $file );
        if ( $contents === false ) wp_die( 'Attachment could not be read.', '', [ 'response' => 500 ] );
        nocache_headers();
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        // The authenticated attachment is binary and must be delivered byte-for-byte.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo $contents;
        exit;
    }

    private static function get_ip() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( trim( explode( ',', (string) $_SERVER[ $key ] )[0] ) );
            }
        }

        return '0.0.0.0';
    }

    private static function has_magic_token_column() {
        static $has_column = null;

        if ( $has_column !== null ) {
            return $has_column;
        }

        global $wpdb;

        $column = $wpdb->get_var(
            $wpdb->prepare(
                "SHOW COLUMNS FROM {$wpdb->prefix}apf_entries LIKE %s",
                'magic_token'
            )
        );

        $has_column = ! empty( $column );
        return $has_column;
    }
}
