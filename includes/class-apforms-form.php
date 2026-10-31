<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_Form {

    public static function get_forms( $args = [] ) {
        global $wpdb;
        $t = $wpdb->prefix . 'apf_forms';
        $status = $args['status'] ?? 'active';
        if ( $status === 'any' ) {
            $forms = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, description, fields_json, status, created_at FROM %i ORDER BY id DESC", $t ) );
        } else {
            $forms = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, title, description, fields_json, status, created_at FROM %i WHERE status = %s ORDER BY id DESC", $t, $status
            ) );
        }

        foreach ( $forms as $form ) {
            $form->fields = json_decode( $form->fields_json, true ) ?: [];
        }
        return $forms;
    }

    public static function get_form( $id ) {
        global $wpdb;
        $t   = $wpdb->prefix . 'apf_forms';
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d", $t, $id ) );
        if ( ! $row ) return null;
        $row->fields   = json_decode( $row->fields_json, true ) ?: [];
        $row->settings = json_decode( $row->settings,    true ) ?: [];
        return $row;
    }

    public static function save_form( $data ) {
        global $wpdb;
        $t = $wpdb->prefix . 'apf_forms';

        $fields   = $data['fields']   ?? [];
        $settings = $data['settings'] ?? [];
        // Preserve add-on settings when the add-on is inactive and its controls are absent.
        if ( ! array_key_exists( 'ai', $settings ) && ! empty( $data['id'] ) ) {
            $existing = self::get_form( (int) $data['id'] );
            if ( $existing && isset( $existing->settings['ai'] ) ) $settings['ai'] = $existing->settings['ai'];
        }

        // Ensure every field has an id
        foreach ( $fields as &$field ) {
            if ( empty( $field['id'] ) ) {
                $field['id'] = 'field_' . uniqid();
            }
        }
        unset( $field );

        $payload = [
            'title'       => sanitize_text_field( $data['title'] ?? 'Untitled Form' ),
            'description' => wp_kses_post( $data['description'] ?? '' ),
            'fields_json' => wp_json_encode( $fields ),
            'settings'    => wp_json_encode( $settings ),
            'status'      => in_array( $data['status'] ?? 'active', [ 'active', 'inactive', 'trash' ] ) ? ($data['status'] ?? 'active') : 'active',
        ];

        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $t, $payload, [ 'id' => (int) $data['id'] ] );
            $id = (int) $data['id'];
        } else {
            $payload['created_by'] = get_current_user_id();
            $wpdb->insert( $t, $payload );
            $id = $wpdb->insert_id;

            // Create default admin notification
            self::create_default_notification( $id, $payload['title'] );
        }

        return $id;
    }

    private static function create_default_notification( $form_id, $form_title ) {
        global $wpdb;
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_option( 'blogname' );

        $wpdb->insert( $wpdb->prefix . 'apf_notifications', [
            'form_id'    => $form_id,
            'name'       => 'Admin Notification',
            'to_type'    => 'admin',
            'to_email'   => $admin_email,
            'from_name'  => $site_name,
            'from_email' => $admin_email,
            'subject'    => "New submission: $form_title",
            'body'       => "{all_fields}",
            'is_active'  => 1,
        ]);
    }

    public static function delete_form( $id ) {
        global $wpdb;
        $id = (int) $id;
        $wpdb->delete( $wpdb->prefix . 'apf_forms',         [ 'id' => $id ] );
        $wpdb->delete( $wpdb->prefix . 'apf_notifications', [ 'form_id' => $id ] );

        // Delete all entries and their meta
        $entries = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}apf_entries WHERE form_id = %d", $id
        ) );
        foreach ( $entries as $eid ) {
            $wpdb->delete( $wpdb->prefix . 'apf_entry_meta', [ 'entry_id' => (int) $eid ] );
        }
        $wpdb->delete( $wpdb->prefix . 'apf_entries', [ 'form_id' => $id ] );

        return true;
    }

    public static function get_entry_count( $form_id ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}apf_entries WHERE form_id = %d AND status = 'active'", $form_id
        ) );
    }

    public static function duplicate_form( $id ) {
        $form = self::get_form( $id );
        if ( ! $form ) return false;
        return self::save_form([
            'title'       => $form->title . ' (Copy)',
            'description' => $form->description,
            'fields'      => $form->fields,
            'settings'    => $form->settings,
        ]);
    }

    // Get notifications for a form
    public static function get_notifications( $form_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}apf_notifications WHERE form_id = %d ORDER BY id", $form_id
        ) );
    }

    public static function save_notification( $data ) {
        global $wpdb;
        $t = $wpdb->prefix . 'apf_notifications';
        $payload = [
            'form_id'    => (int) $data['form_id'],
            'name'       => sanitize_text_field( $data['name'] ?? 'Notification' ),
            'to_type'    => in_array( $data['to_type'] ?? 'admin', ['admin','field','custom'] ) ? $data['to_type'] : 'admin',
            'to_email'   => sanitize_text_field( $data['to_email'] ?? '' ),
            'to_field'   => sanitize_text_field( $data['to_field'] ?? '' ),
            'from_name'  => sanitize_text_field( $data['from_name'] ?? '' ),
            'from_email' => sanitize_email( $data['from_email'] ?? '' ),
            'reply_to'   => sanitize_text_field( $data['reply_to'] ?? '' ),
            'subject'    => sanitize_text_field( $data['subject'] ?? '' ),
            'body'       => wp_kses_post( $data['body'] ?? '' ),
            'is_active'  => (int) ( $data['is_active'] ?? 1 ),
        ];
        if ( ! empty( $data['id'] ) ) {
            $wpdb->update( $t, $payload, [ 'id' => (int) $data['id'] ] );
            return (int) $data['id'];
        }
        $wpdb->insert( $t, $payload );
        return $wpdb->insert_id;
    }
}
