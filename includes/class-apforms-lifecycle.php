<?php
/**
 * apForms lifecycle service.
 *
 * @package apForms
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('APForms_Lifecycle')) {
    final class APForms_Lifecycle
    {
        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function create_form(array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $form_key = self::code($data['form_key'] ?? '');
            $name = self::text($data['name'] ?? '');
            if ($form_key === '' || $name === '') {
                return self::error('form_required', 'Form key and name are required.');
            }

            $wpdb->insert(
                $t['forms'],
                [
                    'form_key' => $form_key,
                    'name' => $name,
                    'description' => self::textarea($data['description'] ?? ''),
                    'status' => self::status($data['status'] ?? 'draft', ['draft', 'active', 'inactive', 'archived'], 'draft'),
                    'settings_json' => self::json($data['settings'] ?? []),
                    'created_by_user_id' => absint($data['created_by_user_id'] ?? 0),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );

            $form_id = (int) $wpdb->insert_id;
            if ($form_id <= 0) {
                return self::error('form_insert_failed', 'Could not create form.');
            }

            return ['success' => true, 'form_id' => $form_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_field(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $field_key = self::code($data['field_key'] ?? '');
            $label = self::text($data['label'] ?? '');
            $type = self::code($data['field_type'] ?? 'text');
            if ($form_id <= 0 || $field_key === '' || $label === '' || !in_array($type, APForms_Contract::field_types(), true)) {
                return self::error('field_required', 'Valid form, field key, label, and field type are required.');
            }

            $wpdb->insert(
                $t['fields'],
                [
                    'form_id' => $form_id,
                    'field_key' => $field_key,
                    'label' => $label,
                    'field_type' => $type,
                    'required' => !empty($data['required']) ? 1 : 0,
                    'sort_order' => intval($data['sort_order'] ?? 0),
                    'choices_json' => self::json($data['choices'] ?? []),
                    'validation_json' => self::json($data['validation'] ?? []),
                    'settings_json' => self::json($data['settings'] ?? []),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s']
            );

            $field_id = (int) $wpdb->insert_id;
            if ($field_id <= 0) {
                return self::error('field_insert_failed', 'Could not create field.');
            }

            return ['success' => true, 'field_id' => $field_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_conditional_rule(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert(
                $t['conditional_rules'],
                [
                    'form_id' => $form_id,
                    'rule_key' => self::code($data['rule_key'] ?? 'rule_' . wp_generate_password(5, false, false)),
                    'target_field_key' => self::code($data['target_field_key'] ?? ''),
                    'action' => self::status($data['action'] ?? 'show', ['show', 'hide', 'require', 'skip'], 'show'),
                    'conditions_json' => self::json($data['conditions'] ?? []),
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
            );

            return ['success' => true, 'conditional_rule_id' => (int) $wpdb->insert_id];
        }

        /**
         * @return array<string,mixed>
         */
        public static function publish_form(int $form_id): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $form = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['forms']} WHERE id = %d", $form_id), ARRAY_A);
            if (!$form) {
                return self::error('form_not_found', 'Form not found.');
            }
            $fields = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['fields']} WHERE form_id = %d ORDER BY sort_order ASC, id ASC", $form_id), ARRAY_A);
            $rules = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$t['conditional_rules']} WHERE form_id = %d ORDER BY id ASC", $form_id), ARRAY_A);
            $version = 1 + (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(version_number), 0) FROM {$t['form_versions']} WHERE form_id = %d", $form_id));
            $schema = ['form' => $form, 'fields' => $fields, 'conditional_rules' => $rules];
            $hash = hash('sha256', self::json($schema));

            $wpdb->insert(
                $t['form_versions'],
                [
                    'form_id' => $form_id,
                    'version_number' => $version,
                    'status' => 'published',
                    'schema_json' => self::json($schema),
                    'schema_hash' => $hash,
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
            );
            $version_id = (int) $wpdb->insert_id;
            $wpdb->update($t['forms'], ['status' => 'active', 'updated_at' => $now], ['id' => $form_id]);

            return ['success' => true, 'form_version_id' => $version_id, 'schema_hash' => $hash];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_notification(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['notifications'], [
                'form_id' => $form_id,
                'notification_key' => self::code($data['notification_key'] ?? 'admin_email'),
                'channel' => self::status($data['channel'] ?? 'email', ['email', 'sms', 'internal'], 'email'),
                'recipient_rule_json' => self::json($data['recipient_rule'] ?? []),
                'subject_template' => self::text($data['subject_template'] ?? ''),
                'body_template' => wp_kses_post((string) ($data['body_template'] ?? '')),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'notification_id' => (int) $wpdb->insert_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_confirmation(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['confirmations'], [
                'form_id' => $form_id,
                'confirmation_key' => self::code($data['confirmation_key'] ?? 'default'),
                'confirmation_type' => self::status($data['confirmation_type'] ?? 'message', ['message', 'redirect'], 'message'),
                'message' => wp_kses_post((string) ($data['message'] ?? 'Thank you.')),
                'redirect_url' => esc_url_raw((string) ($data['redirect_url'] ?? '')),
                'conditions_json' => self::json($data['conditions'] ?? []),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'confirmation_id' => (int) $wpdb->insert_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_crm_route(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['crm_routes'], [
                'form_id' => $form_id,
                'route_key' => self::code($data['route_key'] ?? 'lead_route'),
                'target_module' => self::code($data['target_module'] ?? 'lead'),
                'field_map_json' => self::json($data['field_map'] ?? []),
                'consent_required' => !empty($data['consent_required']) ? 1 : 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'crm_route_id' => (int) $wpdb->insert_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_webhook_route(int $form_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['webhook_routes'], [
                'form_id' => $form_id,
                'webhook_key' => self::code($data['webhook_key'] ?? 'default_webhook'),
                'endpoint_url' => esc_url_raw((string) ($data['endpoint_url'] ?? '')),
                'event' => self::text($data['event'] ?? 'submission.created'),
                'signing_ref' => self::text($data['signing_ref'] ?? ''),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'webhook_route_id' => (int) $wpdb->insert_id];
        }

        /**
         * @param array<string,mixed> $payload
         * @return array<string,mixed>
         */
        public static function submit(int $form_id, int $form_version_id, array $payload): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $entry = 'APF-' . gmdate('Ymd-His') . '-' . strtolower(wp_generate_password(4, false, false));
            $values = is_array($payload['values'] ?? null) ? $payload['values'] : [];
            $email = sanitize_email((string) ($values['email'] ?? ''));
            $name = self::text($values['name'] ?? '');

            $wpdb->insert($t['submissions'], [
                'form_id' => $form_id,
                'form_version_id' => $form_version_id,
                'entry_number' => $entry,
                'status' => 'new',
                'spam_status' => 'not_checked',
                'submitter_email' => $email,
                'submitter_name' => $name,
                'source_url' => esc_url_raw((string) ($payload['source_url'] ?? '')),
                'ip_address' => self::text($payload['ip_address'] ?? ''),
                'user_agent' => self::textarea($payload['user_agent'] ?? ''),
                'consent_json' => self::json($payload['consent'] ?? []),
                'raw_payload_json' => self::json($payload),
                'submitted_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $submission_id = (int) $wpdb->insert_id;
            if ($submission_id <= 0) {
                return self::error('submission_insert_failed', 'Could not create submission.');
            }

            foreach ($values as $key => $value) {
                $field = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['fields']} WHERE form_id = %d AND field_key = %s", $form_id, self::code($key)), ARRAY_A);
                $wpdb->insert($t['submission_values'], [
                    'submission_id' => $submission_id,
                    'field_id' => (int) ($field['id'] ?? 0),
                    'field_key' => self::code($key),
                    'field_type' => (string) ($field['field_type'] ?? ''),
                    'value_text' => is_scalar($value) ? sanitize_textarea_field((string) $value) : '',
                    'value_json' => self::json($value),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $result = ['success' => true, 'submission_id' => $submission_id, 'entry_number' => $entry];

            /**
             * Fires after the submission and all of its field values exist.
             *
             * Magazine/editorial integrations use the workflow envelope in the
             * original payload to link this entry without making AP Forms own
             * the article, assignment, or flatplan lifecycle.
             *
             * @param int                 $submission_id
             * @param int                 $form_id
             * @param int                 $form_version_id
             * @param array<string,mixed> $payload
             * @param array<string,mixed> $result
             */
            do_action('apforms_submission_created', $submission_id, $form_id, $form_version_id, $payload, $result);

            return $result;
        }

        /**
         * @param array<int,string> $reasons
         * @return array<string,mixed>
         */
        public static function record_spam_event(int $submission_id, string $verdict, float $score, array $reasons = []): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $submission = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t['submissions']} WHERE id = %d", $submission_id), ARRAY_A);
            if (!$submission) {
                return self::error('submission_not_found', 'Submission not found.');
            }
            $verdict = self::status($verdict, ['clean', 'suspicious', 'spam'], 'clean');
            $wpdb->insert($t['spam_events'], [
                'submission_id' => $submission_id,
                'form_id' => (int) $submission['form_id'],
                'provider' => 'internal',
                'verdict' => $verdict,
                'score' => number_format($score, 4, '.', ''),
                'reasons_json' => self::json($reasons),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $wpdb->update($t['submissions'], ['spam_status' => $verdict, 'updated_at' => $now], ['id' => $submission_id]);
            return ['success' => true, 'spam_event_id' => (int) $wpdb->insert_id];
        }

        /**
         * @param array<string,mixed> $data
         * @return array<string,mixed>
         */
        public static function add_entry_note(int $submission_id, array $data): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['entry_notes'], [
                'submission_id' => $submission_id,
                'note_type' => self::code($data['note_type'] ?? 'note'),
                'note' => self::textarea($data['note'] ?? ''),
                'created_by_user_id' => absint($data['created_by_user_id'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'entry_note_id' => (int) $wpdb->insert_id];
        }

        /**
         * @return array<string,mixed>
         */
        public static function create_export_job(int $form_id, string $format = 'csv'): array
        {
            global $wpdb;
            $t = APForms_Schema::tables();
            $now = self::now();
            $wpdb->insert($t['export_jobs'], [
                'form_id' => $form_id,
                'export_key' => 'export_' . gmdate('Ymd_His') . '_' . strtolower(wp_generate_password(4, false, false)),
                'format' => self::status($format, ['csv', 'json'], 'csv'),
                'filters_json' => self::json([]),
                'status' => 'queued',
                'artifact_ref' => '',
                'created_by_user_id' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return ['success' => true, 'export_job_id' => (int) $wpdb->insert_id];
        }

        /**
         * @return array<string,int>
         */
        public static function counts(): array
        {
            global $wpdb;
            $counts = [];
            foreach (APForms_Schema::tables() as $key => $table) {
                $counts[$key] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
            }
            return $counts;
        }

        /**
         * @return array<string,mixed>
         */
        private static function error(string $code, string $message): array
        {
            return ['success' => false, 'code' => $code, 'message' => $message];
        }

        private static function now(): string
        {
            return current_time('mysql');
        }

        /**
         * @param mixed $value
         */
        private static function text($value): string
        {
            return sanitize_text_field((string) $value);
        }

        /**
         * @param mixed $value
         */
        private static function textarea($value): string
        {
            return sanitize_textarea_field((string) $value);
        }

        /**
         * @param mixed $value
         */
        private static function code($value): string
        {
            return sanitize_key((string) $value);
        }

        /**
         * @param mixed             $value
         * @param array<int,string> $allowed
         */
        private static function status($value, array $allowed, string $fallback): string
        {
            $status = self::code($value);
            return in_array($status, $allowed, true) ? $status : $fallback;
        }

        /**
         * @param mixed $value
         */
        private static function json($value): string
        {
            return wp_json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{}';
        }
    }
}
