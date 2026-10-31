<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_Renderer {

    public static function render( $form_id, $opts = [] ) {
        $form_id = absint( $form_id );
        $form = APForms_Form::get_form( $form_id );
        if ( ! $form || $form->status !== 'active' ) return '';

        foreach ( $form->fields as $field ) {
            if ( ! empty( $field['conditions']['rules'] ) && ! has_filter( 'apforms_field_visibility' ) ) {
                return '<p class="apf-error-summary" role="alert">' . esc_html__( 'This form requires apForms Pro. Please contact the site administrator.', 'artistpro-forms' ) . '</p>';
            }
            if ( ( $field['type'] ?? '' ) === 'file' && ! has_filter( 'apforms_validate_upload' ) ) {
                return '<p class="apf-error-summary" role="alert">' . esc_html__( 'This form requires apForms Pro. Please contact the site administrator.', 'artistpro-forms' ) . '</p>';
            }
        }

        $settings       = $form->settings;
        $show_title     = $opts['show_title']       ?? true;
        $show_desc      = $opts['show_description'] ?? true;
        $submit_label   = $settings['submit_label'] ?? 'Submit';
        $has_files      = false;
        foreach ( $form->fields as $f ) {
            if ( $f['type'] === 'file' ) { $has_files = true; break; }
        }

        ob_start();
        echo '<div class="apf-form-wrap" id="apf-form-' . esc_attr( (string) $form_id ) . '">';

        if ( $show_title ) {
            echo '<h2 class="apf-form-title">' . esc_html( $form->title ) . '</h2>';
        }
        if ( $show_desc && ! empty( $form->description ) ) {
            echo '<div class="apf-form-description">' . wp_kses_post( $form->description ) . '</div>';
        }

        // Confirmation message (shown after submit)
        $confirm_msg = $settings['confirmation_message'] ?? '<p>Thank you! Your submission has been received.</p>';
        echo '<div class="apf-confirmation" id="apf-confirm-' . esc_attr( (string) $form_id ) . '" style="display:none;">' . wp_kses_post( $confirm_msg ) . '</div>';

        // Error summary
        echo '<div class="apf-error-summary" id="apf-errors-' . esc_attr( (string) $form_id ) . '" style="display:none;" role="alert"></div>';

        echo '<form class="apf-form" id="apf-form-inner-' . esc_attr( (string) $form_id ) . '" method="post" novalidate';
        if ( $has_files ) echo ' enctype="multipart/form-data"';
        echo '>';
        echo '<input type="hidden" name="apf_form_id" value="' . esc_attr( $form_id ) . '">';
        echo '<input type="hidden" name="apf_nonce" value="' . esc_attr( wp_create_nonce( 'apforms_submit_' . $form_id ) ) . '">';

        // Honeypot
        echo '<div class="apf-hp" aria-hidden="true" style="display:none !important;position:absolute;left:-9999px;">';
        echo '<input type="text" name="apf_hp" tabindex="-1" autocomplete="off">';
        echo '</div>';

        foreach ( $form->fields as $field ) {
            // Field renderer escapes each attribute and sanitizes supported HTML.
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo APForms_Fields::render( $field );
        }

        echo "<div class=\"apf-submit-row\">";
        echo '<button type="submit" class="apf-submit-btn" data-form="' . esc_attr( (string) $form_id ) . '">' . esc_html( $submit_label ) . '</button>';
        echo "</div>";
        echo '</form>';
        echo '</div>'; // .apf-form-wrap
        return ob_get_clean();
    }
}
