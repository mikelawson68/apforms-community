<?php
/**
 * apForms Portal Page
 * Renders a form management UI for users with administrator permissions
 * WITHOUT requiring WP-Admin access.
 *
 * Include this template from your portal's page template:
 *   include ABSPATH . 'wp-content/plugins/apforms/portal/forms.php';
 * The template also checks that the current user can manage WordPress options.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
if ( ! is_user_logged_in() ) {
    echo '<p>Please <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">log in</a> to manage forms.</p>';
    return;
}
// The management portal exposes entries directly, so match the administrator-only AJAX permission.
if ( ! current_user_can( 'manage_options' ) ) {
    if ( ! headers_sent() ) status_header( 403 );
    echo '<p>' . esc_html__( 'You do not have permission to manage forms.', 'artistpro-forms' ) . '</p>';
    return;
}

// This template may run after wp_head; enqueue and print registered local styles once.
wp_enqueue_style( 'apforms-admin', plugins_url( 'assets/css/apforms-admin.css', APFORMS_FILE ), [], APFORMS_VERSION );
wp_enqueue_style( 'apforms-portal', plugins_url( 'portal/portal.css', APFORMS_FILE ), [], APFORMS_VERSION );
wp_print_styles( [ 'apforms-admin', 'apforms-portal' ] );
// JS: wp_footer() has NOT fired yet so footer-enqueued scripts work fine.
wp_enqueue_script( 'sortablejs',      plugins_url( 'assets/vendor/sortablejs/Sortable.min.js', APFORMS_FILE ), [], '1.15.2', true );
wp_enqueue_script( 'apforms-builder', plugins_url( 'assets/js/apforms-builder.js', APFORMS_FILE ), [ 'jquery', 'sortablejs' ], APFORMS_VERSION, true );

$current_user = wp_get_current_user();
$nonce        = wp_create_nonce( 'apforms_admin' );
$ajaxUrl      = admin_url( 'admin-ajax.php' );
$field_types  = APForms_Fields::get_types();

// Determine sub-view: list | builder | entries
$view     = sanitize_key( $_GET['apf_view']    ?? 'list' );
$form_id  = (int) ( $_GET['apf_form_id'] ?? 0 );
?>

<div class="apf-portal-wrap">

    <div class="apf-portal-header">
        <div class="apf-portal-header-inner">
            <h2 class="apf-portal-title">📋 Forms</h2>
            <?php if ( $view !== 'list' ) : ?>
                <a href="?apf_view=list" class="apf-portal-back">← All Forms</a>
            <?php endif; ?>
        </div>
        <?php if ( $view === 'list' ) : ?>
            <a href="?apf_view=builder" class="apf-portal-btn apf-portal-btn-primary">+ New Form</a>
        <?php endif; ?>
    </div>

    <?php if ( $view === 'list' ) : ?>
        <!-- ─── FORMS LIST ───────────────────────────────────────────── -->
        <?php
        $forms = APForms_Form::get_forms();
        if ( empty( $forms ) ) :
        ?>
            <div class="apf-portal-empty">
                <div class="apf-portal-empty-icon">📋</div>
                <p>No forms yet. Create your first form to start collecting submissions.</p>
                <a href="?apf_view=builder" class="apf-portal-btn apf-portal-btn-primary">Create Form</a>
            </div>
        <?php else : ?>
            <div class="apf-portal-forms-grid">
            <?php foreach ( $forms as $form ) :
                $count   = APForms_Form::get_entry_count( $form->id );
                $sc      = '[apform id="' . $form->id . '"]';
            ?>
                <div class="apf-portal-form-card">
                    <div class="apf-pfc-header">
                        <h3 class="apf-pfc-title"><?php echo esc_html( $form->title ); ?></h3>
                        <span class="apf-badge apf-badge-<?php echo esc_attr( $form->status ); ?>"><?php echo esc_html( ucfirst($form->status) ); ?></span>
                    </div>
                    <?php if ( $form->description ) : ?>
                        <p class="apf-pfc-desc"><?php echo esc_html( wp_trim_words( $form->description, 15 ) ); ?></p>
                    <?php endif; ?>
                    <div class="apf-pfc-meta">
                        <span>📝 <?php echo esc_html( is_array($form->fields) ? count($form->fields) : 0 ); ?> fields</span>
                        <span>📊 <?php echo esc_html( $count ); ?> <?php echo $count === 1 ? 'entry' : 'entries'; ?></span>
                        <span title="<?php echo esc_attr( date_i18n('M j, Y', strtotime($form->created_at)) ); ?>">
                            🗓 <?php echo esc_html( date_i18n( 'M j', strtotime($form->created_at) ) ); ?>
                        </span>
                    </div>
                    <div class="apf-pfc-shortcode" title="Click to copy shortcode">
                        <code class="apf-portal-sc-copy" data-sc="<?php echo esc_attr($sc); ?>"><?php echo esc_html($sc); ?></code>
                    </div>
                    <div class="apf-pfc-actions">
                        <a href="?apf_view=builder&apf_form_id=<?php echo esc_attr($form->id); ?>" class="apf-portal-btn">✏️ Edit</a>
                        <?php if ( $count > 0 ) : ?>
                            <a href="?apf_view=entries&apf_form_id=<?php echo esc_attr($form->id); ?>" class="apf-portal-btn">📋 Entries (<?php echo esc_html($count); ?>)</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>


    <?php elseif ( $view === 'builder' ) : ?>
        <!-- ─── FORM BUILDER ─────────────────────────────────────────── -->
        <?php
        $form        = $form_id ? APForms_Form::get_form( $form_id ) : null;
        $fields_json = $form ? wp_json_encode( $form->fields ) : '[]';
        $settings    = $form ? $form->settings : [ 'submit_label' => 'Submit', 'confirmation_message' => '' ];
        $status      = $form ? $form->status : 'active';
        ?>

        <div id="apf-builder-notice" class="apf-portal-notice" style="display:none;"></div>

        <div class="apf-portal-builder-toolbar">
            <input type="text" id="apf-form-title" class="apf-portal-title-input"
                value="<?php echo $form ? esc_attr($form->title) : ''; ?>"
                placeholder="Form Title (required)">
            <select id="apf-form-status" class="apf-portal-status-select">
                <option value="active"   <?php selected($status,'active'); ?>>Active</option>
                <option value="inactive" <?php selected($status,'inactive'); ?>>Inactive</option>
            </select>
            <button id="apf-save-btn" class="apf-portal-btn apf-portal-btn-primary">💾 Save</button>
        </div>

        <div class="apf-portal-builder-tabs">
            <button class="apf-tab active" data-tab="fields">Fields</button>
            <button class="apf-tab" data-tab="settings">Settings</button>
        </div>

        <div class="apf-portal-builder-layout">

            <!-- Palette -->
            <div class="apf-palette apf-portal-palette">
                <div class="apf-palette-heading">Add Fields</div>
                <?php
                $groups = ['basic'=>'Basic','choice'=>'Choice','advanced'=>'Advanced','layout'=>'Layout'];
                foreach ( $groups as $gk => $gl ) :
                    $gtypes = array_filter( $field_types, fn($t) => $t['group'] === $gk );
                    if ( empty($gtypes) ) continue;
                ?>
                    <div class="apf-palette-group">
                        <div class="apf-palette-group-label"><?php echo esc_html($gl); ?></div>
                        <?php foreach ( $gtypes as $tk => $ti ) : ?>
                            <button class="apf-palette-btn" data-type="<?php echo esc_attr($tk); ?>">
                                <span class="apf-palette-icon"><?php echo esc_html( $ti['icon'] ); ?></span>
                                <span class="apf-palette-label"><?php echo esc_html($ti['label']); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Canvas + Settings -->
            <div class="apf-portal-canvas-col">
                <div class="apf-tab-content active" id="apf-tab-fields">
                    <div id="apf-canvas" class="apf-canvas">
                        <div class="apf-canvas-empty" id="apf-canvas-empty">
                            <p>Click a field type on the left to add it here.</p>
                        </div>
                    </div>
                </div>
                <div class="apf-tab-content" id="apf-tab-settings">
                    <div class="apf-portal-settings">
                        <div class="apf-portal-settings-row">
                            <label>Form Description</label>
                            <textarea id="apf-form-description" rows="3"><?php echo esc_textarea( $form ? $form->description : '' ); ?></textarea>
                        </div>
                        <div class="apf-portal-settings-row">
                            <label>Submit Button Label</label>
                            <input type="text" id="apf-submit-label" value="<?php echo esc_attr( $settings['submit_label'] ?? 'Submit' ); ?>">
                        </div>
                        <div class="apf-portal-settings-row">
                            <label>Confirmation Message</label>
                            <textarea id="apf-confirm-msg" rows="4"><?php echo esc_textarea( $settings['confirmation_message'] ?? '' ); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Editor panel -->
            <div class="apf-editor-panel" id="apf-editor-panel">
                <div class="apf-editor-header">
                    <h3 id="apf-editor-title">Field Settings</h3>
                    <button id="apf-editor-close" class="apf-editor-close">✕</button>
                </div>
                <div class="apf-editor-body" id="apf-editor-body"></div>
                <div class="apf-editor-footer">
                    <button id="apf-editor-save" class="apf-portal-btn apf-portal-btn-primary">Apply</button>
                    <button id="apf-editor-delete" class="apf-portal-btn apf-portal-btn-danger">Delete</button>
                </div>
            </div>
        </div>

        <script>
        var apfBuilder = <?php echo wp_json_encode([
            'formId'    => $form_id,
            'nonce'     => $nonce,
            'ajaxUrl'   => $ajaxUrl,
            'fields'    => json_decode( $fields_json, true ),
            'fieldTypes'=> $field_types,
            'saveAction'=> 'apforms_portal_save_form',
            'redirectBase' => '?apf_view=builder&apf_form_id=',
        ]); ?>;
        apfBuilder.defaultsMap = {};
        <?php foreach ( $field_types as $tk => $ti ) : ?>
        apfBuilder.defaultsMap[<?php echo wp_json_encode($tk); ?>] = <?php echo wp_json_encode( APForms_Fields::get_defaults($tk) ); ?>;
        <?php endforeach; ?>
        </script>


    <?php elseif ( $view === 'entries' && $form_id ) : ?>
        <!-- ─── ENTRIES VIEW ─────────────────────────────────────────── -->
        <?php
        $form   = APForms_Form::get_form( $form_id );
        if ( ! $form ) { echo '<p>Form not found.</p>'; return; }

        $page   = max(1, (int)($_GET['paged'] ?? 1));
        $per    = 25;
        $result = APForms_Entry::get_entries( $form_id, ['per_page' => $per, 'offset' => ($page-1)*$per] );
        $entries = $result['rows'] ?? [];
        $total  = $result['total'] ?? 0;
        $pages  = ceil( $total / $per );

        $visible = array_values( array_filter( $form->fields, fn($f) => !in_array($f['type'],['section','html','hidden']) ) );
        $show    = array_slice( $visible, 0, 5 );

        $export_url = admin_url( 'admin-ajax.php?action=apforms_export_entries&form_id=' . $form_id . '&nonce=' . $nonce );
        ?>
        <div class="apf-portal-entries-header">
            <h3 class="apf-portal-entries-title"><?php echo esc_html($form->title); ?> — Entries</h3>
            <div class="apf-portal-entries-actions">
                <span class="apf-portal-entry-count"><?php echo esc_html($total); ?> total</span>
                <a href="<?php echo esc_url($export_url); ?>" class="apf-portal-btn">⬇️ Export CSV</a>
                <a href="?apf_view=builder&apf_form_id=<?php echo esc_attr($form_id); ?>" class="apf-portal-btn">✏️ Edit Form</a>
            </div>
        </div>

        <?php if ( empty($entries) ) : ?>
            <div class="apf-portal-empty">
                <div class="apf-portal-empty-icon">📭</div>
                <p>No entries yet.</p>
                <p>Shortcode: <code>[apform id="<?php echo esc_html($form_id); ?>"]</code></p>
            </div>
        <?php else : ?>
            <div class="apf-portal-table-wrap">
                <table class="apf-portal-entries-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Date</th>
                            <?php foreach ( $show as $sf ) : ?>
                                <th><?php echo esc_html($sf['label']); ?></th>
                            <?php endforeach; ?>
                            <th>View</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $entries as $entry ) : ?>
                        <tr>
                            <td><?php echo esc_html($entry->id); ?></td>
                            <td><?php echo esc_html( date_i18n('M j, Y', strtotime($entry->created_at)) ); ?></td>
                            <?php foreach ( $show as $sf ) :
                                $v = $entry->values[$sf['id']]['value'] ?? '';
                                if (is_array($v)) $v = implode(', ',$v);
                            ?>
                                <td title="<?php echo esc_attr($v); ?>"><?php echo esc_html( wp_trim_words($v,6,'…') ); ?></td>
                            <?php endforeach; ?>
                            <td><a href="#" class="apf-portal-view-entry apf-portal-link" data-id="<?php echo esc_attr($entry->id); ?>">View</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ( $pages > 1 ) : ?>
                <div class="apf-portal-pagination">
                    <?php for($p=1;$p<=$pages;$p++) : ?>
                        <a href="?apf_view=entries&apf_form_id=<?php echo esc_attr( (string) $form_id ); ?>&paged=<?php echo esc_html( (string) $p ); ?>"
                           class="apf-portal-page-btn <?php echo $p===$page?'active':''; ?>"><?php echo esc_html( (string) $p ); ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <!-- Entry detail modal -->
        <div id="apf-portal-entry-modal" class="apf-portal-modal" style="display:none;">
            <div class="apf-portal-modal-overlay"></div>
            <div class="apf-portal-modal-box">
                <div class="apf-portal-modal-header">
                    <h3>Entry Detail</h3>
                    <button class="apf-portal-modal-close">✕</button>
                </div>
                <div class="apf-portal-modal-body" id="apf-portal-entry-detail"></div>
                <div class="apf-portal-modal-footer">
                    <button class="apf-portal-modal-close apf-portal-btn">Close</button>
                </div>
            </div>
        </div>

        <script>
        var apfPortalEntries = {
            data: <?php
                $emap = [];
                foreach($entries as $e) {
                    $emap[$e->id] = ['id'=>$e->id,'created_at'=>$e->created_at,'ip'=>$e->ip,'values'=>$e->values];
                }
                echo wp_json_encode($emap);
            ?>
        };
        document.addEventListener('DOMContentLoaded', function() {
            var modal  = document.getElementById('apf-portal-entry-modal');
            var detail = document.getElementById('apf-portal-entry-detail');
            function openEntry(id) {
                var e = apfPortalEntries.data[id];
                if (!e) return;
                var html = '<table class="apf-portal-detail-table"><tbody>';
                Object.values(e.values).forEach(function(v) {
                    html += '<tr><td><strong>' + esc(v.label||v.field_id) + '</strong></td><td>' + esc(String(v.value||'')) + '</td></tr>';
                });
                html += '</tbody></table><p class="apf-portal-entry-meta">Submitted: ' + esc(e.created_at) + '</p>';
                detail.innerHTML = html;
                modal.style.display = 'flex';
            }
            function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
            document.querySelectorAll('.apf-portal-view-entry').forEach(function(btn){
                btn.addEventListener('click', function(e){ e.preventDefault(); openEntry(btn.dataset.id); });
            });
            document.querySelectorAll('.apf-portal-modal-close, .apf-portal-modal-overlay').forEach(function(el){
                el.addEventListener('click', function(){ modal.style.display='none'; });
            });
        });
        </script>

    <?php endif; // views ?>

</div><!-- .apf-portal-wrap -->

<!-- Shortcode copy -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.apf-portal-sc-copy').forEach(function(el){
        el.style.cursor = 'pointer';
        el.title = 'Click to copy';
        el.addEventListener('click', function(){
            navigator.clipboard.writeText(el.dataset.sc || el.textContent).then(function(){
                var orig = el.textContent;
                el.textContent = '✓ Copied!';
                el.style.color = '#16a34a';
                setTimeout(function(){ el.textContent = orig; el.style.color = ''; }, 1800);
            });
        });
    });
});
</script>
