<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$form_id = (int) ( $_GET['form_id'] ?? 0 );
$page    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per     = 25;

if ( ! $form_id ) {
    echo '<div class="wrap"><p>No form selected. <a href="' . esc_url( admin_url('admin.php?page=apforms') ) . '">← Back to Forms</a></p></div>';
    return;
}

$form   = APForms_Form::get_form( $form_id );
if ( ! $form ) {
    echo '<div class="wrap"><p>Form not found.</p></div>';
    return;
}

$result  = APForms_Entry::get_entries( $form_id, ['per_page' => $per, 'offset' => ($page-1)*$per] );
$entries = $result['rows'] ?? [];
$total   = $result['total'] ?? 0;
$pages   = ceil( $total / $per );

// Handle delete
if ( isset($_GET['apf_action']) && $_GET['apf_action'] === 'delete_entry' && isset($_GET['entry_id']) ) {
    if ( check_admin_referer('apforms_action') ) {
        APForms_Entry::delete_entry( (int) $_GET['entry_id'] );
        wp_safe_redirect( admin_url('admin.php?page=apforms-entries&form_id=' . $form_id ) );
        exit;
    }
}

$nonce       = wp_create_nonce('apforms_admin');
$export_url  = admin_url('admin-ajax.php?action=apforms_export_entries&form_id=' . $form_id . '&nonce=' . $nonce);
$builder_url = admin_url('admin.php?page=apforms-builder&form_id=' . $form_id);

