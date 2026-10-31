<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$forms = APForms_Form::get_forms();
$notice = '';

// Handle bulk/quick actions
if ( isset( $_GET['apf_action'] ) && isset( $_GET['apf_id'] ) ) {
    if ( ! check_admin_referer( 'apforms_action' ) ) wp_die('Security check failed.');
    $fid = (int) $_GET['apf_id'];
    switch ( $_GET['apf_action'] ) {
        case 'delete':
            APForms_Form::delete_form( $fid );
            $notice = 'Form deleted.';
            break;
        case 'duplicate':
            $new_id = APForms_Form::duplicate_form( $fid );
            $notice = 'Form duplicated. <a href="' . admin_url( 'admin.php?page=apforms-builder&form_id=' . $new_id ) . '">Edit copy →</a>';
            break;
    }
    $forms = APForms_Form::get_forms();
}
?>
<div class="wrap apf-admin-wrap">
    <h1 class="wp-heading-inline">apForms</h1>
    <a href="<?php echo esc_url( admin_url('admin.php?page=apforms-builder') ); ?>" class="page-title-action">+ New Form</a>
    <hr class="wp-header-end">

    <?php if ( $notice ) : ?>
        <div class="notice notice-success is-dismissible"><p><?php echo wp_kses_post( $notice ); ?></p></div>
    <?php endif; ?>

    <?php if ( empty( $forms ) ) : ?>
        <div class="apf-empty-state">
            <div class="apf-empty-icon">📋</div>
            <h2>No forms yet</h2>
            <p>Create your first form to start collecting submissions.</p>
            <a href="<?php echo esc_url( admin_url('admin.php?page=apforms-builder') ); ?>" class="button button-primary button-hero">Create Your First Form</a>
        </div>
    <?php else : ?>
        <table class="wp-list-table widefat fixed striped apf-forms-table">
            <thead>
                <tr>
                    <th style="width:40px;">#</th>
                    <th>Form Title</th>
                    <th style="width:90px;">Fields</th>
                    <th style="width:100px;">Entries</th>
                    <th style="width:120px;">Status</th>
                    <th style="width:160px;">Created</th>
                    <th style="width:200px;">Shortcode</th>
                    <th style="width:180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $forms as $form ) :
                $entry_count = APForms_Form::get_entry_count( $form->id );
                $fields = is_array( $form->fields ) ? count( $form->fields ) : 0;
                $nonce  = wp_create_nonce( 'apforms_action' );
                $builder_url  = admin_url( 'admin.php?page=apforms-builder&form_id=' . $form->id );
                $entries_url  = admin_url( 'admin.php?page=apforms-entries&form_id=' . $form->id );
                $export_url   = admin_url( 'admin-ajax.php?action=apforms_export_entries&form_id=' . $form->id . '&nonce=' . wp_create_nonce('apforms_admin') );
                $delete_url   = admin_url( 'admin.php?page=apforms&apf_action=delete&apf_id=' . $form->id . '&_wpnonce=' . $nonce );
                $dup_url      = admin_url( 'admin.php?page=apforms&apf_action=duplicate&apf_id=' . $form->id . '&_wpnonce=' . $nonce );
            ?>
                <tr>
                    <td><?php echo esc_html( $form->id ); ?></td>
                    <td class="apf-form-title-cell">
                        <strong><a href="<?php echo esc_url( $builder_url ); ?>"><?php echo esc_html( $form->title ); ?></a></strong>
                        <?php if ( $form->description ) : ?>
                            <p class="apf-form-desc-preview"><?php echo esc_html( wp_trim_words( $form->description, 10 ) ); ?></p>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( $fields ); ?></td>
                    <td>
                        <?php if ( $entry_count > 0 ) : ?>
                            <a href="<?php echo esc_url( $entries_url ); ?>"><?php echo esc_html( $entry_count ); ?></a>
                        <?php else : ?>
                            <span style="color:#999;">0</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ( $form->status === 'active' ) : ?>
                            <span class="apf-badge apf-badge-active">Active</span>
                        <?php else : ?>
                            <span class="apf-badge apf-badge-inactive">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $form->created_at ) ) ); ?></td>
                    <td>
                        <code class="apf-shortcode-copy" data-shortcode='[apform id="<?php echo esc_attr( $form->id ); ?>"]'>[apform id="<?php echo esc_html( $form->id ); ?>"]</code>
                    </td>
                    <td class="apf-actions-cell">
                        <a href="<?php echo esc_url( $builder_url ); ?>" class="button button-small">Edit</a>
                        <?php if ( $entry_count > 0 ) : ?>
                            <a href="<?php echo esc_url( $entries_url ); ?>" class="button button-small">Entries</a>
                        <?php endif; ?>
                        <a href="<?php echo esc_url( $dup_url ); ?>" class="button button-small" title="Duplicate">⧉</a>
                        <a href="<?php echo esc_url( $delete_url ); ?>" class="button button-small apf-delete-btn" title="Delete" data-title="<?php echo esc_attr( $form->title ); ?>">🗑</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Shortcode click-to-copy
    document.querySelectorAll('.apf-shortcode-copy').forEach(function(el) {
        el.style.cursor = 'pointer';
        el.title = 'Click to copy';
        el.addEventListener('click', function() {
            navigator.clipboard.writeText(el.dataset.shortcode || el.textContent).then(function() {
                var orig = el.textContent;
                el.textContent = '✓ Copied!';
                el.style.color = '#00a32a';
                setTimeout(function() { el.textContent = orig; el.style.color = ''; }, 1800);
            });
        });
    });

    // Confirm delete
    document.querySelectorAll('.apf-delete-btn').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (!confirm('Delete form "' + btn.dataset.title + '" and all its entries? This cannot be undone.')) {
                e.preventDefault();
            }
        });
    });
});
</script>
