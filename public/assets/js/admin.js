/**
 * Admin table interactions: expandable edit rows and the magic-edit panel.
 */
(function () {
    function toggleRow(id, button) {
        const row = document.getElementById(id);
        if (!row) return;

        const willOpen = row.hidden;
        row.hidden = !willOpen;

        const productRow = document.querySelector('[data-product-id="' + id.split('-')[1] + '"]');
        if (productRow) productRow.classList.toggle('is-open', willOpen);
        if (button) button.setAttribute('aria-expanded', String(willOpen));
    }

    document.querySelectorAll('.row-toggle').forEach((btn) => {
        btn.addEventListener('click', () => toggleRow(btn.dataset.target, btn));
    });

    document.querySelectorAll('.magic-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const row = document.getElementById(btn.dataset.target);
            if (row) row.hidden = !row.hidden;
        });
    });

    // Normalize Enter to <p> in contenteditable regions — Chrome defaults
    // to bare <div>s, which the server-side sanitizer has to special-case
    // anyway, but this keeps the two editors' output consistent.
    try {
        document.execCommand('defaultParagraphSeparator', false, 'p');
    } catch (err) {
        // Unsupported in some browsers — the sanitizer's <div>-as-<p>
        // handling covers that case regardless.
    }

    document.querySelectorAll('[data-wysiwyg]').forEach((wrap) => {
        const editor = wrap.querySelector('.wysiwyg-editor');
        const textarea = wrap.querySelector('textarea');
        const form = wrap.closest('form');

        const sync = () => { textarea.value = editor.innerHTML; };
        sync();

        editor.addEventListener('input', sync);
        if (form) form.addEventListener('submit', sync);

        wrap.querySelectorAll('[data-cmd]').forEach((btn) => {
            btn.addEventListener('click', () => {
                editor.focus();
                const cmd = btn.dataset.cmd;

                if (cmd === 'createLink') {
                    const url = window.prompt('Link URL (https://…):');
                    if (!url) return;
                    document.execCommand(cmd, false, url);
                } else {
                    document.execCommand(cmd, false, null);
                }

                sync();
            });
        });
    });

    document.querySelectorAll('.magic-run').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const block = btn.closest('.magic-block');
            const output = block.querySelector('.magic-output');
            const instruction = block.querySelector('.magic-prompt').value.trim();

            if (!instruction) {
                output.hidden = false;
                output.textContent = 'Enter an instruction first.';
                return;
            }

            btn.disabled = true;
            output.hidden = false;
            output.textContent = 'Thinking…';

            try {
                const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
                const response = await fetch('/admin/api/magic-edit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        product_id: block.dataset.productId,
                        instruction,
                        csrf_token: csrfToken,
                    }),
                });
                const data = await response.json();
                output.textContent = data.success ? data.suggestion : 'Error: ' + data.error;
            } catch (err) {
                output.textContent = 'Request failed: ' + err.message;
            } finally {
                btn.disabled = false;
            }
        });
    });
})();
