<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$form_id = (int) ( $_GET['form_id'] ?? 0 );
$form    = $form_id ? APForms_Form::get_form( $form_id ) : null;

$title       = $form ? $form->title : '';
$description = $form ? esc_attr( $form->description ) : '';
$status      = $form ? $form->status                  : 'active';
$fields_json = $form ? wp_json_encode( $form->fields ) : '[]';
$settings    = $form ? $form->settings : [ 'submit_label' => 'Submit', 'confirmation_message' => '<p>Thank you! Your submission has been received.</p>' ];
$notifs      = $form_id ? APForms_Form::get_notifications( $form_id ) : [];

$page_title = $form_id ? 'Edit Form' : 'New Form';
$nonce      = wp_create_nonce( 'apforms_admin' );
$field_types = APForms_Fields::get_types();
?>
<div class="wrap apf-admin-wrap apf-builder-wrap">
    <h1 class="wp-heading-inline"><?php echo esc_html( $page_title ); ?></h1>
    <a href="<?php echo esc_url( admin_url('admin.php?page=apforms') ); ?>" class="page-title-action">← All Forms</a>
    <hr class="wp-header-end">

    <div id="apf-builder-notice" class="notice" style="display:none;"><p></p></div>

    <!-- Top toolbar -->
    <div class="apf-builder-toolbar">
        <input type="text" id="apf-form-title" class="apf-form-title-input" value="<?php echo esc_attr( $title ); ?>" placeholder="Form Title (required)" />
        <select id="apf-form-status" class="apf-status-select">
            <option value="active"    <?php selected($status,'active'); ?>>Active</option>
            <option value="inactive"  <?php selected($status,'inactive'); ?>>Inactive</option>
        </select>
        <button id="apf-preview-btn" type="button" class="button apf-preview-btn">◉ Preview Form</button>
        <button id="apf-save-btn" class="button button-primary apf-save-btn">💾 Save Form</button>
        <?php if ( $form_id ) : ?>
            <a href="<?php echo esc_url( admin_url('admin.php?page=apforms-entries&form_id=' . $form_id) ); ?>" class="button apf-entries-link">📋 View Entries</a>
        <?php endif; ?>
    </div>

    <!-- Main builder layout: palette | canvas | settings -->
    <div class="apf-builder-layout">

        <!-- LEFT: Field palette -->
        <div class="apf-palette">
            <h3 class="apf-palette-heading">Add Fields</h3>

            <?php
            $groups = [
                'basic'    => 'Basic',
                'choice'   => 'Choice',
                'advanced' => 'Advanced',
                'layout'   => 'Layout',
            ];
            foreach ( $groups as $gkey => $glabel ) :
                $gtypes = array_filter( $field_types, fn($t) => ($t['group'] === $gkey) );
                if ( empty($gtypes) ) continue;
            ?>
            <div class="apf-palette-group">
                <div class="apf-palette-group-label"><?php echo esc_html($glabel); ?></div>
                <?php foreach ( $gtypes as $type_key => $type_info ) : ?>
                    <button class="apf-palette-btn" data-type="<?php echo esc_attr($type_key); ?>" title="Add <?php echo esc_attr($type_info['label']); ?>">
                        <span class="apf-palette-icon"><?php echo esc_html( $type_info['icon'] ); ?></span>
                        <span class="apf-palette-label"><?php echo esc_html($type_info['label']); ?></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- CENTER: Canvas + Tabs -->
        <div class="apf-canvas-area">
            <div class="apf-builder-tabs">
                <button class="apf-tab active" data-tab="fields">Fields</button>
                <button class="apf-tab" data-tab="settings">Settings</button>
                <button class="apf-tab" data-tab="notifications">Notifications</button>
            </div>

            <!-- FIELDS TAB -->
            <div class="apf-tab-content active" id="apf-tab-fields">
                <div id="apf-canvas" class="apf-canvas">
                    <div class="apf-canvas-empty" id="apf-canvas-empty">
                        <p>👆 Click a field type on the left to add it here,<br>or drag fields to reorder them.</p>
                    </div>
                    <!-- Fields render here dynamically -->
                </div>
            </div>

            <!-- SETTINGS TAB -->
            <div class="apf-tab-content" id="apf-tab-settings">
                <div class="apf-settings-panel">
                    <table class="form-table apf-settings-table">
                        <tr>
                            <th><label for="apf-form-description">Form Description</label></th>
                            <td><textarea id="apf-form-description" rows="3" class="large-text"><?php echo esc_textarea( $form ? $form->description : '' ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label for="apf-submit-label">Submit Button Label</label></th>
                            <td><input type="text" id="apf-submit-label" value="<?php echo esc_attr( $settings['submit_label'] ?? 'Submit' ); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th><label for="apf-confirm-msg">Confirmation Message</label></th>
                            <td>
                                <textarea id="apf-confirm-msg" rows="4" class="large-text"><?php echo esc_textarea( $settings['confirmation_message'] ?? '' ); ?></textarea>
                                <p class="description">HTML allowed. Displayed after successful submission.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="apf-limit-one"><?php echo esc_html__( 'Limit repeat submissions', 'artistpro-forms' ); ?></label></th>
                            <td>
                                <label><input type="checkbox" id="apf-limit-one" value="1" <?php checked( ! empty( $settings['limit_one_submission'] ) ); ?>> <?php echo esc_html__( 'Allow one submission per access link or signed-in user', 'artistpro-forms' ); ?></label>
                                <p class="description"><?php echo esc_html__( 'For visitors without an access link or account, this uses their IP address. People sharing a network may be blocked.', 'artistpro-forms' ); ?></p>
                            </td>
                        </tr>
                        <?php do_action( 'apforms_builder_extended_settings', $settings ); ?>
                    </table>
                </div>
            </div>

            <!-- NOTIFICATIONS TAB -->
            <div class="apf-tab-content" id="apf-tab-notifications">
                <div class="apf-notif-panel">
                    <div class="apf-notif-list" id="apf-notif-list">
                        <?php if ( empty($notifs) ) : ?>
                            <p class="apf-notif-empty">No notifications yet. Save the form first, then add notifications.</p>
                        <?php else : foreach ( $notifs as $n ) : ?>
                            <div class="apf-notif-item" data-id="<?php echo esc_attr($n->id); ?>">
                                <div class="apf-notif-header">
                                    <strong><?php echo esc_html($n->name ?: 'Notification'); ?></strong>
                                    <span class="apf-notif-to">&rarr; <?php echo esc_html($n->to_email ?: $n->to_type); ?></span>
                                    <div class="apf-notif-actions">
                                        <button class="button button-small apf-edit-notif" data-id="<?php echo esc_attr($n->id); ?>">Edit</button>
                                        <button class="button button-small apf-delete-notif" data-id="<?php echo esc_attr($n->id); ?>">✕</button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                    <?php if ( $form_id ) : ?>
                        <button class="button apf-add-notif-btn" id="apf-add-notif">+ Add Notification</button>
                    <?php else : ?>
                        <p class="description">Save the form first to add notifications.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT: Field editor panel (slides in on field click) -->
        <div class="apf-editor-panel" id="apf-editor-panel">
            <div class="apf-editor-header">
                <h3 id="apf-editor-title">Field Settings</h3>
                <button id="apf-editor-close" class="apf-editor-close" title="Close">✕</button>
            </div>
            <div class="apf-editor-body" id="apf-editor-body">
                <!-- Populated dynamically by JS -->
            </div>
            <div class="apf-editor-footer">
                <button id="apf-editor-save" class="button button-primary">Apply</button>
                <button id="apf-editor-delete" class="button apf-danger-btn">Delete Field</button>
            </div>
        </div>
    </div><!-- .apf-builder-layout -->
</div>

<!-- Front-end preview: generated from the builder's current in-memory fields. -->
<div id="apf-preview-modal" class="apf-preview-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="apf-preview-title">
    <div class="apf-preview-backdrop" data-apf-preview-close="1"></div>
    <section class="apf-preview-dialog">
        <header class="apf-preview-header"><div><strong id="apf-preview-title">Form Preview</strong><span>Front-end appearance</span></div><button type="button" class="apf-preview-close" data-apf-preview-close="1" aria-label="Close preview">×</button></header>
        <div id="apf-preview-content" class="apf-preview-content"></div>
    </section>
</div>

<!-- Notification Modal -->
<div id="apf-notif-modal" class="apf-modal" style="display:none;">
    <div class="apf-modal-overlay"></div>
    <div class="apf-modal-box">
        <div class="apf-modal-header">
            <h3 id="apf-notif-modal-title">Add Notification</h3>
            <button class="apf-modal-close">✕</button>
        </div>
        <div class="apf-modal-body">
            <input type="hidden" id="apf-notif-id" value="">
            <table class="form-table">
                <tr>
                    <th><label>Notification Name</label></th>
                    <td><input type="text" id="apf-notif-name" class="regular-text" placeholder="e.g. Admin Notification"></td>
                </tr>
                <tr>
                    <th><label>Active</label></th>
                    <td><label><input type="checkbox" id="apf-notif-active" checked> Send this notification</label></td>
                </tr>
                <tr>
                    <th><label>Send To</label></th>
                    <td>
                        <select id="apf-notif-to-type">
                            <option value="admin">Admin Email</option>
                            <option value="custom">Custom Email</option>
                            <option value="field">Email Field in Form</option>
                        </select>
                    </td>
                </tr>
                <tr id="apf-notif-to-email-row">
                    <th><label>Email Address</label></th>
                    <td><input type="text" id="apf-notif-to-email" class="regular-text" placeholder="email@example.com"></td>
                </tr>
                <tr id="apf-notif-to-field-row" style="display:none;">
                    <th><label>Email Field</label></th>
                    <td>
                        <select id="apf-notif-to-field">
                            <?php
                            if ( $form ) {
                                foreach ( $form->fields as $f ) {
                                    if ( $f['type'] === 'email' ) {
                                        echo '<option value="' . esc_attr($f['id']) . '">' . esc_html($f['label']) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label>From Name</label></th>
                    <td><input type="text" id="apf-notif-from-name" class="regular-text" placeholder="<?php echo esc_attr(get_option('blogname')); ?>"></td>
                </tr>
                <tr>
                    <th><label>From Email</label></th>
                    <td><input type="text" id="apf-notif-from-email" class="regular-text" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"></td>
                </tr>
                <tr>
                    <th><label>Reply-To</label></th>
                    <td><input type="text" id="apf-notif-reply-to" class="regular-text" placeholder="Leave blank to use From Email"></td>
                </tr>
                <tr>
                    <th><label>Subject</label></th>
                    <td>
                        <input type="text" id="apf-notif-subject" class="large-text" placeholder="New submission: {form_title}">
                        <p class="description">Merge tags: <code>{form_title}</code> <code>{entry_id}</code> <code>{site_name}</code> <code>{date}</code></p>
                    </td>
                </tr>
                <tr>
                    <th><label>Message</label></th>
                    <td>
                        <textarea id="apf-notif-body" rows="8" class="large-text" placeholder="{all_fields}"></textarea>
                        <p class="description">Use <code>{all_fields}</code> to include all field values, or individual field tags like <code>{field_id}</code>.</p>
                    </td>
                </tr>
            </table>
        </div>
        <div class="apf-modal-footer">
            <button id="apf-notif-save-btn" class="button button-primary">Save Notification</button>
            <button class="apf-modal-close button">Cancel</button>
        </div>
    </div>
</div>

<!-- Pass data to JS -->
<script>
var apfBuilder = <?php echo wp_json_encode([
    'formId'     => $form_id,
    'nonce'      => $nonce,
    'ajaxUrl'    => admin_url('admin-ajax.php'),
    'fields'     => json_decode( $fields_json, true ),
    'fieldTypes' => $field_types,
    'defaults'   => array_map( [APForms_Fields::class, 'get_defaults'], array_keys($field_types) ),
]); ?>;
// Map defaults to keyed object
apfBuilder.defaultsMap = {};
<?php foreach ( $field_types as $tk => $ti ) : ?>
apfBuilder.defaultsMap[<?php echo wp_json_encode($tk); ?>] = <?php echo wp_json_encode( APForms_Fields::get_defaults($tk) ); ?>;
<?php endforeach; ?>
</script>
