<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Export & Import utilities including Gravity Forms migration
 */
class APForms_Export {

    // Handle admin export request
    public static function maybe_export() {
        if ( ! isset( $_GET['apforms_export'] ) ) return;
        if ( ! current_user_can('manage_options') ) wp_die('Access denied');
        $form_id = isset( $_GET['form_id'] ) && is_scalar( $_GET['form_id'] ) ? absint( wp_unslash( $_GET['form_id'] ) ) : 0;
        if ( ! $form_id ) wp_die( 'Invalid form.', '', [ 'response' => 400 ] );
        check_admin_referer( 'apforms_export_' . $form_id );
        APForms_Entry::export_csv( $form_id );
    }

    /**
     * Migrate a Gravity Forms form + its entries into apForms.
     * $gf_db_prefix  — table prefix of the source GF install (e.g. 'wp_')
     * $gf_form_id    — the GF form ID to migrate
     */
    public static function migrate_from_gravity_forms( $gf_db_prefix, $gf_form_id ) {
        global $wpdb;

        // 1. Get GF form meta (fields defined as JSON)
        $meta = $wpdb->get_row( $wpdb->prepare(
            "SELECT display_meta, notifications FROM %i WHERE form_id = %d", $gf_db_prefix . 'gf_form_meta', $gf_form_id
        ) );
        if ( ! $meta ) return new WP_Error( 'not_found', "GF form $gf_form_id not found." );

        $gf_data = json_decode( $meta->display_meta, true );
        if ( ! $gf_data ) return new WP_Error( 'parse_error', 'Could not parse GF form JSON.' );

        // 2. Map GF fields → apForms fields
        $apf_fields = [];
        foreach ( $gf_data['fields'] ?? [] as $gf_field ) {
            $apf = self::map_gf_field( $gf_field );
            if ( $apf ) $apf_fields[] = $apf;
        }

        // 3. Map GF form settings → apForms settings
        $settings = [
            'submit_label'         => $gf_data['button']['text'] ?? 'Submit',
            'confirmation_message' => '',
        ];

        // 4. Save the form
        $form_id = APForms_Form::save_form([
            'title'       => $gf_data['title'],
            'description' => $gf_data['description'] ?? '',
            'fields'      => $apf_fields,
            'settings'    => $settings,
        ]);

        // 5. Migrate entries
        $gf_entries = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE form_id = %d AND status = 'active'", $gf_db_prefix . 'gf_entry', $gf_form_id
        ) );

        $entry_count = 0;
        foreach ( $gf_entries as $gf_entry ) {
            // Get entry field values from gf_entry_meta
            $gf_meta = $wpdb->get_results( $wpdb->prepare(
                "SELECT meta_key, meta_value FROM %i WHERE entry_id = %d", $gf_db_prefix . 'gf_entry_meta', $gf_entry->id
            ) );

            $values = [];
            $labels = [];

            // Build a key→value map for this entry
            $raw = [];
            foreach ( $gf_meta as $m ) {
                $raw[ $m->meta_key ] = $m->meta_value;
            }

            // Map GF field values to apForms field IDs
            foreach ( $apf_fields as $apf_field ) {
                $gf_id  = $apf_field['_gf_id'] ?? null;
                if ( ! $gf_id ) continue;
                $fid    = $apf_field['id'];
                $val    = $raw[ $gf_id ] ?? $raw[ (string)(int)$gf_id ] ?? '';
                $values[$fid] = $val;
                $labels[$fid] = $apf_field['label'];
            }

            $values['__labels'] = $labels;

            // Insert entry directly (bypass file handling for migration)
            $wpdb->insert( $wpdb->prefix . 'apf_entries', [
                'form_id'    => $form_id,
                'status'     => 'active',
                'ip'         => $gf_entry->ip ?? '',
                'source_url' => $gf_entry->source_url ?? '',
                'created_at' => $gf_entry->date_created,
                'updated_at' => $gf_entry->date_updated ?? $gf_entry->date_created,
            ]);
            $entry_id = $wpdb->insert_id;

            foreach ( $values as $field_id => $value ) {
                if ( $field_id === '__labels' ) continue;
                $label = $labels[$field_id] ?? $field_id;
                if ( is_array($value) ) $value = implode(', ', $value);
                $wpdb->insert( $wpdb->prefix . 'apf_entry_meta', [
                    'entry_id'    => $entry_id,
                    'form_id'     => $form_id,
                    'field_id'    => $field_id,
                    'field_label' => $label,
                    'value'       => $value,
                ]);
            }
            $entry_count++;
        }

        return [
            'form_id'     => $form_id,
            'fields'      => count( $apf_fields ),
            'entries'     => $entry_count,
            'title'       => $gf_data['title'],
        ];
    }

    private static function map_gf_field( $gf ) {
        $type_map = [
            'text'      => 'text',
            'textarea'  => 'textarea',
            'email'     => 'email',
            'phone'     => 'phone',
            'number'    => 'number',
            'website'   => 'url',
            'select'    => 'select',
            'radio'     => 'radio',
            'checkbox'  => 'checkbox',
            'name'      => 'name',
            'fileupload'=> 'file',
            'date'      => 'date',
            'time'      => 'time',
            'hidden'    => 'hidden',
            'section'   => 'section',
            'html'      => 'html',
        ];

        $gf_type = $gf['type'] ?? 'text';
        $apf_type = $type_map[ $gf_type ] ?? 'text';

        $field = [
            'id'          => 'field_' . $gf['id'],
            '_gf_id'      => (string) $gf['id'],
            'type'        => $apf_type,
            'label'       => $gf['label'] ?? '',
            'description' => $gf['description'] ?? '',
            'required'    => ! empty( $gf['isRequired'] ),
            'css_class'   => $gf['cssClass'] ?? '',
            'conditions'  => [],
        ];

        // Conditional logic
        if ( ! empty( $gf['conditionalLogic'] ) ) {
            $field['conditions'] = self::map_gf_conditions( $gf['conditionalLogic'] );
        }

        // Choices
        if ( in_array( $apf_type, ['select','radio','checkbox'] ) ) {
            $choices = [];
            foreach ( (array) ( $gf['choices'] ?? [] ) as $c ) {
                $choices[] = $c['text'] ?? $c['value'] ?? '';
            }
            $field['choices'] = array_filter( $choices );
        }

        if ( $apf_type === 'section' && isset( $gf['content'] ) ) {
            $field['description'] = wp_kses_post( $gf['content'] );
        }
        if ( $apf_type === 'html' ) {
            $field['content'] = wp_kses_post( $gf['content'] ?? '' );
        }
        if ( $apf_type === 'file' ) {
            $field['max_files']   = ! empty($gf['multipleFiles']) ? ($gf['maxFiles'] ?: 5) : 1;
            $field['max_size_mb'] = 10;
            $field['allowed_ext'] = 'jpg,jpeg,png,gif,pdf,doc,docx';
        }

        return $field;
    }

    private static function map_gf_conditions( $gf_logic ) {
        if ( empty( $gf_logic['rules'] ) ) return [];
        $conditions = [
            'action'    => $gf_logic['actionType'] === 'show' ? 'show' : 'hide',
            'logic_type'=> $gf_logic['logicType'] === 'all' ? 'all' : 'any',
            'rules'     => [],
        ];
        foreach ( $gf_logic['rules'] as $rule ) {
            $conditions['rules'][] = [
                'field'    => 'field_' . $rule['fieldId'],
                'operator' => $rule['operator'],
                'value'    => $rule['value'],
            ];
        }
        return $conditions;
    }
}

// Hook export action
add_action( 'admin_init', [ 'APForms_Export', 'maybe_export' ] );
