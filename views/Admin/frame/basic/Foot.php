<?= $this->assets?->renderJs() ?>
<script>
    const activeCode = <?= json_encode($activeCode ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('form').forEach(function(form) {
            if (form.hasAttribute('data-no-active-code')) return;
            const hiddenInput = document.createElement('input');
            hiddenInput.type = 'hidden';
            hiddenInput.name = 'activeCode';
            hiddenInput.value = activeCode;
            form.appendChild(hiddenInput);
        });
        document.querySelectorAll('a').forEach(function(link) {
            if (link.hasAttribute('data-no-active-code')) return;
            let href = link.getAttribute('href');
            if (!href || !href.includes('/admin/') || href.includes('#')) return;
            if (href && !href.trim().toLowerCase().startsWith('javascript:')) {
                const url = new URL(href, window.location.origin);
                if (!url.searchParams.has('activeCode')) {
                    url.searchParams.append('activeCode', activeCode);
                    link.setAttribute('href', url.toString());
                }
            }
        });
    });
</script>
</body>
</html>
