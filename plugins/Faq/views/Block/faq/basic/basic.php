<?php
/**
 * Block Skin: faq/basic
 *
 * 카드형 FAQ 아코디언 — 카테고리를 해시태그 탭으로 노출하고,
 * 질문은 여백이 넉넉한 카드로 쌓는다. 자체 CSS, 부트스트랩 미사용.
 *
 * 동작 규칙
 *  - 탭은 항상 하나가 선택되어 있다(첫 탭 기본). 전체를 한꺼번에 쏟지 않는다.
 *  - 노출 개수는 관리자 블록 설정(pc_count/mo_count)을 탭 하나당으로 적용한다.
 *    PC/모바일 개수가 다를 수 있어, 초과분은 서버에서 표시 상태를 정하고
 *    모바일 전용 초과분만 CSS 로 감춘다(JS 없이도 첫 화면이 정확하다).
 *
 * @var array $titleConfig 타이틀 설정
 * @var string $titlePartial 타이틀 파셜 경로
 * @var \Mublo\Entity\Block\BlockColumn $column 블록 칸 엔티티
 * @var \Mublo\Core\Rendering\AssetManager|null $assets 에셋 매니저
 * @var array $grouped FAQ 그룹 배열 [['category_name' => string, 'items' => [...]], ...]
 * @var array $config content_config
 * @var int $pcCount PC 노출 개수 (0 = 제한 없음)
 * @var int $moCount 모바일 노출 개수 (0 = 제한 없음)
 */

$grouped = $grouped ?? [];
$config = $config ?? [];
$pcCount = (int) ($pcCount ?? 0);
$moCount = (int) ($moCount ?? 0);
$showCategory = (bool) ($config['show_category'] ?? true);
$blockId = 'block_faq_' . $column->getRenderKey();

// 항목이 있는 그룹만 탭 대상
$tabGroups = [];
foreach ($grouped as $gIdx => $group) {
    if (!empty($group['items'])) {
        $tabGroups[$gIdx] = (string) ($group['category_name'] ?? '');
    }
}
$showTabs = $showCategory && count($tabGroups) > 1;

// 기본 선택 탭 — 탭을 쓰지 않으면 모든 그룹을 이어서 보여준다
$activeCat = $showTabs ? array_key_first($tabGroups) : null;

if ($assets) {
    $assets->addCss('/serve/plugin/Faq/views/Block/faq/basic/style.css');
}
?>

<div class="block-faq block-faq--basic" id="<?= htmlspecialchars($blockId) ?>"
     data-pc-count="<?= $pcCount ?>" data-mo-count="<?= $moCount ?>">
    <?php include $titlePartial; ?>

    <div class="block-faq__content block-body">
        <?php if (empty($tabGroups)): ?>
        <div class="block-faq__empty">
            <i class="bi bi-inbox block-faq__empty-icon"></i>
            <span>등록된 FAQ가 없습니다.</span>
        </div>
        <?php else: ?>

            <?php if ($showTabs): ?>
            <div class="cfaq-chips" role="tablist" aria-label="FAQ 카테고리">
                <?php foreach ($tabGroups as $gIdx => $name): ?>
                <?php $on = $gIdx === $activeCat; ?>
                <button type="button" class="cfaq-chip<?= $on ? ' is-active' : '' ?>" role="tab"
                        data-cat="<?= (int) $gIdx ?>" aria-selected="<?= $on ? 'true' : 'false' ?>">
                    #<?= htmlspecialchars($name) ?>
                </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="cfaq-list">
                <?php foreach ($grouped as $gIdx => $group): ?>
                    <?php $idx = 0; ?>
                    <?php foreach ($group['items'] as $item): ?>
                        <?php
                        $inActiveTab = ($activeCat === null) || ($gIdx === $activeCat);
                        $overPc = $pcCount > 0 && $idx >= $pcCount;
                        $overMo = $moCount > 0 && $idx >= $moCount;
                        // 화면에 낼 항목인지: 활성 탭이고 PC 개수 이내
                        $visible = $inActiveTab && !$overPc;
                        // PC 에는 나오지만 모바일 개수는 넘는 항목 → CSS 로 모바일에서만 숨김
                        $moHide = $visible && $overMo;
                        ?>
                        <div class="cfaq-item<?= $moHide ? ' cfaq-item--mo-hide' : '' ?>"
                             data-cat="<?= (int) $gIdx ?>" data-idx="<?= $idx ?>"<?= $visible ? '' : ' hidden' ?>>
                            <button type="button" class="cfaq-q" aria-expanded="false">
                                <span class="cfaq-q-text"><?= htmlspecialchars($item['question']) ?></span>
                                <svg class="cfaq-chevron" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"
                                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="m6 9 6 6 6-6"></path>
                                </svg>
                            </button>
                            <div class="cfaq-a">
                                <div class="cfaq-a-inner"><?= $item['answer'] ?></div>
                            </div>
                        </div>
                        <?php $idx++; ?>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var root = document.getElementById('<?= htmlspecialchars($blockId, ENT_QUOTES) ?>');
    if (!root || root.dataset.cfaqReady) return;
    root.dataset.cfaqReady = '1';

    var pcCount = parseInt(root.dataset.pcCount, 10) || 0;
    var moCount = parseInt(root.dataset.moCount, 10) || 0;
    var mq = window.matchMedia('(max-width: 575px)');

    // 아코디언 — 실제 높이로 펼쳐 답변 길이에 관계없이 잘리지 않는다
    root.querySelectorAll('.cfaq-q').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var item = btn.closest('.cfaq-item');
            var panel = item.querySelector('.cfaq-a');
            var open = item.classList.toggle('is-open');
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            panel.style.maxHeight = open ? (panel.scrollHeight + 'px') : '';
        });
    });

    var chips = root.querySelectorAll('.cfaq-chip');
    if (!chips.length) return;

    var items = root.querySelectorAll('.cfaq-item');

    function close(item) {
        if (!item.classList.contains('is-open')) return;
        item.classList.remove('is-open');
        item.querySelector('.cfaq-q').setAttribute('aria-expanded', 'false');
        item.querySelector('.cfaq-a').style.maxHeight = '';
    }

    function apply(cat) {
        // 뷰포트에 맞는 개수 (0 이면 제한 없음)
        var limit = mq.matches ? moCount : pcCount;
        var shown = 0;

        chips.forEach(function (c) {
            var on = c.dataset.cat === cat;
            c.classList.toggle('is-active', on);
            c.setAttribute('aria-selected', on ? 'true' : 'false');
        });

        items.forEach(function (it) {
            var inTab = it.dataset.cat === cat;
            var show = inTab && (limit === 0 || shown < limit);
            if (show) shown++;
            // 전환 후에는 JS 가 개수를 직접 판단하므로 모바일 전용 클래스는 걷어낸다
            it.classList.remove('cfaq-item--mo-hide');
            it.hidden = !show;
            if (!show) close(it);
        });
    }

    // 탭은 항상 하나가 선택된 상태를 유지한다(해제 없음)
    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            apply(chip.dataset.cat);
        });
    });

    // 뷰포트가 바뀌면 현재 탭 기준으로 개수를 다시 맞춘다
    var onChange = function () {
        var active = root.querySelector('.cfaq-chip.is-active');
        if (active) apply(active.dataset.cat);
    };
    if (mq.addEventListener) mq.addEventListener('change', onChange);
    else if (mq.addListener) mq.addListener(onChange);
})();
</script>
