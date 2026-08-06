(() => {
    'use strict';

    const selector = '[data-mublo-member-action-menu]';

    document.addEventListener('toggle', (event) => {
        const current = event.target;
        if (!(current instanceof HTMLDetailsElement) || !current.matches(selector) || !current.open) return;
        document.querySelectorAll(`${selector}[open]`).forEach((menu) => {
            if (menu !== current) menu.removeAttribute('open');
        });
    }, true);

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const menu = document.querySelector(`${selector}[open]`);
        if (!(menu instanceof HTMLDetailsElement)) return;
        menu.removeAttribute('open');
        menu.querySelector('summary')?.focus();
    });

    document.addEventListener('click', (event) => {
        document.querySelectorAll(`${selector}[open]`).forEach((menu) => {
            if (!menu.contains(event.target)) menu.removeAttribute('open');
        });
    });
})();
