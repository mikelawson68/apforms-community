/**
 * apForms Builder — Drag-and-drop form builder
 * Requires: Sortable.js (loaded via wp_enqueue_script)
 */
(function($) {
    'use strict';

    /* ─── State ────────────────────────────────────────────────────── */
    var fields       = [];       // array of field objects
    var activeField  = null;     // field currently being edited
    var sortable     = null;     // Sortable instance
    var saving       = false;
    var formId       = apfBuilder.formId || 0;
    var nonce        = apfBuilder.nonce;
    var ajaxUrl      = apfBuilder.ajaxUrl;
    var fieldTypes   = apfBuilder.fieldTypes || {};
    var defaultsMap  = apfBuilder.defaultsMap || {};

    /* ─── Init ─────────────────────────────────────────────────────── */
    function init() {
        // Load existing fields
        if ( apfBuilder.fields && apfBuilder.fields.length ) {
            fields = apfBuilder.fields;
            renderAllFields();
        }

        initSortable();
        bindEvents();
    }

    /* ─── Sortable ─────────────────────────────────────────────────── */
    function initSortable() {
        var canvas = document.getElementById('apf-canvas');
        if (!canvas) return;
        sortable = Sortable.create(canvas, {
            handle: '.apf-field-handle',
            animation: 150,
            ghostClass: 'apf-field-ghost',
            onEnd: function(evt) {
                // Re-sync fields array order to DOM order
                var newOrder = [];
                canvas.querySelectorAll('.apf-canvas-field').forEach(function(el) {
                    var fid = el.dataset.fieldId;
                    var f = fields.find(function(x){ return x.id === fid; });
                    if (f) newOrder.push(f);
                });
                fields = newOrder;
            }
        });
    }

    /* ─── Render all fields to canvas ─────────────────────────────── */
    function renderAllFields() {
        var canvas = document.getElementById('apf-canvas');
        // Clear existing field elements (not the empty state)
        canvas.querySelectorAll('.apf-canvas-field').forEach(function(el){ el.remove(); });
        fields.forEach(function(f){ appendFieldCard(f); });
        updateEmptyState();
    }

    /* ─── Append a single field card ───────────────────────────────── */
    function appendFieldCard(field) {
        var canvas = document.getElementById('apf-canvas');
        var typeInfo = fieldTypes[field.type] || { label: field.type, icon: '?' };
        var el = document.createElement('div');
        el.className = 'apf-canvas-field';
        el.dataset.fieldId = field.id;

        var reqMark = field.required ? '<span class="apf-req-mark" title="Required">*</span>' : '';

        el.innerHTML =
            '<div class="apf-field-handle" title="Drag to reorder">⠿</div>' +
            '<div class="apf-field-info">' +
                '<span class="apf-field-type-icon">' + typeInfo.icon + '</span>' +
                '<span class="apf-field-label">' + esc(field.label || '(no label)') + '</span>' + reqMark +
                '<span class="apf-field-type-tag">' + esc(typeInfo.label || field.type) + '</span>' +
            '</div>' +
            '<div class="apf-field-actions">' +
                '<button class="apf-field-edit" title="Edit field settings" data-field-id="' + esc(field.id) + '">⚙️</button>' +
                '<button class="apf-field-dup"  title="Duplicate field"     data-field-id="' + esc(field.id) + '">⧉</button>' +
                '<button class="apf-field-del"  title="Delete field"        data-field-id="' + esc(field.id) + '">🗑</button>' +
            '</div>';

        canvas.appendChild(el);

        // Bind action buttons
        el.querySelector('.apf-field-edit').addEventListener('click', function(){ openEditor(field.id); });
        el.querySelector('.apf-field-dup').addEventListener('click',  function(){ duplicateField(field.id); });
        el.querySelector('.apf-field-del').addEventListener('click',  function(){ deleteField(field.id); });
        el.addEventListener('click', function(e){
            if (!e.target.closest('.apf-field-actions')) openEditor(field.id);
        });
    }

    /* ─── Add field from palette ───────────────────────────────────── */
    function addField(type) {
        var defaults = defaultsMap[type] ? JSON.parse(JSON.stringify(defaultsMap[type])) : { type: type };
        defaults.id  = 'field_' + Date.now() + '_' + Math.floor(Math.random()*9999);
        defaults.type = type;
        fields.push(defaults);
        appendFieldCard(defaults);
        updateEmptyState();
        openEditor(defaults.id);
    }

    /* ─── Duplicate field ──────────────────────────────────────────── */
    function duplicateField(fid) {
        var orig = fields.find(function(f){ return f.id === fid; });
        if (!orig) return;
        var copy = JSON.parse(JSON.stringify(orig));
        copy.id    = 'field_' + Date.now() + '_' + Math.floor(Math.random()*9999);
        copy.label = (copy.label || '') + ' (Copy)';
        var idx = fields.findIndex(function(f){ return f.id === fid; });
        fields.splice(idx + 1, 0, copy);
        renderAllFields();
    }

    /* ─── Delete field ─────────────────────────────────────────────── */
    function deleteField(fid) {
        if (!confirm('Delete this field?')) return;
        fields = fields.filter(function(f){ return f.id !== fid; });
        var el = document.querySelector('.apf-canvas-field[data-field-id="' + fid + '"]');
        if (el) el.remove();
        if (activeField && activeField.id === fid) closeEditor();
        updateEmptyState();
    }

    /* ─── Empty state visibility ───────────────────────────────────── */
    function updateEmptyState() {
        var empty = document.getElementById('apf-canvas-empty');
        if (!empty) return;
        empty.style.display = fields.length ? 'none' : '';
    }

    /* ─── Field Editor Panel ───────────────────────────────────────── */
    function openEditor(fid) {
        var field = fields.find(function(f){ return f.id === fid; });
        if (!field) return;
        activeField = field;

        var panel = document.getElementById('apf-editor-panel');
        var body  = document.getElementById('apf-editor-body');
        var title = document.getElementById('apf-editor-title');

        title.textContent = (fieldTypes[field.type] ? fieldTypes[field.type].label : field.type) + ' Settings';
        body.innerHTML = buildEditorHTML(field);
        panel.classList.add('open');

        // Highlight active card
        document.querySelectorAll('.apf-canvas-field').forEach(function(c){ c.classList.remove('active'); });
        var card = document.querySelector('.apf-canvas-field[data-field-id="' + fid + '"]');
        if (card) card.classList.add('active');

        // Bind conditional logic UI
        if (window.apformsConditionEditor) window.apformsConditionEditor.bind(field, { fields: fields, sel: sel, esc: esc });
        bindChoicesUI(field);
    }

    function closeEditor() {
        activeField = null;
        document.getElementById('apf-editor-panel').classList.remove('open');
        document.querySelectorAll('.apf-canvas-field').forEach(function(c){ c.classList.remove('active'); });
    }

    /* ─── Build editor form HTML ───────────────────────────────────── */
    function buildEditorHTML(field) {
        var type = field.type;
        var html = '';

        // Common: ID display
        html += row('Field ID', '<code class="apf-field-id-display">' + esc(field.id) + '</code>');

        // Common: Label (except section/html)
        if (type !== 'html') {
            html += row('Label', input('text', 'field-label', field.label || '', 'Field Label'));
        }

        // Common: Description (except html)
        if (type !== 'html') {
            html += row('Description', textarea('field-description', field.description || '', 'Optional helper text below the field'));
        }

        // Common: Required (non-layout, non-hidden)
        if (!['section','html','hidden'].includes(type)) {
            html += row('Required', checkboxInput('field-required', field.required, 'This field is required'));
        }

        // Common: CSS Class
        html += row('CSS Class', input('text', 'field-css-class', field.css_class || '', 'e.g. half-width'));

        // Type-specific
        switch(type) {
            case 'text':
            case 'email':
            case 'phone':
            case 'url':
                html += row('Placeholder', input('text', 'field-placeholder', field.placeholder || '', 'Placeholder text'));
                html += row('Max Length',  input('number', 'field-max-length', field.max_length || '', 'e.g. 255'));
                break;

            case 'textarea':
                html += row('Placeholder', input('text',   'field-placeholder', field.placeholder || '', 'Placeholder text'));
                html += row('Rows',        input('number', 'field-rows',        field.rows || 5, 'Default: 5'));
                break;

            case 'number':
                html += row('Min', input('number', 'field-min', field.min !== undefined ? field.min : '', 'Minimum value'));
                html += row('Max', input('number', 'field-max', field.max !== undefined ? field.max : '', 'Maximum value'));
                html += row('Step', input('number', 'field-step', field.step || '', 'e.g. 0.01'));
                break;

            case 'name':
                html += row('Show Prefix',      checkboxInput('field-show-prefix', field.show_prefix, 'Show prefix / honorific'));
                html += row('Show Middle Name', checkboxInput('field-show-middle', field.show_middle, 'Show middle name field'));
                break;

            case 'select':
            case 'radio':
            case 'checkbox':
                html += row('Choices', buildChoicesEditor(field));
                break;

            case 'date':
                html += row('Min Date', input('date', 'field-min-date', field.min_date || '', ''));
                html += row('Max Date', input('date', 'field-max-date', field.max_date || '', ''));
                break;

            case 'hidden':
                html += row('Default Value', input('text', 'field-default-value', field.default_value || '', 'Value to pre-fill'));
                break;

            case 'section':
                // Label already added above (acts as section title)
                break;

            case 'html':
                html += row('HTML Content', textarea('field-html-content', field.content || '', 'Enter HTML markup'));
                break;
        }

        var editor = (window.apformsFieldEditors || {})[type];
        if (editor && editor.render) html += editor.render(field, { row: row, input: input });

        if (window.apformsConditionEditor && !['section','html','hidden'].includes(type)) {
            html += window.apformsConditionEditor.render(field, { fields: fields, sel: sel, esc: esc });
        }

        return '<div class="apf-editor-fields">' + html + '</div>';
    }

    /* ─── Choice editor (select/radio/checkbox) ────────────────────── */
    function buildChoicesEditor(field) {
        var choices = Array.isArray(field.choices) ? field.choices : [];
        var html = '<div class="apf-choices-editor" id="apf-choices-list">';
        choices.forEach(function(c, i) {
            html += '<div class="apf-choice-row">' +
                '<span class="apf-choice-handle">⠿</span>' +
                '<input type="text" class="apf-choice-input" value="' + esc(c) + '" placeholder="Option ' + (i+1) + '">' +
                '<button class="apf-choice-del" type="button" title="Remove">✕</button>' +
                '</div>';
        });
        html += '</div><button type="button" class="button apf-add-choice">+ Add Choice</button>';
        return html;
    }

    function bindChoicesUI(field) {
        var list = document.getElementById('apf-choices-list');
        if (!list) return;

        // Sortable for choices
        Sortable.create(list, { handle: '.apf-choice-handle', animation: 100 });

        list.addEventListener('click', function(e) {
            if (e.target.classList.contains('apf-choice-del')) {
                e.target.closest('.apf-choice-row').remove();
            }
        });

        var addBtn = document.querySelector('.apf-add-choice');
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                var row = document.createElement('div');
                row.className = 'apf-choice-row';
                row.innerHTML = '<span class="apf-choice-handle">⠿</span>' +
                    '<input type="text" class="apf-choice-input" placeholder="New option">' +
                    '<button class="apf-choice-del" type="button" title="Remove">✕</button>';
                list.appendChild(row);
                row.querySelector('input').focus();
            });
        }
    }

    /* ─── Apply editor changes ──────────────────────────────────────── */
    function applyEditor() {
        if (!activeField) return;
        var field = activeField;

        // Common
        var labelEl = document.getElementById('field-label');
        if (labelEl) field.label = labelEl.value;

        var descEl = document.getElementById('field-description');
        if (descEl) field.description = descEl.value;

        var reqEl = document.getElementById('field-required');
        if (reqEl) field.required = reqEl.checked;

        var cssEl = document.getElementById('field-css-class');
        if (cssEl) field.css_class = cssEl.value;

        // Type-specific
        switch(field.type) {
            case 'text': case 'email': case 'phone': case 'url':
                field.placeholder = val('field-placeholder');
                field.max_length  = val('field-max-length');
                break;
            case 'textarea':
                field.placeholder = val('field-placeholder');
                field.rows        = parseInt(val('field-rows')) || 5;
                break;
            case 'number':
                field.min  = val('field-min');
                field.max  = val('field-max');
                field.step = val('field-step');
                break;
            case 'name':
                field.show_prefix = checked('field-show-prefix');
                field.show_middle = checked('field-show-middle');
                break;
            case 'select': case 'radio': case 'checkbox':
                var choiceInputs = document.querySelectorAll('.apf-choice-input');
                field.choices = [];
                choiceInputs.forEach(function(inp){ if(inp.value.trim()) field.choices.push(inp.value.trim()); });
                break;
            case 'date':
                field.min_date = val('field-min-date');
                field.max_date = val('field-max-date');
                break;
            case 'hidden':
                field.default_value = val('field-default-value');
                break;
            case 'html':
                field.content = val('field-html-content');
                break;
        }

        var editor = (window.apformsFieldEditors || {})[field.type];
        if (editor && editor.save) editor.save(field, { val: val });

        if (window.apformsConditionEditor) window.apformsConditionEditor.save(field);

        // Update fields array
        var idx = fields.findIndex(function(f){ return f.id === field.id; });
        if (idx !== -1) fields[idx] = field;

        // Re-render this card
        var card = document.querySelector('.apf-canvas-field[data-field-id="' + field.id + '"]');
        if (card) {
            var oldCard = card;
            var parent  = card.parentNode;
            var next    = card.nextSibling;
            parent.removeChild(oldCard);

            var tmpDiv = document.createElement('div');
            // We'll re-render by creating a new card
            var newCard = document.createElement('div');
            newCard.className = 'apf-canvas-field active';
            newCard.dataset.fieldId = field.id;
            var typeInfo = fieldTypes[field.type] || { label: field.type, icon: '?' };
            var reqMark = field.required ? '<span class="apf-req-mark" title="Required">*</span>' : '';
            newCard.innerHTML =
                '<div class="apf-field-handle" title="Drag to reorder">⠿</div>' +
                '<div class="apf-field-info">' +
                    '<span class="apf-field-type-icon">' + typeInfo.icon + '</span>' +
                    '<span class="apf-field-label">' + esc(field.label || '(no label)') + '</span>' + reqMark +
                    '<span class="apf-field-type-tag">' + esc(typeInfo.label || field.type) + '</span>' +
                '</div>' +
                '<div class="apf-field-actions">' +
                    '<button class="apf-field-edit" title="Edit" data-field-id="' + esc(field.id) + '">⚙️</button>' +
                    '<button class="apf-field-dup"  title="Duplicate" data-field-id="' + esc(field.id) + '">⧉</button>' +
                    '<button class="apf-field-del"  title="Delete" data-field-id="' + esc(field.id) + '">🗑</button>' +
                '</div>';

            if (next) parent.insertBefore(newCard, next);
            else parent.appendChild(newCard);

            newCard.querySelector('.apf-field-edit').addEventListener('click', function(){ openEditor(field.id); });
            newCard.querySelector('.apf-field-dup').addEventListener('click',  function(){ duplicateField(field.id); });
            newCard.querySelector('.apf-field-del').addEventListener('click',  function(){ deleteField(field.id); });
            newCard.addEventListener('click', function(e){
                if (!e.target.closest('.apf-field-actions')) openEditor(field.id);
            });
        }
    }

    /* ─── Save form ─────────────────────────────────────────────────── */
    function saveForm() {
        if (saving) return;
        var title = document.getElementById('apf-form-title').value.trim();
        if (!title) {
            alert('Please enter a form title.');
            document.getElementById('apf-form-title').focus();
            return;
        }

        saving = true;
        var btn = document.getElementById('apf-save-btn');
        btn.textContent = '⏳ Saving…';
        btn.disabled = true;

        var payload = {
            id:          formId || undefined,
            title:       title,
            description: document.getElementById('apf-form-description') ? document.getElementById('apf-form-description').value : '',
            status:      document.getElementById('apf-form-status').value,
            fields:      fields,
            settings: {
                submit_label:         (document.getElementById('apf-submit-label')  || {}).value || 'Submit',
                confirmation_message: (document.getElementById('apf-confirm-msg')    || {}).value || '',
                limit_one_submission: !!(document.getElementById('apf-limit-one') || {}).checked

            },
        };

        if (document.getElementById('apf-ai-action-enabled')) {
            payload.settings.ai = {
                    enabled: !!((document.getElementById('apf-ai-action-enabled') || {}).checked),
                    mode: (document.getElementById('apf-ai-action-mode') || {}).value || 'summarize',
                    field_ids: ((document.getElementById('apf-ai-field-ids') || {}).value || '').split(',').map(function(v){ return v.trim(); }).filter(Boolean),
                    consent_field_id: (document.getElementById('apf-ai-consent-field-id') || {}).value || ''
                };
        }

        $.ajax({
            url:         ajaxUrl + '?action=apforms_save_form&nonce=' + encodeURIComponent(nonce),
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(payload),
            success: function(res) {
                if (res.success) {
                    var newId = res.data.id;
                    if (!formId) {
                        // Redirect to edit URL with new ID
                        window.location.href = window.location.href + '&form_id=' + newId;
                        return;
                    }
                    showNotice('Form saved!', 'success');
                } else {
                    showNotice('Error: ' + (res.data || 'Unknown error'), 'error');
                }
            },
            error: function() {
                showNotice('Network error. Please try again.', 'error');
            },
            complete: function() {
                saving = false;
                btn.textContent = '💾 Save Form';
                btn.disabled = false;
            }
        });
    }

    /* ─── Notification modal ────────────────────────────────────────── */
    function openNotifModal(notifData) {
        var modal = document.getElementById('apf-notif-modal');
        document.getElementById('apf-notif-modal-title').textContent = notifData ? 'Edit Notification' : 'Add Notification';
        document.getElementById('apf-notif-id').value          = notifData ? notifData.id       : '';
        document.getElementById('apf-notif-name').value        = notifData ? (notifData.name     || '') : '';
        document.getElementById('apf-notif-active').checked    = notifData ? !!notifData.is_active : true;
        document.getElementById('apf-notif-to-type').value     = notifData ? (notifData.to_type   || 'admin') : 'admin';
        document.getElementById('apf-notif-to-email').value    = notifData ? (notifData.to_email  || '') : '';
        document.getElementById('apf-notif-from-name').value   = notifData ? (notifData.from_name || '') : '';
        document.getElementById('apf-notif-from-email').value  = notifData ? (notifData.from_email|| '') : '';
        document.getElementById('apf-notif-reply-to').value    = notifData ? (notifData.reply_to  || '') : '';
        document.getElementById('apf-notif-subject').value     = notifData ? (notifData.subject   || '') : 'New Submission: {form_title}';
        document.getElementById('apf-notif-body').value        = notifData ? (notifData.body      || '') : '{all_fields}';
        toggleNotifToField(document.getElementById('apf-notif-to-type').value);
        modal.style.display = 'flex';
    }

    function toggleNotifToField(toType) {
        document.getElementById('apf-notif-to-email-row').style.display = (toType === 'admin' || toType === 'custom') ? '' : 'none';
        document.getElementById('apf-notif-to-field-row').style.display = (toType === 'field') ? '' : 'none';
    }

    function saveNotification() {
        var data = {
            action:     'apforms_save_notification',
            nonce:      nonce,
            id:         document.getElementById('apf-notif-id').value || undefined,
            form_id:    formId,
            name:       document.getElementById('apf-notif-name').value,
            is_active:  document.getElementById('apf-notif-active').checked ? 1 : 0,
            to_type:    document.getElementById('apf-notif-to-type').value,
            to_email:   document.getElementById('apf-notif-to-email').value,
            to_field:   document.getElementById('apf-notif-to-field') ? document.getElementById('apf-notif-to-field').value : '',
            from_name:  document.getElementById('apf-notif-from-name').value,
            from_email: document.getElementById('apf-notif-from-email').value,
            reply_to:   document.getElementById('apf-notif-reply-to').value,
            subject:    document.getElementById('apf-notif-subject').value,
            body:       document.getElementById('apf-notif-body').value,
        };
        $.ajax({
            url:         ajaxUrl + '?action=apforms_save_notification&nonce=' + encodeURIComponent(nonce),
            method:      'POST',
            contentType: 'application/json',
            data:        JSON.stringify(data),
            success: function(res) {
                if (res.success) {
                    document.getElementById('apf-notif-modal').style.display = 'none';
                    showNotice('Notification saved.', 'success');
                    // Refresh notification list
                    location.reload();
                } else {
                    alert('Error saving notification.');
                }
            }
        });
    }

    /* ─── Bind all events ───────────────────────────────────────────── */
    function bindEvents() {
        // Palette buttons
        document.querySelectorAll('.apf-palette-btn').forEach(function(btn) {
            btn.addEventListener('click', function() { addField(btn.dataset.type); });
        });

        // Save button
        document.getElementById('apf-save-btn').addEventListener('click', function() {
            applyEditor(); // save any open editor changes
            saveForm();
        });

        var previewBtn = document.getElementById('apf-preview-btn');
        if (previewBtn) previewBtn.addEventListener('click', function() {
            applyEditor();
            openPreview();
        });
        document.querySelectorAll('[data-apf-preview-close]').forEach(function(el) {
            el.addEventListener('click', closePreview);
        });

        // Editor close
        document.getElementById('apf-editor-close').addEventListener('click', closeEditor);

        // Editor apply button
        document.getElementById('apf-editor-save').addEventListener('click', function() {
            applyEditor();
            closeEditor();
        });

        // Editor delete button
        document.getElementById('apf-editor-delete').addEventListener('click', function() {
            if (activeField) deleteField(activeField.id);
        });

        // Tabs
        document.querySelectorAll('.apf-tab').forEach(function(tab) {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.apf-tab').forEach(function(t){ t.classList.remove('active'); });
                document.querySelectorAll('.apf-tab-content').forEach(function(c){ c.classList.remove('active'); });
                tab.classList.add('active');
                var tabContent = document.getElementById('apf-tab-' + tab.dataset.tab);
                if (tabContent) tabContent.classList.add('active');
            });
        });

        // Notifications
        var addNotifBtn = document.getElementById('apf-add-notif');
        if (addNotifBtn) addNotifBtn.addEventListener('click', function() { openNotifModal(null); });

        document.querySelectorAll('.apf-edit-notif').forEach(function(btn) {
            btn.addEventListener('click', function() {
                // fetch notification data and open modal
                $.getJSON(ajaxUrl + '?action=apforms_get_form&id=' + formId + '&nonce=' + nonce, function(res) {
                    if (res.success) {
                        var n = (res.data.notifications || []).find(function(x){ return x.id == btn.dataset.id; });
                        if (n) openNotifModal(n);
                    }
                });
            });
        });

        document.querySelectorAll('.apf-delete-notif').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!confirm('Delete this notification?')) return;
                $.ajax({
                    url:         ajaxUrl,
                    method:      'POST',
                    data:        { action: 'apforms_delete_notification', nonce: nonce, id: btn.dataset.id },
                    success: function(res) { if (res.success) { btn.closest('.apf-notif-item').remove(); showNotice('Notification deleted.', 'success'); } }
                });
            });
        });

        document.getElementById('apf-notif-save-btn').addEventListener('click', saveNotification);

        document.querySelectorAll('.apf-modal-close, .apf-modal-overlay').forEach(function(el) {
            el.addEventListener('click', function() {
                document.querySelectorAll('.apf-modal').forEach(function(m){ m.style.display = 'none'; });
            });
        });

        document.getElementById('apf-notif-to-type').addEventListener('change', function() {
            toggleNotifToField(this.value);
        });

        // Keyboard: Ctrl/Cmd+S to save
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                applyEditor();
                saveForm();
            }
        });
    }

    /* ─── Helpers ────────────────────────────────────────────────────── */
    function row(label, content) {
        return '<div class="apf-editor-row"><label class="apf-editor-label">' + label + '</label><div class="apf-editor-control">' + content + '</div></div>';
    }
    function input(type, id, value, placeholder) {
        return '<input type="' + type + '" id="' + id + '" value="' + esc(String(value)) + '" placeholder="' + esc(placeholder||'') + '" class="apf-editor-input">';
    }
    function textarea(id, value, placeholder) {
        return '<textarea id="' + id + '" placeholder="' + esc(placeholder||'') + '" rows="3" class="apf-editor-textarea">' + esc(value) + '</textarea>';
    }
    function checkboxInput(id, checked, label) {
        return '<label><input type="checkbox" id="' + id + '" ' + (checked ? 'checked' : '') + '> ' + esc(label) + '</label>';
    }
    function val(id) {
        var el = document.getElementById(id);
        return el ? el.value : '';
    }
    function checked(id) {
        var el = document.getElementById(id);
        return el ? el.checked : false;
    }
    function sel(a, b) { return a === b ? 'selected' : ''; }
    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function showNotice(msg, type) {
        var el = document.getElementById('apf-builder-notice');
        el.className = 'notice notice-' + (type === 'error' ? 'error' : 'success') + ' is-dismissible';
        el.querySelector('p').innerHTML = msg;
        el.style.display = '';
        if (type !== 'error') setTimeout(function(){ el.style.display = 'none'; }, 4000);
    }

    /* ─── Front-end preview ─────────────────────────────────────────── */
    function previewControl(field) {
        var required = field.required ? ' required' : '';
        var placeholder = field.placeholder ? ' placeholder="' + esc(field.placeholder) + '"' : '';
        var choices = Array.isArray(field.choices) ? field.choices : [];
        if (field.type === 'textarea') return '<textarea' + required + placeholder + ' rows="' + (field.rows || 5) + '"></textarea>';
        if (field.type === 'select') return '<select' + required + '><option value="">Select an option</option>' + choices.map(function(choice){ return '<option>' + esc(choice) + '</option>'; }).join('') + '</select>';
        if (field.type === 'radio' || field.type === 'checkbox') return '<div class="apf-preview-choices">' + choices.map(function(choice, i){ return '<label><input type="' + field.type + '" ' + (field.type === 'radio' ? 'name="' + esc(field.id) + '" ' : '') + 'value="' + esc(choice) + '"> ' + esc(choice) + '</label>'; }).join('') + '</div>';
        if (field.type === 'section') return '<h3>' + esc(field.label || 'Section') + '</h3>' + (field.description ? '<p>' + esc(field.description) + '</p>' : '');
        if (field.type === 'html') return '<div class="apf-preview-html">' + (field.content || '') + '</div>';
        if (field.type === 'hidden') return '';
        var typeMap = { email: 'email', phone: 'tel', number: 'number', url: 'url', date: 'date', time: 'time', file: 'file' };
        if (field.type === 'name') return '<div class="apf-preview-name"><input type="text" placeholder="First name"' + required + '><input type="text" placeholder="Last name"' + required + '></div>';
        return '<input type="' + (typeMap[field.type] || 'text') + '"' + required + placeholder + '>';
    }
    function openPreview() {
        var modal = document.getElementById('apf-preview-modal');
        var content = document.getElementById('apf-preview-content');
        if (!modal || !content) return;
        var title = val('apf-form-title') || 'Untitled Form';
        var description = val('apf-form-description');
        var submit = val('apf-submit-label') || 'Submit';
        content.innerHTML = '<div class="apf-public-preview"><h2>' + esc(title) + '</h2>' + (description ? '<p class="apf-preview-description">' + esc(description) + '</p>' : '') + '<form novalidate>' + fields.map(function(field) {
            if (field.type === 'section' || field.type === 'html' || field.type === 'hidden') return '<div class="apf-preview-field apf-preview-' + esc(field.type) + '">' + previewControl(field) + '</div>';
            return '<div class="apf-preview-field"><label>' + esc(field.label || 'Untitled field') + (field.required ? ' <b>*</b>' : '') + '</label>' + (field.description ? '<small>' + esc(field.description) + '</small>' : '') + previewControl(field) + '</div>';
        }).join('') + '<button type="submit">' + esc(submit) + '</button></form><p class="apf-preview-note">Preview only — no submission is sent.</p></div>';
        var form = content.querySelector('form');
        if (form) form.addEventListener('submit', function(event){ event.preventDefault(); });
        modal.style.display = 'block';
        document.body.classList.add('apf-preview-open');
    }
    function closePreview() {
        var modal = document.getElementById('apf-preview-modal');
        if (modal) modal.style.display = 'none';
        document.body.classList.remove('apf-preview-open');
    }

    /* ─── Boot ──────────────────────────────────────────────────────── */
    $(document).ready(init);

})(jQuery);
