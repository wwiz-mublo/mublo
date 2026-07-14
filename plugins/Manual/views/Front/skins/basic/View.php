<?php
/**
 * 매뉴얼 열람 (프론트) — TOC 사이드바 + 본문
 *
 * 자체 완결 스킨: 코어 manual.css/manual.js·Bootstrap 에 의존하지 않는다.
 * 스타일은 플러그인 style.css, 스크롤스파이는 아래 인라인 스크립트로 해결한다.
 * 각 페이지는 #sec-{page_id} 앵커의 .manual-section 으로 렌더되고,
 * 좌측 TOC(.manual-toc__link)가 스크롤 위치를 .is-active 로 하이라이트한다.
 *
 * @var string $pageTitle
 * @var \Mublo\Contract\Manual\ManualBook|null $book
 * @var list<\Mublo\Contract\Manual\ManualPageNode> $tree  중첩 페이지 트리
 * @var string|null $activeSlug  딥링크 대상 페이지 slug (스크롤 이동용)
 */

use Mublo\Contract\Manual\ManualPageNode;

$book = $book ?? null;
$tree = $tree ?? [];
$activeSlug = $activeSlug ?? null;

/**
 * TOC 재귀 렌더링 (들여쓰기 depth)
 *
 * @param list<ManualPageNode> $nodes
 */
if (!function_exists('mublo_manual_front_toc')) {
    function mublo_manual_front_toc(array $nodes, int $depth = 0): void
    {
        foreach ($nodes as $node) {
            $pad = 0.75 + $depth * 0.85;
            echo '<a class="manual-toc__link"'
                . ' style="padding-left:' . $pad . 'rem"'
                . ' data-slug="' . htmlspecialchars($node->slug) . '"'
                . ' href="#sec-' . $node->pageId . '">'
                . htmlspecialchars($node->title)
                . '</a>';
            if ($node->children !== []) {
                mublo_manual_front_toc($node->children, $depth + 1);
            }
        }
    }
}

/**
 * 본문 섹션 재귀 렌더링 — depth로 제목 레벨(h4~h6) 조정
 *
 * @param list<ManualPageNode> $nodes
 */
if (!function_exists('mublo_manual_front_sections')) {
    function mublo_manual_front_sections(array $nodes, int $depth = 0): void
    {
        $hTag = 'h' . min(6, 4 + $depth);
        foreach ($nodes as $node) {
            echo '<section id="sec-' . $node->pageId . '" class="manual-section">';
            echo '<' . $hTag . '>' . htmlspecialchars($node->title) . '</' . $hTag . '>';
            $content = trim((string) ($node->content ?? ''));
            if ($content !== '') {
                echo '<div class="manual-page-content">' . $content . '</div>';
            }
            echo '</section>';
            if ($node->children !== []) {
                mublo_manual_front_sections($node->children, $depth + 1);
            }
        }
    }
}

$this->assets->addCss('/serve/plugin/Manual/views/Front/skins/basic/style.css?v=20260722');
?>

<div class="manual-view">
    <div class="manual-view__head">
        <a href="/manual" class="manual-view__back"><i class="bi bi-arrow-left"></i> 매뉴얼 목록</a>
        <div class="manual-view__head-main">
            <div class="manual-view__book-icon"><i class="bi bi-book"></i></div>
            <div>
                <div class="manual-view__eyebrow">MUBLO MANUAL</div>
                <h1 class="manual-view__title"><?= htmlspecialchars($book?->title ?? '') ?></h1>
                <?php if ($book !== null && $book->description !== null && $book->description !== ''): ?>
                    <p class="manual-view__desc"><?= htmlspecialchars($book->description) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if (empty($tree)): ?>
        <div class="manual-list-empty">등록된 페이지가 없습니다.</div>
    <?php else: ?>
        <div class="manual-view__grid">
            <nav class="manual-view__toc" id="manual-toc" aria-label="목차">
                <div class="manual-toc__head">
                    <div class="manual-toc__icon"><i class="bi bi-list-nested"></i></div>
                    <div>
                        <div class="manual-toc__label">목차</div>
                        <div class="manual-toc__hint">원하는 항목으로 이동하세요</div>
                    </div>
                </div>
                <div class="manual-toc__links">
                    <?php mublo_manual_front_toc($tree); ?>
                </div>
            </nav>

            <div class="manual-view__body" id="manual-content">
                <?php mublo_manual_front_sections($tree); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
(function () {
    var toc = document.getElementById('manual-toc');
    var content = document.getElementById('manual-content');
    if (!toc || !content) return;

    var links = Array.prototype.slice.call(toc.querySelectorAll('.manual-toc__link'));
    var sections = Array.prototype.slice.call(content.querySelectorAll('.manual-section'));
    if (!links.length || !sections.length) return;

    function scrollToHash(hash, smooth) {
        var target = document.querySelector(hash);
        if (!target) return false;
        var top = target.getBoundingClientRect().top + window.pageYOffset - 90;
        window.scrollTo({ top: top, behavior: smooth ? 'smooth' : 'auto' });
        return true;
    }

    // 스크롤스파이: 현재 화면 상단(뷰포트 120px 지점)에 걸린 섹션을 활성 표시
    // getBoundingClientRect 로 뷰포트 기준 위치를 재므로, 바깥 레이아웃에
    // position 래퍼가 있어도 offsetParent 영향을 받지 않는다.
    function onScroll() {
        var threshold = 120;
        var activeId = sections[0].id;
        for (var i = 0; i < sections.length; i++) {
            if (sections[i].getBoundingClientRect().top <= threshold) activeId = sections[i].id;
        }
        for (var j = 0; j < links.length; j++) {
            links[j].classList.toggle('is-active', links[j].getAttribute('href') === '#' + activeId);
        }
    }

    // TOC 클릭 → 부드러운 스크롤
    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            var hash = link.getAttribute('href');
            if (scrollToHash(hash, true)) {
                e.preventDefault();
                if (history.replaceState) history.replaceState(null, '', hash);
            }
        });
    });

    window.addEventListener('scroll', onScroll, { passive: true });
    window.addEventListener('resize', onScroll, { passive: true });
    onScroll();

    <?php if ($activeSlug !== null): ?>
    // 딥링크: 특정 페이지로 진입 시 해당 섹션으로 이동
    var deep = toc.querySelector('.manual-toc__link[data-slug="<?= htmlspecialchars($activeSlug, ENT_QUOTES) ?>"]');
    if (deep) {
        var run = function () { scrollToHash(deep.getAttribute('href'), false); onScroll(); };
        if (document.readyState === 'complete') run();
        else window.addEventListener('load', run);
    }
    <?php endif; ?>
})();
</script>
