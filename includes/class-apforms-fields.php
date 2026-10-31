<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Field type registry and rendering
 */
class APForms_Fields {

    // All supported field types
    public static function get_types() {
        $types = [
            'text'      => [ 'label' => 'Text',          'icon' => '✏️',  'group' => 'basic' ],
            'textarea'  => [ 'label' => 'Paragraph Text','icon' => '📝',  'group' => 'basic' ],
            'email'     => [ 'label' => 'Email',          'icon' => '📧',  'group' => 'basic' ],
            'phone'     => [ 'label' => 'Phone',          'icon' => '📞',  'group' => 'basic' ],
            'number'    => [ 'label' => 'Number',         'icon' => '🔢',  'group' => 'basic' ],
            'url'       => [ 'label' => 'Website URL',    'icon' => '🌐',  'group' => 'basic' ],
            'name'      => [ 'label' => 'Name',           'icon' => '👤',  'group' => 'basic' ],
            'select'    => [ 'label' => 'Dropdown',       'icon' => '▼',   'group' => 'choice' ],
            'radio'     => [ 'label' => 'Radio Buttons',  'icon' => '⚬',   'group' => 'choice' ],
            'checkbox'  => [ 'label' => 'Checkboxes',     'icon' => '☑️',  'group' => 'choice' ],
            'date'      => [ 'label' => 'Date',           'icon' => '📅',  'group' => 'advanced' ],
            'time'      => [ 'label' => 'Time',           'icon' => '⏰',  'group' => 'advanced' ],
            'hidden'    => [ 'label' => 'Hidden Field',   'icon' => '👁‍🗨', 'group' => 'advanced' ],
            'section'   => [ 'label' => 'Section Break',  'icon' => '—',   'group' => 'layout' ],
            'html'      => [ 'label' => 'HTML Block',     'icon' => '</>',  'group' => 'layout' ],
        ];
        return apply_filters( 'apforms_field_types', $types );
    }

    // Default properties for a new field of a given type
    public static function get_defaults( $type ) {
        $base = [
            'id'          => '',
            'type'        => $type,
            'label'       => self::get_types()[$type]['label'] ?? ucfirst($type),
            'description' => '',
            'required'    => false,
            'css_class'   => '',
            'conditions'  => [],
        ];
        switch ( $type ) {
            case 'text':
            case 'email':
            case 'phone':
            case 'url':
                return array_merge( $base, [ 'placeholder' => '', 'max_length' => '' ] );
            case 'textarea':
                return array_merge( $base, [ 'placeholder' => '', 'rows' => 5 ] );
            case 'number':
                return array_merge( $base, [ 'min' => '', 'max' => '', 'step' => '' ] );
            case 'name':
                return array_merge( $base, [ 'show_prefix' => false, 'show_middle' => false ] );
            case 'select':
            case 'radio':
            case 'checkbox':
                return array_merge( $base, [ 'choices' => [ 'Option 1', 'Option 2', 'Option 3' ] ] );
            case 'date':
                return array_merge( $base, [ 'min_date' => '', 'max_date' => '' ] );
            case 'hidden':
                return array_merge( $base, [ 'default_value' => '', 'required' => false ] );
            case 'section':
                return array_merge( $base, [ 'label' => 'Section Title', 'description' => '' ] );
            case 'html':
                return array_merge( $base, [ 'content' => '<p>Add HTML content here.</p>' ] );
            default:
                return apply_filters( 'apforms_field_defaults', $base, $type );
        }
    }

