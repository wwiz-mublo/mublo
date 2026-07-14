/**
 * 매뉴얼 공통 스크립트
 * - ScrollSpy (TOC 하이라이트) + Smooth Scroll
 * - Core / Mshop / Front 매뉴얼에서 공용 사용
 */
document.addEventListener('DOMContentLoaded', function () {
    var tocLinks = document.querySelectorAll('#manual-toc .list-group-item-action');
    var sections = document.querySelectorAll('.manual-section');
    var content  = document.getElementById('manual-content');

    if (!content || sections.length === 0) return;

    /* ---------- ScrollSpy ---------- */
    function updateActiveToc() {
        var scrollTop = window.scrollY || document.documentElement.scrollTop;
        var offset    = 120;
        var activeId  = '';

        sections.forEach(function (sec) {
            if (sec.offsetTop - offset <= scrollTop) {
                activeId = sec.id;
            }
        });

        tocLinks.forEach(function (link) {
            link.classList.remove('active');
            if (link.getAttribute('href') === '#' + activeId) {
                link.classList.add('active');
            }
        });
    }

    window.addEventListener('scroll', updateActiveToc);
    updateActiveToc();

    /* ---------- Smooth Scroll ---------- */
    tocLinks.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var target = document.querySelector(this.getAttribute('href'));
            if (target) {
                window.scrollTo({
                    top: target.offsetTop - 90,
                    behavior: 'smooth'
                });
            }
        });
    });
});
