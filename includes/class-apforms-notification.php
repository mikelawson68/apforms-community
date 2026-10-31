<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class APForms_Notification {

    public static function send_all( $form_id, $entry_id ) {
        $form          = APForms_Form::get_form( $form_id );
        $entry         = APForms_Entry::get_entry( $entry_id );
        $notifications = APForms_Form::get_notifications( $form_id );

        if ( ! $form || ! $entry ) return;

        foreach ( $notifications as $n ) {
            if ( ! $n->is_active ) continue;
            self::send_one( $n, $form, $entry );
        }
    }

    private static function send_one( $n, $form, $entry ) {
        // Resolve recipient
        $to = '';
        if ( $n->to_type === 'admin' || $n->to_type === 'custom' ) {
            $to = $n->to_email;
        } elseif ( $n->to_type === 'field' && $n->to_field ) {
            $to = $entry->values[ $n->to_field ]['value'] ?? '';
        }
        if ( ! $to || ! is_email( explode(',', $to)[0] ) ) return;

        $subject = self::replace_tags( $n->subject, $form, $entry );
        $body    = self::replace_tags( $n->body,    $form, $entry );

        $from_name  = $n->from_name  ?: get_option('blogname');
        $from_email = $n->from_email ?: get_option('admin_email');

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            "From: $from_name <$from_email>",
        ];
        if ( $n->reply_to ) $headers[] = "Reply-To: {$n->reply_to}";

        wp_mail( $to, $subject, nl2br( $body ), $headers );
    }

    // Replace {field_id}, {all_fields}, {entry_id}, {form_title}, {site_name}, {date}
    public static function replace_tags( $text, $form, $entry ) {
        // Standard tags
        $text = str_replace( '{entry_id}',  $entry->id,         $text );
        $text = str_replace( '{form_title}', esc_html($form->title), $text );
        $text = str_replace( '{site_name}', get_option('blogname'), $text );
        $text = str_replace( '{date}',      date_i18n( get_option('date_format') ), $text );
        $text = str_replace( '{time}',      date_i18n( get_option('time_format') ), $text );
        $text = str_replace( '{ip}',        esc_html( $entry->ip ?? '' ), $text );

        // {all_fields} — full entry table
        if ( strpos( $text, '{all_fields}' ) !== false ) {
            $table  = '<table style="border-collapse:collapse;width:100%;font-family:sans-serif;">';
            $table .= '<tr style="background:#f5f5f5;"><th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Field</th><th style="padding:8px 12px;text-align:left;border:1px solid #ddd;">Value</th></tr>';
            foreach ( $form->fields as $f ) {
                if ( in_array( $f['type'], ['section','html','hidden'] ) ) continue;
                $label = esc_html( $f['label'] ?? $f['id'] );
                $val   = esc_html( $entry->values[ $f['id'] ]['value'] ?? '—' );
                $table .= "<tr><td style=\"padding:8px 12px;border:1px solid #ddd;font-weight:600;\">$label</td><td style=\"padding:8px 12px;border:1px solid #ddd;\">$val</td></tr>";
            }
            $table .= '</table>';
            $text   = str_replace( '{all_fields}', $table, $text );
        }

        // Individual field tags {field_id}
        foreach ( $entry->values as $fid => $data ) {
            $text = str_replace( '{' . $fid . '}', esc_html( $data['value'] ), $text );
        }

        return $text;
    }
}
