/** 计划 / 发版日志编辑：显式保存，预览不写文件，冲突或失败时保留输入。 */
(function () {
    var root = document.getElementById('local_markdown_editor');
    var $ = window.jQuery;
    if (!root || !$) return;

    var content = document.getElementById('doc_content');
    var preview = document.getElementById('doc_preview');
    var status = document.getElementById('doc_save_status');
    var save = document.getElementById('doc_save');
    var frontmatterError = document.getElementById('doc_frontmatter_error');
    var frontmatterErrorText = document.getElementById('doc_frontmatter_error_text');
    var raw = JSON.parse(document.getElementById('local_markdown_raw').textContent);
    content.value = raw;
    var savedContent = content.value;
    var useCRLF = raw.indexOf('\r\n') !== -1 && raw.replace(/\r\n/g, '').indexOf('\n') === -1;
    var version = root.dataset.version;
    var timer, sequence = 0, saving = false;

    function setStatus(text, state) {
        status.textContent = text;
        status.className = 'p-docs-editor__status is-' + state;
    }

    function request(url, data) {
        return $.ajax({
            url: url, type: 'POST', contentType: 'application/json', dataType: 'json',
            timeout: 15000,
            headers: { 'X-CSRF-TOKEN': root.dataset.csrf, 'Accept': 'application/json' },
            data: JSON.stringify(data)
        });
    }

    function renderPreview() {
        var current = ++sequence;
        request(root.dataset.preview, { slug: root.dataset.slug, content: content.value })
            .done(function (result) {
                if (current !== sequence) return;
                frontmatterError.hidden = !result.error;
                frontmatterErrorText.textContent = result.error || '';
                preview.innerHTML = result.html;
                if (window.scaffoldDocsRenderMermaid) window.scaffoldDocsRenderMermaid(preview);
                if (window.scaffoldDocsRenderCode) window.scaffoldDocsRenderCode(preview);
            })
            .fail(function () {
                if (current === sequence) preview.textContent = '预览失败，请稍后重试。';
            });
    }

    content.addEventListener('input', function () {
        frontmatterError.hidden = true;
        setStatus(content.value === savedContent ? '已保存' : '未保存', content.value === savedContent ? 'saved' : 'dirty');
        ++sequence;
        clearTimeout(timer);
        timer = setTimeout(renderPreview, 350);
    });

    save.addEventListener('click', function () {
        if (saving) return;
        saving = true;
        save.disabled = true;
        var submitted = content.value;
        setStatus('保存中…', 'saving');
        var serialized = submitted === savedContent ? raw : (useCRLF ? submitted.replace(/\n/g, '\r\n') : submitted);
        request(root.dataset.save, { slug: root.dataset.slug, content: serialized, version: version })
            .done(function (result) {
                version = result.version;
                raw = serialized;
                savedContent = submitted;
                setStatus(content.value === submitted ? '已保存' : '未保存', content.value === submitted ? 'saved' : 'dirty');
            })
            .fail(function (xhr, reason) {
                var result = xhr.responseJSON || {};
                var validation = result.errors && result.errors.content && result.errors.content[0];
                if (validation) {
                    frontmatterError.hidden = false;
                    frontmatterErrorText.textContent = validation;
                }
                var message = reason === 'timeout'
                    ? '保存超时，结果尚未确认。请重试，当前输入已保留。'
                    : (validation || result.message || result.error || '保存失败，请重试，当前输入已保留。');
                setStatus(message, 'error');
            })
            .always(function () { saving = false; save.disabled = false; });
    });

    window.addEventListener('beforeunload', function (event) {
        if (content.value === savedContent && !saving) return;
        event.preventDefault();
        event.returnValue = '';
    });

    renderPreview();
})();
