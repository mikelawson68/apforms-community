/**
 * apForms Public — AJAX form submission, conditional logic, file validation
 */
(function() {
    'use strict';

    var ajaxUrl  = (typeof apformsPublic !== 'undefined') ? apformsPublic.ajaxUrl : '/wp-admin/admin-ajax.php';

    /* ─── Init all forms on page ─────────────────────────────────── */
    function init() {
        document.querySelectorAll('.apf-form').forEach(function(form) {
            initForm(form);
        });
    }

    function initForm(form) {
        var formId = form.querySelector('[name="apf_form_id"]');
        if (!formId) return;

        // Evaluate initial conditional logic state
        if (window.apformsEvaluateConditions) window.apformsEvaluateConditions(form);

        // Live conditional logic on change/input
        form.addEventListener('change', function() { if (window.apformsEvaluateConditions) window.apformsEvaluateConditions(form); });
        form.addEventListener('input',  function() { if (window.apformsEvaluateConditions) window.apformsEvaluateConditions(form); });

        // Submit handler
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitForm(form);
        });

        // File input previews
        form.querySelectorAll('input[type="file"]').forEach(function(fileInput) {
            fileInput.addEventListener('change', function() {
                handleFilePreview(fileInput);
            });
        });
    }

    /* ─── AJAX Submit ─────────────────────────────────────────────── */
    function submitForm(form) {
        var wrap  = form.closest('.apf-form-wrap');
        var fid   = form.querySelector('[name="apf_form_id"]').value;
        var btn   = form.querySelector('.apf-submit-btn');
        var errEl = wrap ? wrap.querySelector('.apf-error-summary') : null;

        // Clear previous errors
        form.querySelectorAll('.apf-field-error').forEach(function(e){ e.remove(); });
        if (errEl) { errEl.innerHTML = ''; errEl.style.display = 'none'; }

        // Client-side required validation (visible fields only)
        var errors = [];
        form.querySelectorAll('.apf-field').forEach(function(fieldWrap) {
            // Skip hidden fields (conditional logic)
            if (fieldWrap.style.display === 'none' || fieldWrap.classList.contains('apf-cond-hidden')) return;

            var inputs = fieldWrap.querySelectorAll('input[required], select[required], textarea[required]');
            inputs.forEach(function(inp) {
                var empty = false;
                if (inp.type === 'checkbox' || inp.type === 'radio') {
                    // Check if any in the group is checked
                    var name = inp.name;
                    var group = form.querySelectorAll('[name="' + name + '"]');
                    var anyChecked = false;
                    group.forEach(function(g){ if(g.checked) anyChecked = true; });
                    if (!anyChecked) empty = true;
                } else {
                    empty = !inp.value.trim();
                }
                if (empty) {
                    var label = fieldWrap.querySelector('.apf-label');
                    var labelText = label ? label.textContent.replace('*','').trim() : 'Field';
                    errors.push(labelText + ' is required.');
                    fieldWrap.classList.add('apf-has-error');
                    var errDiv = document.createElement('div');
                    errDiv.className = 'apf-field-error';
                    errDiv.setAttribute('role', 'alert');
                    errDiv.textContent = labelText + ' is required.';
                    var lastInput = fieldWrap.querySelector('input, select, textarea');
                    if (lastInput && lastInput.parentNode) {
                        lastInput.parentNode.insertBefore(errDiv, lastInput.nextSibling);
                    }
                }
            });
        });

        if (errors.length) {
            if (errEl) {
                errEl.innerHTML = '<strong>Please fix the following:</strong><ul>' +
                    errors.map(function(e){ return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>';
                errEl.style.display = '';
                errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            return;
        }

        // Disable button
        if (btn) {
            btn.disabled = true;
            btn.dataset.origText = btn.textContent;
            btn.textContent = 'Submitting…';
        }

        var formData = new FormData(form);
        formData.append('action', 'apforms_submit');

        fetch(ajaxUrl, {
            method: 'POST',
            body:   formData,
            credentials: 'same-origin',
        })
        .then(function(r){ return r.json(); })
        .then(function(res) {
            if (res.success) {
                form.style.display = 'none';

                var confirmEl = wrap ? wrap.querySelector('#apf-confirm-' + fid) : null;
                if (confirmEl) {
                    confirmEl.innerHTML = '<div class="apf-success-message">Submission Successful!</div>';
                    confirmEl.style.display = '';
                    confirmEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }

                if (res.data && res.data.redirect) {
                    setTimeout(function() {
                        window.location.href = res.data.redirect;
                    }, 1200);
                }
            } else {
                // Server-side errors
                var serverErrors = res.data ? (res.data.errors || {}) : {};
                var msg = (res.data && res.data.message) ? res.data.message : 'An error occurred.';
                Object.keys(serverErrors).forEach(function(fieldId) {
                    var fieldWrap = form.querySelector('#wrap_apf_' + fieldId);
                    if (fieldWrap) {
                        fieldWrap.classList.add('apf-has-error');
                        var errDiv = document.createElement('div');
                        errDiv.className = 'apf-field-error';
                        errDiv.setAttribute('role', 'alert');
                        errDiv.textContent = serverErrors[fieldId];
                        var inp = fieldWrap.querySelector('input, select, textarea');
                        if (inp) inp.parentNode.insertBefore(errDiv, inp.nextSibling);
                    }
                });
                if (errEl) {
                    errEl.textContent = msg;
                    errEl.style.display = '';
                    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = btn.dataset.origText || 'Submit';
                }
            }
        })
        .catch(function() {
            if (errEl) {
                errEl.textContent = 'Network error. Please check your connection and try again.';
                errEl.style.display = '';
            }
            if (btn) {
                btn.disabled = false;
                btn.textContent = btn.dataset.origText || 'Submit';
            }
        });
    }

    /* ─── File preview ────────────────────────────────────────────── */
    function handleFilePreview(input) {
        var wrap = input.closest('.apf-field');
        if (!wrap) return;

        // Remove previous preview
        var existing = wrap.querySelector('.apf-file-preview');
        if (existing) existing.remove();

        if (!input.files || !input.files.length) return;

        var preview = document.createElement('div');
        preview.className = 'apf-file-preview';

        Array.from(input.files).forEach(function(file) {
            var item = document.createElement('div');
            item.className = 'apf-file-preview-item';
            item.textContent = file.name + ' (' + formatBytes(file.size) + ')';
            preview.appendChild(item);
        });

        input.parentNode.insertBefore(preview, input.nextSibling);
    }

    /* ─── Helpers ─────────────────────────────────────────────────── */
    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / 1048576).toFixed(1) + ' MB';
    }

    function esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ─── Boot ────────────────────────────────────────────────────── */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