    // Render a single field for front-end display
    public static function render( $field, $value = '', $error = '' ) {
        $raw_id = 'apf_' . $field['id'];
        $raw_name = 'apf_fields[' . $field['id'] . ']';
        $id    = esc_attr( $raw_id );
        $name  = esc_attr( $raw_name );
        $req   = ! empty( $field['required'] ) ? 'required' : '';
        $classes = 'apf-field apf-type-' . $field['type'];
        if ( ! empty( $field['css_class'] ) ) $classes .= ' ' . $field['css_class'];

        ob_start();
        echo '<div class="' . esc_attr( $classes ) . '" id="' . esc_attr( 'wrap_' . $raw_id ) . '"';
        if ( ! empty( $field['conditions'] ) ) echo ' data-conditions="' . esc_attr( wp_json_encode( $field['conditions'] ) ) . '"';
        echo '>';

        // Section / HTML are layout-only
        if ( $field['type'] === 'section' ) {
            echo '<div class="apf-section-break">';
            if ( ! empty( $field['label'] ) ) echo '<h3 class="apf-section-title">' . esc_html( $field['label'] ) . '</h3>';
            if ( ! empty( $field['description'] ) ) echo '<p class="apf-section-desc">' . wp_kses_post( $field['description'] ) . '</p>';
            echo '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        if ( $field['type'] === 'html' ) {
            echo '<div class="apf-html-block">' . wp_kses_post( $field['content'] ?? '' ) . '</div>';
            echo '</div>';
            return ob_get_clean();
        }

        if ( $field['type'] === 'hidden' ) {
            echo '<input type="hidden" name="' . esc_attr( $raw_name ) . '" id="' . esc_attr( $raw_id ) . '" value="' . esc_attr( $field['default_value'] ?? '' ) . '">';
            echo '</div>';
            return ob_get_clean();
        }

        // Label
        echo '<label class="apf-label" for="' . esc_attr( $raw_id ) . '">' . esc_html( $field['label'] );
        if ( ! empty( $field['required'] ) ) echo ' <span class="apf-required" aria-hidden="true">*</span>';
        echo '</label>';

        // Description
        if ( ! empty( $field['description'] ) ) {
            echo '<div class="apf-field-desc">' . wp_kses_post( $field['description'] ) . '</div>';
        }

        // Error
        if ( $error ) {
            echo '<div class="apf-field-error" role="alert">' . esc_html( $error ) . '</div>';
        }

        $raw_value = $value;
        $value = is_scalar( $value ) ? esc_attr( (string) $value ) : '';

        switch ( $field['type'] ) {

            case 'text':
            case 'email':
            case 'phone':
            case 'url':
                $type = $field['type'] === 'phone' ? 'tel' : $field['type'];
                echo '<input type="' . esc_attr( $type ) . '" class="apf-input" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '" value="' . esc_attr( is_scalar( $raw_value ) ? (string) $raw_value : '' ) . '" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '"';
                if ( ! empty( $field['max_length'] ) ) echo ' maxlength="' . esc_attr( (string) absint( $field['max_length'] ) ) . '"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                break;

            case 'number':
                echo '<input type="number" class="apf-input" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '" value="' . esc_attr( is_scalar( $raw_value ) ? (string) $raw_value : '' ) . '"';
                foreach ( [ 'min', 'max', 'step' ] as $attribute ) {
                    if ( isset( $field[ $attribute ] ) && $field[ $attribute ] !== '' ) echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( (string) $field[ $attribute ] ) . '"';
                }
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                break;

            case 'textarea':
                echo '<textarea class="apf-textarea" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '" rows="' . esc_attr( (string) (int) ( $field['rows'] ?? 5 ) ) . '" placeholder="' . esc_attr( $field['placeholder'] ?? '' ) . '"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>' . esc_textarea( is_scalar( $raw_value ) ? (string) $raw_value : '' ) . '</textarea>';
                break;

            case 'select':
                echo '<select class="apf-select" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                echo "<option value=\"\">-- Select --</option>";
                foreach ( (array) ($field['choices'] ?? []) as $choice ) {
                    echo '<option value="' . esc_attr( $choice ) . '"';
                    selected( is_scalar( $raw_value ) ? (string) $raw_value : '', $choice );
                    echo '>' . esc_html( $choice ) . '</option>';
                }
                echo "</select>";
                break;

            case 'radio':
                echo '<div class="apf-radio-group" role="radiogroup">';
                foreach ( (array) ( $field['choices'] ?? [] ) as $i => $choice ) {
                    echo '<label class="apf-choice-label"><input type="radio" id="' . esc_attr( $raw_id . '_' . absint( $i ) ) . '" name="' . esc_attr( $raw_name ) . '" value="' . esc_attr( $choice ) . '"';
                    checked( is_scalar( $raw_value ) ? (string) $raw_value : '', $choice );
                    if ( ! empty( $field['required'] ) ) echo ' required';
                    echo '> ' . esc_html( $choice ) . '</label>';
                }
                echo '</div>';
                break;

            case 'checkbox':
                $vals = is_array( $raw_value ) ? $raw_value : ( $raw_value ? explode( ',', (string) $raw_value ) : [] );
                echo '<div class="apf-checkbox-group">';
                foreach ( (array) ( $field['choices'] ?? [] ) as $i => $choice ) {
                    echo '<label class="apf-choice-label"><input type="checkbox" id="' . esc_attr( $raw_id . '_' . absint( $i ) ) . '" name="' . esc_attr( $raw_name . '[]' ) . '" value="' . esc_attr( $choice ) . '"';
                    checked( in_array( $choice, $vals ) );
                    echo '> ' . esc_html( $choice ) . '</label>';
                }
                echo '</div>';
                break;

            case 'name':
                $show_prefix = ! empty( $field['show_prefix'] );
                $show_middle = ! empty( $field['show_middle'] );
                echo "<div class=\"apf-name-group\">";
                if ( $show_prefix ) {
                    echo '<select class="apf-input apf-name-prefix" name="' . esc_attr( "apf_fields[{$field['id']}_prefix]" ) . '"><option value="">Prefix</option>';
                    foreach ( ['Dr.','Miss','Mr.','Mrs.','Ms.','Prof.','Rev.'] as $p ) echo "<option>" . esc_html($p) . "</option>";
                    echo "</select>";
                }
                echo '<input type="text" class="apf-input apf-name-first" name="' . esc_attr( "apf_fields[{$field['id']}_first]" ) . '" placeholder="First Name"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                if ( $show_middle ) {
                    echo '<input type="text" class="apf-input apf-name-middle" name="' . esc_attr( "apf_fields[{$field['id']}_middle]" ) . '" placeholder="Middle Name">';
                }
                echo '<input type="text" class="apf-input apf-name-last" name="' . esc_attr( "apf_fields[{$field['id']}_last]" ) . '" placeholder="Last Name"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                echo "</div>";
                break;

            case 'file':
                do_action( 'apforms_render_upload_input', $field, $id, $req );
                break;

            case 'date':
                echo '<input type="date" class="apf-input" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '" value="' . esc_attr( is_scalar( $raw_value ) ? (string) $raw_value : '' ) . '"';
                if ( ! empty( $field['min_date'] ) ) echo ' min="' . esc_attr( $field['min_date'] ) . '"';
                if ( ! empty( $field['max_date'] ) ) echo ' max="' . esc_attr( $field['max_date'] ) . '"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                break;

            case 'time':
                echo '<input type="time" class="apf-input" id="' . esc_attr( $raw_id ) . '" name="' . esc_attr( $raw_name ) . '" value="' . esc_attr( is_scalar( $raw_value ) ? (string) $raw_value : '' ) . '"';
                if ( ! empty( $field['required'] ) ) echo ' required';
                echo '>';
                break;
        }

        echo '</div>'; // .apf-field
        return ob_get_clean();
    }

    // Validate a single field value
    public static function validate( $field, $value ) {
        if ( ! empty( $field['required'] ) ) {
            $empty = is_array( $value ) ? empty( array_filter( $value ) ) : trim( $value ) === '';
            if ( $empty ) return $field['label'] . ' is required.';
        }
        if ( empty( $value ) ) return null; // Not required and empty – skip

        switch ( $field['type'] ) {
            case 'email':
                if ( ! is_email( $value ) ) return 'Please enter a valid email address.';
                break;
            case 'url':
                if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) return 'Please enter a valid URL.';
                break;
            case 'number':
                if ( ! is_numeric( $value ) ) return 'Please enter a valid number.';
                if ( $field['min'] !== '' && $value < $field['min'] ) return "Minimum value is {$field['min']}.";
                if ( $field['max'] !== '' && $value > $field['max'] ) return "Maximum value is {$field['max']}.";
                break;
        }
        return null;
    }
}