// Collect visible fields (non-layout)
$visible_fields = array_filter( $form->fields, fn($f) => ! in_array( $f['type'], ['section','html','hidden'] ) );
$visible_fields = array_values( $visible_fields );
$col_limit      = 5; // max columns to show in table
$show_fields    = array_slice( $visible_fields, 0, $col_limit );
?>
<div class="wrap apf-admin-wrap apf-entries-wrap">
    <h1 class="wp-heading-inline">Entries: <?php echo esc_html($form->title); ?></h1>
    <a href="<?php echo esc_url($builder_url); ?>" class="page-title-action">✏️ Edit Form</a>
    <a href="<?php echo esc_url($export_url); ?>" class="page-title-action">⬇️ Export CSV</a>
    <a href="<?php echo esc_url( admin_url('admin.php?page=apforms') ); ?>" class="page-title-action">← All Forms</a>
    <hr class="wp-header-end">

    <?php if ( empty($entries) ) : ?>
        <div class="apf-empty-state">
            <div class="apf-empty-icon">📭</div>
            <h2>No entries yet</h2>
            <p>When someone submits this form, entries will appear here.</p>
            <p><strong>Shortcode:</strong> <code>[apform id="<?php echo esc_html($form_id); ?>"]</code></p>
        </div>
    <?php else : ?>
        <p class="apf-entry-count">Showing <?php echo count($entries); ?> of <?php echo esc_html($total); ?> entries.</p>

        <table class="wp-list-table widefat fixed striped apf-entries-table">
            <thead>
                <tr>
                    <th style="width:50px;">#</th>
                    <th style="width:140px;">Date</th>
                    <?php foreach ( $show_fields as $sf ) : ?>
                        <th><?php echo esc_html( $sf['label'] ); ?></th>
                    <?php endforeach; ?>
                    <?php if ( count($visible_fields) > $col_limit ) : ?>
                        <th style="width:60px;">More</th>
                    <?php endif; ?>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $entries as $entry ) :
                $del_url = admin_url('admin.php?page=apforms-entries&form_id=' . $form_id . '&apf_action=delete_entry&entry_id=' . $entry->id . '&_wpnonce=' . wp_create_nonce('apforms_action'));
            ?>
                <tr class="apf-entry-row" data-entry-id="<?php echo esc_attr($entry->id); ?>">
                    <td><?php echo esc_html($entry->id); ?></td>
                    <td>
                        <span title="<?php echo esc_attr($entry->created_at); ?>">
                            <?php echo esc_html( date_i18n('M j, Y g:i a', strtotime($entry->created_at)) ); ?>
                        </span>
                    </td>
                    <?php foreach ( $show_fields as $sf ) :
                        $fval = $entry->values[$sf['id']]['value'] ?? '';
                        $display = is_array($fval) ? implode(', ', $fval) : $fval;
                    ?>
                        <td class="apf-entry-cell" title="<?php echo esc_attr($display); ?>">
                            <?php echo esc_html( wp_trim_words( $display, 8, '…' ) ); ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ( count($visible_fields) > $col_limit ) : ?>
                        <td><a href="#" class="apf-view-entry" data-id="<?php echo esc_attr($entry->id); ?>">View</a></td>
                    <?php endif; ?>
                    <td>
                        <a href="#" class="apf-view-entry button button-small" data-id="<?php echo esc_attr($entry->id); ?>">View</a>
                        <a href="<?php echo esc_url($del_url); ?>" class="button button-small apf-delete-entry-btn" title="Delete">🗑</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo wp_kses_post( paginate_links([
                        'base'    => add_query_arg('paged', '%#%'),
                        'format'  => '',
                        'current' => $page,
                        'total'   => $pages,
                        'type'    => 'plain',
                    ]) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Entry detail modal -->
<div id="apf-entry-modal" class="apf-modal" style="display:none;">
    <div class="apf-modal-overlay"></div>
    <div class="apf-modal-box apf-entry-modal-box">
        <div class="apf-modal-header">
            <h3>Entry Detail</h3>
            <button class="apf-modal-close">✕</button>
        </div>
        <div class="apf-modal-body" id="apf-entry-detail">
            <!-- Populated via JS -->
        </div>
        <div class="apf-modal-footer">
            <button class="apf-modal-close button">Close</button>
        </div>
    </div>
</div>

<script>
var apfEntries = {
    ajaxUrl: '<?php echo esc_js( admin_url("admin-ajax.php") ); ?>',
    nonce:   '<?php echo esc_js($nonce); ?>',
    formId:  <?php echo (int) $form_id; ?>,
    downloadUrl: <?php echo wp_json_encode( admin_url( 'admin-post.php' ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>,
    // Pre-load entry data for client-side display
    data: <?php
        $entry_map = [];
        foreach ( $entries as $e ) {
            $entry_map[$e->id] = [
                'id'         => $e->id,
                'created_at' => $e->created_at,
                'ip'         => $e->ip,
                'values'     => $e->values,
            ];
        }
        echo wp_json_encode( $entry_map, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
    ?>
};

document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('apf-entry-modal');
    var detail = document.getElementById('apf-entry-detail');

    function openEntry(id) {
        var e = apfEntries.data[id];
        if (!e) return;
        var html = '<table class="apf-entry-detail-table"><thead><tr><th>Field</th><th>Value</th></tr></thead><tbody>';
        Object.values(e.values).forEach(function(v) {
            html += '<tr><td><strong>' + escHtml(v.label || v.field_id) + '</strong></td><td>' + displayValue(String(v.value || '')) + '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<p class="apf-entry-meta">Submitted: ' + escHtml(e.created_at) + (e.ip ? ' &nbsp;|&nbsp; IP: ' + escHtml(e.ip) : '') + '</p>';
        detail.innerHTML = html;
        modal.style.display = 'flex';
    }

    function displayValue(value) {
        var endpoint = new URL(apfEntries.downloadUrl, window.location.href);
        return value.split(', ').map(function(part) {
            try {
                var link = new URL(part);
                if (link.origin === endpoint.origin && link.pathname === endpoint.pathname && link.searchParams.get('action') === 'apforms_download') {
                    return '<a href="' + escHtml(link.href) + '">Download attachment</a>';
                }
            } catch (error) {}
            return escHtml(part);
        }).join(', ');
    }

    function escHtml(s) {
        return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    document.querySelectorAll('.apf-view-entry').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            openEntry(btn.dataset.id);
        });
    });

    document.querySelectorAll('.apf-modal-close, .apf-modal-overlay').forEach(function(el) {
        el.addEventListener('click', function() { modal.style.display = 'none'; });
    });

    document.querySelectorAll('.apf-delete-entry-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Delete this entry? This cannot be undone.')) e.preventDefault();
        });
    });
});
</script>
