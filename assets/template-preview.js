/* Synthetic-only previews. Never submit/save the post form or render HTML in the parent document. */
(function () {
    'use strict';
    jQuery(function () {
        var config = window.adfTemplatePreviewSettings || {};
        var run = document.getElementById('adf-preview-run');
        var html = document.getElementById('adf-template-html');
        var subject = document.getElementById('adf-template-subject');
        var data = document.getElementById('adf-preview-fields');
        var frame = document.getElementById('adf-template-preview');
        var status = document.getElementById('adf-preview-status');
        var diagnostics = document.getElementById('adf-preview-diagnostics');
        var buttons = document.getElementById('adf-placeholder-buttons');
        var target = document.getElementById('adf-insert-target');
        var renderedSubject = document.getElementById('adf-preview-subject');
        if (!run || !html || !subject || !data || !frame || !buttons) return;
        var editor = null;
        if (config.codeEditor && window.wp && wp.codeEditor) {
            editor = wp.codeEditor.initialize(html, config.codeEditor).codemirror;
            editor.on('change', function () { editor.save(); });
        }
        function text(key, fallback) { return (config.strings || {})[key] || fallback; }
        function renderButtons(tokens) {
            buttons.replaceChildren();
            tokens.forEach(function (token) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'button';
                button.dataset.adfPlaceholder = token;
                button.textContent = token;
                button.setAttribute('aria-controls', target.value === 'subject' ? subject.id : html.id);
                buttons.appendChild(button);
            });
        }
        function refreshButtons() {
            var tokens = ['{{form.summary}}'];
            try {
                var fields = JSON.parse(data.value);
                if (fields && typeof fields === 'object' && !Array.isArray(fields)) {
                    Object.keys(fields).slice(0, 50).forEach(function (id) {
                        if (/^[a-zA-Z0-9_-]+$/.test(id)) tokens.push('{{fields.' + id + '}}');
                    });
                }
            } catch (error) { /* The server returns bounded, localized JSON diagnostics on explicit preview. */ }
            renderButtons(tokens);
        }
        buttons.addEventListener('click', function (event) {
            var button = event.target.closest('button[data-adf-placeholder]');
            if (!button || !buttons.contains(button)) return;
            var token = button.dataset.adfPlaceholder;
            var intoSubject = target.value === 'subject' && token !== '{{form.summary}}';
            if (intoSubject) {
                subject.setRangeText(token, subject.selectionStart, subject.selectionEnd, 'end');
                subject.focus();
                subject.dispatchEvent(new Event('input', {bubbles: true}));
            } else if (editor) {
                editor.replaceSelection(token);
                editor.focus();
                editor.save();
            } else {
                html.setRangeText(token, html.selectionStart, html.selectionEnd, 'end');
                html.focus();
                html.dispatchEvent(new Event('input', {bubbles: true}));
            }
        });
        data.addEventListener('input', refreshButtons);
        target.addEventListener('change', refreshButtons);
        function showDiagnostics(result) {
            diagnostics.replaceChildren();
            [['missing_fields', 'missing'], ['unknown_placeholders', 'unknown'], ['attribute_placeholders', 'attributes']].forEach(function (pair) {
                if (Array.isArray(result[pair[0]]) && result[pair[0]].length) {
                    var item = document.createElement('li');
                    item.textContent = text(pair[1], pair[1]) + ' ' + result[pair[0]].join(', ');
                    diagnostics.appendChild(item);
                }
            });
            if (result.subject_body_only) {
                var item = document.createElement('li');
                item.textContent = text('bodyOnly', '{{form.summary}} is body-only.');
                diagnostics.appendChild(item);
            }
        }
        run.addEventListener('click', async function () {
            run.disabled = true;
            status.classList.remove('adf-preview-error');
            status.textContent = text('working', 'Rendering test data…');
            diagnostics.replaceChildren();
            frame.srcdoc = '';
            renderedSubject.textContent = '';
            try {
                var body = new URLSearchParams({
                    action: 'adf_preview_template',
                    template_id: run.dataset.templateId,
                    nonce: run.dataset.previewNonce,
                    subject: subject.value,
                    html: editor ? editor.getValue() : html.value,
                    fields_json: data.value
                });
                var response = await fetch(config.ajaxUrl, {method: 'POST', credentials: 'same-origin', body: body});
                var result = await response.json();
                var payload = result.data || {};
                showDiagnostics(payload);
                if (!response.ok || !result.success) throw new Error(payload.message || text('failed', 'Preview failed.'));
                // Keep the existing iframe's empty sandbox; never use innerHTML for returned content or diagnostics.
                frame.srcdoc = payload.document;
                renderedSubject.textContent = payload.subject;
                status.textContent = text('done', 'Preview rendered without saving or sending.');
                if (Array.isArray(payload.placeholders)) renderButtons(payload.placeholders);
            } catch (error) {
                status.classList.add('adf-preview-error');
                status.textContent = error.message || text('failed', 'Preview failed.');
            } finally {
                run.disabled = false;
            }
        });
        refreshButtons();
    });
}());
