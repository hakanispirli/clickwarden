(() => {
    'use strict';

    const flash = (button) => {
        button.classList.add('is-copied');
        setTimeout(() => button.classList.remove('is-copied'), 1500);
    };

    const copy = async (text, button) => {
        try {
            await navigator.clipboard.writeText(text);
        } catch (e) {
            // Fallback for non-secure (http) admin screens.
            const area = document.createElement('textarea');
            area.value = text;
            area.setAttribute('readonly', '');
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        flash(button);
    };

    document.addEventListener('click', (event) => {
        const direct = event.target.closest('[data-cw-copy]');
        if (direct) {
            event.preventDefault();
            copy(direct.dataset.cwCopy, direct);
            return;
        }

        const targeted = event.target.closest('[data-cw-copy-target]');
        if (targeted) {
            event.preventDefault();
            const source = document.querySelector(targeted.dataset.cwCopyTarget);
            if (source) {
                copy(source.value || source.textContent, targeted);
            }
        }
    });

    // Confirmation for destructive forms.
    document.querySelectorAll('form[data-cw-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.cwConfirm)) {
                event.preventDefault();
            }
        });
    });

    // Keep filter URLs short: drop empty fields before submitting.
    document.querySelectorAll('form[data-cw-filters]').forEach((form) => {
        form.addEventListener('submit', () => {
            form.querySelectorAll('input, select').forEach((field) => {
                const empty = field.type === 'checkbox' ? !field.checked : field.value === '';
                if (empty && field.name && field.type !== 'hidden') {
                    field.disabled = true;
                }
            });
        });
    });

    // Select the exclusion list on focus for manual copying.
    const list = document.getElementById('cw-exclusions');
    if (list) {
        list.addEventListener('focus', () => list.select());
    }
})();
