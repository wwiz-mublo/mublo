<?php
/**
 * Admin Menu - Index
 *
 * 메뉴 관리 (탭 기반)
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var string $activeTab 활성 탭
 * @var array $items 메뉴 아이템 목록 (페이징 적용)
 * @var array $allActiveItems 전체 활성 메뉴 아이템 (트리용)
 * @var array $tree 메뉴 트리 (계층형)
 * @var array $flatTree 메뉴 트리 (평면)
 * @var array $utilityMenus 유틸리티 메뉴
 * @var array $footerMenus 푸터 메뉴
 * @var array $mypageMenus 마이페이지 메뉴
 * @var array $levelOptions 레벨 옵션 [level_value => level_name]
 * @var array $targetOptions target 옵션
 * @var array $pagination 페이지네이션 정보
 * @var array $searchFields 검색 필드 옵션
 * @var array $currentSearch 현재 검색 조건
 * @var string $filterRaw 현재 제공자 필터 raw 값
 * @var array $providerOptions 제공자 옵션 ['plugin' => [...], 'package' => [...]]
 * @var array $enabledPlugins 활성화된 플러그인 목록
 * @var array $enabledPackages 활성화된 패키지 목록
 */

$tabs = [
    'items'   => '메뉴 아이템',
    'tree'    => '메인 메뉴',
    'utility' => '유틸리티 메뉴',
    'footer'  => '푸터 메뉴',
    'mypage'  => '마이페이지 메뉴',
];

// 제공자별 메뉴 그룹화 (utility/footer/mypage 탭 공통)
$groupedAllItems = ['core' => [], 'plugin' => [], 'package' => []];
foreach ($allActiveItems as $item) {
    $type = $item['provider_type'] ?? 'core';
    $name = $item['provider_name'] ?? '';
    if ($type === 'core') {
        $groupedAllItems['core'][] = $item;
    } elseif ($type === 'plugin') {
        $groupedAllItems['plugin'][$name][] = $item;
    } elseif ($type === 'package') {
        $groupedAllItems['package'][$name][] = $item;
    }
}

// 마이페이지 영역 링크(/mypage/*) 표식 뱃지 — URL에서 파생(저장 필드 아님).
// 각 탭(tab-*.php)이 Index 스코프로 include되므로 공용으로 사용 가능.
$mypageBadge = function (?string $url): string {
    return (is_string($url) && str_starts_with($url, '/mypage/'))
        ? ' <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle" style="font-size:0.65rem">mypage</span>'
        : '';
};

// On/Off 옵션
$onOffOptions = [
    '1' => 'ON',
    '0' => 'OFF',
];

// 상태 옵션
$statusOptions = [
    '1' => '활성',
    '0' => '비활성',
];

// 레이아웃 오버라이드 옵션 (빈값 = 상속: 도메인 기본을 따름)
$layoutListOptions = [
    ''  => '상속',
    '1' => '전체',
    '2' => '좌측',
    '3' => '우측',
    '4' => '양쪽',
];

// 제공자 색상: Core=secondary / Plugin=info / Package=primary (트리·풀 배지와 동일 스킴)
$providerColor = function (string $type): string {
    return match ($type) {
        'plugin'  => 'info',
        'package' => 'primary',
        default   => 'secondary',
    };
};
$providerBadge = function (string $text, string $color): string {
    return '<span class="badge bg-' . $color . '-subtle text-' . $color . '-emphasis border border-' . $color . '-subtle">'
        . htmlspecialchars($text) . '</span>';
};

// 컬럼 정의 (ListColumnBuilder 사용, tab-items.php에서 $this->listRenderHelper 호출 시 필요)
// NOTE: 커스텀 HTML은 'formatter'가 아니라 'render'(callback, $row 받음)로 줘야 렌더된다.
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center']])
    ->add('item_id', '번호', ['sortable' => true, '_th_attr' => ['style' => 'width:60px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->add('provider_type', '제공자', [
        'sortable' => true,
        '_th_attr' => ['style' => 'width:90px'],
        '_cell_attr' => ['class' => 'text-nowrap'],
        'render' => function ($row) use ($providerColor, $providerBadge) {
            $type = $row['provider_type'] ?? 'core';
            $label = ['plugin' => 'Plugin', 'package' => 'Package'][$type] ?? 'Core';
            return $providerBadge($label, $providerColor($type));
        }
    ])
    ->add('provider_name', '제공자명', [
        'sortable' => true,
        '_th_attr' => ['style' => 'width:90px'],
        '_cell_attr' => ['class' => 'text-nowrap'],
        'render' => function ($row) use ($providerColor, $providerBadge) {
            $name = (string) ($row['provider_name'] ?? '');
            if ($name === '') {
                return '<span class="text-muted">-</span>';
            }
            return $providerBadge($name, $providerColor($row['provider_type'] ?? 'core'));
        }
    ])
    ->add('label', '메뉴명', ['sortable' => true])
    ->add('url', 'URL', [
        'sortable' => true,
        'render' => function ($row) {
            $value = (string) ($row['url'] ?? '');
            if ($value === '') {
                return '<span class="text-muted">-</span>';
            }
            return '<code>' . htmlspecialchars($value) . '</code>';
        }
    ])
    ->select('min_level', '접근 레벨', $levelOptions, ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:120px']])
    ->select('show_on_pc', 'PC', $onOffOptions, ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:90px']])
    ->select('show_on_mobile', '모바일', $onOffOptions, ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:90px']])
    ->select('layout_type', '레이아웃', $layoutListOptions, ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:90px']])
    ->select('is_active', '상태', $statusOptions, ['id_key' => 'item_id', '_th_attr' => ['style' => 'width:100px']])
    ->actions('actions', '관리', function ($row) {
        $id = $row['item_id'];
        return '
            <button type="button" class="btn btn-sm btn-default btn-edit-item" data-item-id="' . $id . '">
                <i class="bi bi-pencil"></i>
            </button>
            <button type="button" class="btn btn-sm btn-default btn-delete-item"
                    data-item-id="' . $id . '"
                    data-label="' . htmlspecialchars($row['label']) . '">
                <i class="bi bi-trash"></i>
            </button>
        ';
    }, ['_th_attr' => ['style' => 'width:80px'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '메뉴 관리') ?></h3>
            <p>사이트 메뉴를 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <button type="button" class="btn btn-sm btn-primary" id="btn-add-item">
                <i class="bi bi-plus-lg"></i> 메뉴 추가
            </button>
        </div>
    </div>

    <div class="page-block">
        <!-- 탭 네비게이션 -->
        <ul class="nav nav-tabs mb-2" id="menuTabs" role="tablist">
            <?php foreach ($tabs as $tabId => $tabLabel): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $activeTab === $tabId ? 'active' : '' ?>"
                        id="<?= $tabId ?>-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#<?= $tabId ?>"
                        type="button"
                        role="tab">
                    <?= htmlspecialchars($tabLabel) ?>
                </button>
            </li>
            <?php endforeach; ?>
        </ul>

        <!-- 탭 컨텐츠 -->
        <div class="tab-content" id="menuTabsContent">
            <?php foreach ($tabs as $tabId => $tabLabel): ?>
            <div class="tab-pane fade <?= $activeTab === $tabId ? 'show active' : '' ?>"
                 id="<?= $tabId ?>" role="tabpanel">
                <?php
                $tabFile = __DIR__ . '/tab-' . $tabId . '.php';
                if (is_file($tabFile)) {
                    include $tabFile;
                }
                ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- 메뉴 아이템 추가/수정 모달 -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="itemModalTitle">메뉴 추가</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="item-form">
                    <input type="hidden" name="item_id" id="item_id" value="0">

                    <div class="mb-3">
                        <label class="form-label">메뉴명 <span class="text-danger">*</span></label>
                        <input type="text" name="label" id="item_label" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL</label>
                        <input type="text" name="url" id="item_url" class="form-control" placeholder="/about">
                        <div class="form-text">비워두면 클릭 불가 (부모 메뉴용)</div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-6">
                            <label class="form-label">제공자</label>
                            <select name="provider_type" id="item_provider_type" class="form-select">
                                <option value="core">Core</option>
                                <option value="plugin">Plugin</option>
                                <option value="package">Package</option>
                            </select>
                        </div>
                        <div class="col-6" id="provider_name_wrap" style="display:none">
                            <label class="form-label">제공자명</label>
                            <select id="item_provider_name_sel" class="form-select">
                                <!-- provider_type 변경 시 JS로 옵션 채움 -->
                            </select>
                            <!-- 실제 전송값 (hidden) -->
                            <input type="hidden" name="provider_name" id="item_provider_name">
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">표시 대상</label>
                            <select name="visibility" id="item_visibility" class="form-select">
                                <option value="all">전체</option>
                                <option value="guest">비로그인만</option>
                                <option value="member">로그인만</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">메뉴 쌍 코드</label>
                            <input type="text" name="pair_code" id="item_pair_code" class="form-control"
                                   placeholder="예: auth, account" maxlength="30">
                            <div class="form-text">같은 코드끼리 묶음 (자동 포함)</div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">접근 레벨</label>
                            <select name="min_level" id="item_min_level" class="form-select">
                                <?php foreach ($levelOptions as $value => $label): ?>
                                <option value="<?= $value ?>"><?= htmlspecialchars($label) ?> (Lv.<?= $value ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">해당 레벨 이상만 접근 가능</div>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">링크 타겟</label>
                            <select name="target" id="item_target" class="form-select">
                                <?php foreach ($targetOptions as $value => $label): ?>
                                <option value="<?= $value ?>"><?= htmlspecialchars($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">화면 레이아웃</label>
                        <select name="layout_type" id="item_layout_type" class="form-select">
                            <option value="">사이트 기본 따름 (상속)</option>
                            <option value="1">전체 (사이드바 없음)</option>
                            <option value="2">좌측 사이드바</option>
                            <option value="3">우측 사이드바</option>
                            <option value="4">양쪽 사이드바</option>
                        </select>
                        <div class="form-text">
                            이 메뉴 화면에만 적용됩니다. 사이트 기본과 다른 레이아웃을 쓰고 싶을 때만 지정하세요
                            (예: 사이트는 우측 사이드바인데 이 페이지만 전체 폭).
                            <span class="text-warning-emphasis d-block">
                                ※ ‘전체’를 고르면 이 메뉴에 배치한 좌/우 사이드바 블록은 표시되지 않습니다.
                            </span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-2">
                                <input type="checkbox" name="show_on_pc" id="item_show_on_pc" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="item_show_on_pc">PC에서 표시</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input type="checkbox" name="show_on_mobile" id="item_show_on_mobile" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="item_show_on_mobile">모바일에서 표시</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check form-switch mb-2">
                                <input type="checkbox" name="is_active" id="item_is_active" class="form-check-input" value="1" checked>
                                <label class="form-check-label" for="item_is_active">활성화</label>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">취소</button>
                <button type="button" class="btn btn-primary" id="btn-save-item">저장</button>
            </div>
        </div>
    </div>
</div>

<style>
/* 풀 접기 — 페어 처리가 쓰는 d-none 과 겹치지 않도록 별도 클래스를 쓴다.
   그룹(Core/Plugin/Package)과 제공자(플러그인·패키지명)도 서로 다른 클래스를 써야
   그룹을 펼칠 때 접어둔 제공자가 되살아나지 않는다. 세 상태가 독립적으로 겹친다. */
.pool-collapsed,
.pool-collapsed-provider { display: none !important; }
.pool-group-label,
.pool-provider-label { cursor: pointer; user-select: none; }
.pool-group-label .pool-group-caret,
.pool-provider-label .pool-group-caret { font-size: 0.7rem; transition: transform 0.15s; }
.pool-group-label.is-collapsed .pool-group-caret,
.pool-provider-label.is-collapsed .pool-group-caret { transform: rotate(-90deg); }
.pool-group-label .pool-group-count,
.pool-provider-label .pool-group-count { font-size: 0.7rem; }

/* 제공자(플러그인·패키지) 소속 항목은 한 단계 들여써서 라벨의 화살표와 왼쪽 선을 맞춘다.
   제공자 라벨이 ms-2(0.5rem)이므로 같은 값을 쓴다.
   풀 안에서만 적용 — 활성 목록·트리로 옮겨간 항목에는 영향이 없다. */
.pool-collapsible .pool-item-indent { margin-left: 0.5rem; }
</style>

<script>
// ══════════════════════════════════════════════════════════════
// 풀 접기 — 2단계: 그룹(Core/Plugin/Package) + 제공자(플러그인·패키지명)
//
// DOM 은 평탄하게 유지한다. Sortable 이 풀 컨테이너에 직접 걸려 있고
// (draggable: '.menu-pool-item') returnToGroup() 이 형제 순회로 복귀 위치를
// 찾기 때문에, 그룹을 래퍼로 감싸면 드래그와 복귀가 모두 깨진다.
// → 라벨 클릭 시 형제 항목의 표시만 토글한다.
//
// 숨김 상태는 세 가지가 독립적으로 겹친다:
//   d-none(페어 처리) / .pool-collapsed(그룹) / .pool-collapsed-provider(제공자)
// 같은 클래스를 공유하면 한쪽을 펼칠 때 다른 쪽이 되살아난다.
// ══════════════════════════════════════════════════════════════
window.MubloPoolCollapse = (function () {
    var PREFIX = 'mublo.menuPool.collapsed.';
    var PROVIDER_KEY = 'p:';   // localStorage 상태에서 제공자 키 접두사

    function read(key) {
        try { return JSON.parse(localStorage.getItem(PREFIX + key) || '{}'); } catch (e) { return {}; }
    }
    function write(key, state) {
        try { localStorage.setItem(PREFIX + key, JSON.stringify(state)); } catch (e) { /* 저장 실패는 무시 */ }
    }
    function isItem(el) {
        return el.classList.contains('menu-pool-item') || el.classList.contains('item-pool-item');
    }
    function isProviderLabel(el) {
        return el.classList.contains('pool-provider-label');
    }

    // 그룹 라벨 다음부터 다음 그룹 라벨 직전까지가 그 그룹 소속.
    // 안내 문구(<p>)는 그룹에 속하지 않으므로 제외한다.
    function members(pool, type) {
        var label = groupLabelOf(pool, type);
        var out = [];
        if (!label) return out;
        var cursor = label.nextElementSibling;
        while (cursor && cursor.dataset.groupLabel === undefined) {
            if (cursor.tagName !== 'P') out.push(cursor);
            cursor = cursor.nextElementSibling;
        }
        return out;
    }

    // 제공자 라벨 다음부터 다음 제공자 라벨 / 다음 그룹 라벨 직전까지.
    function providerMembers(label) {
        var out = [];
        var cursor = label.nextElementSibling;
        while (cursor && cursor.dataset.groupLabel === undefined && !isProviderLabel(cursor)) {
            if (cursor.tagName !== 'P') out.push(cursor);
            cursor = cursor.nextElementSibling;
        }
        return out;
    }

    function groupLabelOf(pool, type) {
        return pool.querySelector('[data-group-label="' + type + '"]');
    }
    function providerLabelsOf(pool, type) {
        return members(pool, type).filter(isProviderLabel);
    }
    function findProviderLabel(pool, type, name) {
        var found = null;
        providerLabelsOf(pool, type).forEach(function (l) {
            if (l.dataset.provider === name) found = l;
        });
        return found;
    }

    function visibleCount(list) {
        return list.filter(function (m) {
            return isItem(m) && !m.classList.contains('d-none');
        }).length;
    }
    function setCount(label, n) {
        var el = label && label.querySelector('.pool-group-count');
        if (el) el.textContent = '(' + n + ')';
    }
    function markCollapsed(label, collapsed) {
        label.classList.toggle('is-collapsed', collapsed);
        label.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    function applyGroup(pool, type, collapsed) {
        members(pool, type).forEach(function (el) {
            el.classList.toggle('pool-collapsed', collapsed);
        });
        var label = groupLabelOf(pool, type);
        if (label) markCollapsed(label, collapsed);
    }

    function applyProvider(label, collapsed) {
        providerMembers(label).forEach(function (el) {
            el.classList.toggle('pool-collapsed-provider', collapsed);
        });
        markCollapsed(label, collapsed);
    }

    // 제공자 소속 항목만 한 단계 들여쓴다(라벨의 화살표와 왼쪽 선 맞춤).
    // 항목이 오갈 때마다 다시 계산해야 하므로 refresh 에서 함께 처리한다.
    function applyIndent(pool) {
        pool.querySelectorAll('.pool-item-indent').forEach(function (el) {
            el.classList.remove('pool-item-indent');
        });
        pool.querySelectorAll('[data-group-label]').forEach(function (gl) {
            providerLabelsOf(pool, gl.dataset.groupLabel).forEach(function (pl) {
                providerMembers(pl).forEach(function (el) {
                    if (isItem(el)) el.classList.add('pool-item-indent');
                });
            });
        });
    }

    function refresh(pool) {
        if (!pool) return;
        pool.querySelectorAll('[data-group-label]').forEach(function (gl) {
            var type = gl.dataset.groupLabel;
            setCount(gl, visibleCount(members(pool, type)));
            providerLabelsOf(pool, type).forEach(function (pl) {
                setCount(pl, visibleCount(providerMembers(pl)));
            });
        });
        applyIndent(pool);
    }

    function bindToggle(label, onToggle) {
        label.setAttribute('role', 'button');
        label.setAttribute('tabindex', '0');
        if (!label.querySelector('.pool-group-caret')) {
            var caret = document.createElement('i');
            caret.className = 'bi bi-chevron-down pool-group-caret me-1';
            label.insertBefore(caret, label.firstChild);
        }
        if (!label.querySelector('.pool-group-count')) {
            var cnt = document.createElement('span');
            cnt.className = 'pool-group-count text-muted ms-1';
            label.appendChild(cnt);
        }
        label.addEventListener('click', onToggle);
        label.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onToggle(); }
        });
    }

    function init(poolId, storeKey) {
        var pool = document.getElementById(poolId);
        if (!pool) return;
        pool.dataset.collapseKey = storeKey;
        pool.classList.add('pool-collapsible');   // 들여쓰기 CSS 를 이 풀 안으로 한정
        var state = read(storeKey);

        pool.querySelectorAll('[data-group-label]').forEach(function (gl) {
            var type = gl.dataset.groupLabel;

            bindToggle(gl, function () {
                var next = !gl.classList.contains('is-collapsed');
                applyGroup(pool, type, next);
                state[type] = next;
                write(storeKey, state);
            });

            // 제공자 라벨: 소속 이름은 바로 뒤 항목의 data-provider-name 에서 얻는다.
            // (항목이 없으면 라벨 텍스트의 "— " 뒤를 폴백으로 사용)
            providerLabelsOf(pool, type).forEach(function (pl) {
                var first = providerMembers(pl).filter(isItem)[0];
                var name = (first && first.dataset.providerName)
                    || pl.textContent.replace(/^[\s—-]+/, '').trim();
                pl.dataset.provider = name;
                var sKey = PROVIDER_KEY + type + ':' + name;

                bindToggle(pl, function () {
                    var next = !pl.classList.contains('is-collapsed');
                    applyProvider(pl, next);
                    state[sKey] = next;
                    write(storeKey, state);
                });

                applyProvider(pl, !!state[sKey]);
            });

            applyGroup(pool, type, !!state[type]);   // 기본값은 전부 펼침
        });

        refresh(pool);
    }

    // 접힌 곳으로 항목이 되돌아올 때: 조용히 사라진 것처럼 보이지 않도록 펼친다.
    function reveal(pool, type, name) {
        if (!pool) return;
        var key = pool.dataset.collapseKey;
        var state = key ? read(key) : {};

        var gl = groupLabelOf(pool, type);
        if (gl && gl.classList.contains('is-collapsed')) {
            applyGroup(pool, type, false);
            state[type] = false;
        }
        if (name) {
            var pl = findProviderLabel(pool, type, name);
            if (pl && pl.classList.contains('is-collapsed')) {
                applyProvider(pl, false);
                state[PROVIDER_KEY + type + ':' + name] = false;
            }
        }
        if (key) write(key, state);
        refresh(pool);
    }

    return { init: init, reveal: reveal, refresh: refresh, findProviderLabel: findProviderLabel };
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var itemModal = new bootstrap.Modal(document.getElementById('itemModal'));

    // ============================
    // 전체 선택
    // ============================
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }

    // ============================
    // 탭 1: 메뉴 아이템
    // ============================

    // 메뉴 추가 버튼
    document.getElementById('btn-add-item').addEventListener('click', function() {
        resetItemForm();
        document.getElementById('itemModalTitle').textContent = '메뉴 추가';
        itemModal.show();
    });

    // 메뉴 수정 버튼 (이벤트 위임)
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.btn-edit-item');
        if (btn) {
            var itemId = btn.dataset.itemId;
            loadItemForEdit(itemId);
        }

        var deleteBtn = e.target.closest('.btn-delete-item');
        if (deleteBtn) {
            var itemId = deleteBtn.dataset.itemId;
            var label = deleteBtn.dataset.label;
            MubloRequest.showConfirm('"' + label + '" 메뉴를 삭제하시겠습니까?', function() {
                deleteItem(itemId);
            }, { type: 'warning' });
        }
    });

    // 선택 삭제
    document.getElementById('btn-bulk-delete').addEventListener('click', function() {
        var checkboxes = document.querySelectorAll('input[name="chk[]"]:checked');
        if (checkboxes.length === 0) {
            MubloRequest.showAlert('삭제할 항목을 선택해주세요.', 'warning');
            return;
        }
        MubloRequest.showConfirm(checkboxes.length + '개 항목을 삭제하시겠습니까?', function() {
            var promises = [];
            checkboxes.forEach(function(checkbox) {
                promises.push(
                    MubloRequest.requestJson('/admin/menu/item-delete', { item_id: parseInt(checkbox.value) })
                );
            });

            Promise.all(promises).then(function() {
                location.reload();
            });
        }, { type: 'warning' });
    });

    // 메뉴 저장
    document.getElementById('btn-save-item').addEventListener('click', function() {
        saveItem();
    });

    function resetItemForm() {
        document.getElementById('item-form').reset();
        document.getElementById('item_id').value = '0';
        document.getElementById('item_visibility').value = 'all';
        document.getElementById('item_layout_type').value = '';
        document.getElementById('item_pair_code').value = '';
        document.getElementById('item_show_on_pc').checked = true;
        document.getElementById('item_show_on_mobile').checked = true;
        document.getElementById('item_is_active').checked = true;
        document.getElementById('item_provider_type').value = 'core';
        document.getElementById('item_provider_name').value = '';
        document.getElementById('item_provider_name_sel').innerHTML = '';
        document.getElementById('provider_name_wrap').style.display = 'none';
    }

    // 활성화된 플러그인/패키지 목록 (PHP → JS)
    var providerNameOptions = {
        plugin: <?= json_encode(array_values($enabledPlugins ?? []), JSON_UNESCAPED_UNICODE) ?>,
        package: <?= json_encode(array_values($enabledPackages ?? []), JSON_UNESCAPED_UNICODE) ?>
    };

    function updateProviderNameSelect(type, currentValue) {
        var sel = document.getElementById('item_provider_name_sel');
        var hidden = document.getElementById('item_provider_name');
        sel.innerHTML = '';

        var names = providerNameOptions[type] || [];

        // 빈 옵션(제공자명 미지정) — 유형만 바꿀 때 첫 항목이 자동 저장되는 것을 방지.
        // 저장된 값이 활성 목록에 없으면 매칭 옵션이 없어 이 빈 옵션이 선택되고, 저장 시 빈값으로 처리된다.
        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '';
        sel.appendChild(blank);

        names.forEach(function(name) {
            var opt = document.createElement('option');
            opt.value = name;
            opt.textContent = name;
            if (name === currentValue) opt.selected = true;
            sel.appendChild(opt);
        });

        // 선택값 반영
        hidden.value = sel.value || '';
    }

    // 제공자 셀렉트 변경 → hidden 동기화
    document.getElementById('item_provider_name_sel').addEventListener('change', function() {
        document.getElementById('item_provider_name').value = this.value;
    });

    // 제공자 유형 변경 시 제공자명 select 재구성
    document.getElementById('item_provider_type').addEventListener('change', function() {
        var wrap = document.getElementById('provider_name_wrap');
        if (this.value === 'core') {
            wrap.style.display = 'none';
            document.getElementById('item_provider_name').value = '';
        } else {
            wrap.style.display = '';
            updateProviderNameSelect(this.value, '');
        }
    });

    function loadItemForEdit(itemId) {
        MubloRequest.requestJson('/admin/menu/item-view?item_id=' + itemId, null, { method: 'GET' })
            .then(function(result) {
                if (result.result === 'success') {
                    var item = result.data;
                    document.getElementById('item_id').value = item.item_id;
                    document.getElementById('item_label').value = item.label || '';
                    document.getElementById('item_url').value = item.url || '';
                    document.getElementById('item_visibility').value = item.visibility || 'all';
                    document.getElementById('item_pair_code').value = item.pair_code || '';
                    document.getElementById('item_min_level').value = item.min_level || 0;
                    document.getElementById('item_target').value = item.target || '_self';
                    // NULL(상속)은 빈 문자열로 — 사이트 기본 따름
                    document.getElementById('item_layout_type').value =
                        (item.layout_type === null || item.layout_type === undefined) ? '' : String(item.layout_type);
                    document.getElementById('item_show_on_pc').checked = item.show_on_pc == 1;
                    document.getElementById('item_show_on_mobile').checked = item.show_on_mobile == 1;
                    document.getElementById('item_is_active').checked = item.is_active == 1;
                    var providerType = item.provider_type || 'core';
                    document.getElementById('item_provider_type').value = providerType;
                    if (providerType !== 'core') {
                        document.getElementById('provider_name_wrap').style.display = '';
                        updateProviderNameSelect(providerType, item.provider_name || '');
                    } else {
                        document.getElementById('provider_name_wrap').style.display = 'none';
                        document.getElementById('item_provider_name').value = '';
                    }

                    document.getElementById('itemModalTitle').textContent = '메뉴 수정';
                    itemModal.show();
                } else {
                    MubloRequest.showAlert(result.message || '메뉴를 불러올 수 없습니다.', 'error');
                }
            });
    }

    function saveItem() {
        var data = {
            item_id: parseInt(document.getElementById('item_id').value) || 0,
            label: document.getElementById('item_label').value,
            url: document.getElementById('item_url').value,
            target: document.getElementById('item_target').value,
            visibility: document.getElementById('item_visibility').value || 'all',
            pair_code: document.getElementById('item_pair_code').value || '',
            min_level: parseInt(document.getElementById('item_min_level').value) || 0,
            // 빈 문자열 = 상속(NULL). 서버가 ''→NULL, 그 외→int 로 정규화한다.
            layout_type: document.getElementById('item_layout_type').value,
            show_on_pc: document.getElementById('item_show_on_pc').checked ? 1 : 0,
            show_on_mobile: document.getElementById('item_show_on_mobile').checked ? 1 : 0,
            is_active: document.getElementById('item_is_active').checked ? 1 : 0,
            provider_type: document.getElementById('item_provider_type').value || 'core',
            provider_name: document.getElementById('item_provider_name').value || ''
        };

        MubloRequest.requestJson('/admin/menu/item-store', data, { loading: true })
            .then(function(result) {
                if (result.result === 'success') {
                    MubloRequest.showToast(result.message, 'success');
                    location.reload();
                } else {
                    MubloRequest.showAlert(result.message || '저장에 실패했습니다.', 'error');
                }
            });
    }

    function deleteItem(itemId) {
        MubloRequest.requestJson('/admin/menu/item-delete', { item_id: itemId }, { loading: true })
            .then(function(result) {
                if (result.result === 'success') {
                    location.reload();
                } else {
                    MubloRequest.showAlert(result.message || '삭제에 실패했습니다.', 'error');
                }
            });
    }

    // ============================
    // 탭 2: 메인 메뉴 (트리)
    // ============================

    // 아이템 풀은 제공자별 그룹으로 표시 (유틸/푸터/마이 탭과 통일, 검색/필터는 그룹 탐색으로 대체)

    // 트리에 메뉴 추가 버튼 (이벤트 위임)
    var itemPoolEl = document.getElementById('item-pool');
    if (itemPoolEl) {
        itemPoolEl.addEventListener('click', function(e) {
            var btn = e.target.closest('.btn-add-to-tree');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                var menuCode = btn.dataset.menuCode;
                if (menuCode) {
                    addToTree(menuCode, null);
                }
                return false;
            }
        });
    }

    // 트리 저장
    var btnSaveTree = document.getElementById('btn-save-tree');
    if (btnSaveTree) {
        btnSaveTree.addEventListener('click', function() {
            saveTree();
        });
    }

    // 트리 노드 제거
    document.addEventListener('click', function(e) {
        if (e.target.closest('.btn-remove-node')) {
            var btn = e.target.closest('.btn-remove-node');
            var nodeId = btn.dataset.nodeId;
            MubloRequest.showConfirm('이 메뉴를 트리에서 제거하시겠습니까?\n(하위 메뉴도 함께 제거됩니다)', function() {
                removeFromTree(nodeId);
            }, { type: 'warning' });
        }
    });

    function addToTree(menuCode, parentCode) {
        // item-pool에서 데이터 가져오기
        var poolItem = document.querySelector('.item-pool-item[data-menu-code="' + menuCode + '"]');
        var label = poolItem ? poolItem.dataset.label : menuCode;
        var url = poolItem ? poolItem.dataset.url : '';
        var minLevel = poolItem ? parseInt(poolItem.dataset.minLevel) || 0 : 0;
        var providerType = poolItem ? (poolItem.dataset.providerType || 'core') : 'core';
        var providerName = poolItem ? (poolItem.dataset.providerName || '') : '';
        var pairCode = poolItem ? (poolItem.dataset.pairCode || '') : '';

        // 트리에 동적으로 추가
        var rootTree = document.querySelector('#menu-tree .sortable-tree[data-depth="0"]');
        if (rootTree) {
            var newNode = createTreeNode(menuCode, label, 0, url, minLevel, providerType, providerName, pairCode);
            rootTree.appendChild(newNode);
            hideEmptyTreeMessage();
            MubloRequest.showToast('메뉴가 추가되었습니다. 저장 버튼을 눌러 저장하세요.', 'success');
        } else {
            MubloRequest.showAlert('트리를 찾을 수 없습니다.', 'error');
        }
    }

    function removeFromTree(nodeId) {
        // DOM에서 직접 제거 (저장은 별도로)
        var node = document.querySelector('.tree-node[data-node-id="' + nodeId + '"]');
        if (node) {
            node.remove();
            // X 제거는 Sortable onEnd를 안 거치므로, 비게 된 하위 드롭존을 직접 숨긴다.
            // (안 하면 빈 드롭존이 대시보더 빈 자리로 남는 버그)
            document.querySelectorAll('#menu-tree .child-drop-zone').forEach(function(zone) {
                if (zone.children.length === 0) {
                    zone.style.display = 'none';
                }
            });
            MubloRequest.showToast('메뉴가 제거되었습니다. 저장 버튼을 눌러 저장하세요.', 'success');
        }
    }

    function saveTree() {
        var rootTree = document.querySelector('#menu-tree > .sortable-tree, #menu-tree .sortable-tree[data-depth="0"]');
        var treeData = collectTreeData(rootTree);

        if (treeData.length === 0) {
            MubloRequest.showAlert('저장할 메뉴가 없습니다.', 'warning');
            return;
        }

        MubloRequest.requestJson('/admin/menu/tree-update', { tree: treeData }, { loading: true })
            .then(function(result) {
                if (result.result === 'success') {
                    MubloRequest.showToast(result.message || '저장되었습니다.', 'success');
                } else {
                    MubloRequest.showAlert(result.message || '저장에 실패했습니다.', 'error');
                }
            })
            .catch(function(error) {
                console.error('tree-update error:', error);
                MubloRequest.showAlert('서버 오류가 발생했습니다.', 'error');
            });
    }

    function collectTreeData(ul) {
        var data = [];
        if (!ul) return data;

        var children = ul.children;
        for (var i = 0; i < children.length; i++) {
            var li = children[i];
            if (!li.classList.contains('tree-node')) continue;

            var node = {
                menu_code: li.dataset.menuCode,
                children: []
            };

            // 하위 메뉴 찾기
            var childContainer = li.querySelector(':scope > .children-container');
            if (childContainer) {
                var childUl = childContainer.querySelector(':scope > ul.tree-list');
                if (childUl && childUl.children.length > 0) {
                    node.children = collectTreeData(childUl);
                }
            }

            data.push(node);

            // pair 아이템도 같은 레벨에 별도 노드로 추가
            if (li.dataset.pairedMenuCode) {
                data.push({
                    menu_code: li.dataset.pairedMenuCode,
                    children: []
                });
            }
        }

        return data;
    }

    // 트리에 새 노드 추가하는 헬퍼 함수
    function createTreeNode(menuCode, label, depth, url, minLevel, providerType, providerName, pairCode) {
        depth = depth || 0;
        url = url || '';
        minLevel = minLevel || 0;
        providerType = providerType || 'core';
        providerName = providerName || '';
        pairCode = pairCode || '';

        var newNode = document.createElement('li');
        newNode.className = 'tree-node mb-1';
        newNode.dataset.menuCode = menuCode;
        newNode.dataset.nodeId = 'new_' + Date.now();
        newNode.dataset.depth = depth;
        newNode.dataset.url = url;
        newNode.dataset.minLevel = minLevel;
        if (pairCode) {
            newNode.dataset.pairCode = pairCode;
        }

        var depthHtml = depth > 0 ? '<span class="depth-indicator text-muted me-2">└</span>' : '';

        var providerBadge = '';
        if (providerType === 'plugin') {
            providerBadge = '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle me-1" style="font-size:0.75rem">' + (providerName || 'Plugin') + '</span>';
        } else if (providerType === 'package') {
            providerBadge = '<span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle me-1" style="font-size:0.75rem">' + (providerName || 'Package') + '</span>';
        } else {
            providerBadge = '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle me-1" style="font-size:0.75rem">Core</span>';
        }

        var urlHtml = url ? '<code class="menu-url text-muted small ms-2">' + url + '</code>' : '';

        var levelHtml = '';
        if (minLevel > 0) {
            levelHtml = '<span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle small ms-2">Lv.' + minLevel + '+</span>';
        }

        // pair 인디케이터 (쌍 아이템 자동 탐색)
        var pairHtml = '';
        if (pairCode) {
            var pairedItem = document.querySelector('#item-pool .item-pool-item[data-pair-code="' + pairCode + '"]:not([data-menu-code="' + menuCode + '"])');
            if (pairedItem) {
                newNode.dataset.pairedMenuCode = pairedItem.dataset.menuCode;
                pairHtml = '<span class="pair-indicator ms-1" style="font-size:0.85rem"><span class="text-muted">↔</span> ' + pairedItem.dataset.label + '</span>';
            }
        }

        newNode.innerHTML =
            '<div class="node-content d-flex align-items-center">' +
                depthHtml +
                providerBadge +
                '<span class="menu-label">' + label + '</span>' +
                pairHtml +
                urlHtml +
                levelHtml +
                '<span class="flex-grow-1"></span>' +
                '<button type="button" class="btn btn-xs btn-outline-danger btn-remove-node" data-node-id="' + newNode.dataset.nodeId + '" title="제거"><i class="bi bi-x"></i></button>' +
            '</div>' +
            '<div class="children-container">' +
                '<ul class="tree-list list-unstyled sortable-tree child-drop-zone" data-depth="' + (depth + 1) + '" style="display: none;"></ul>' +
            '</div>';

        setTimeout(function() {
            var childList = newNode.querySelector('.child-drop-zone');
            if (childList) {
                initSortableTree(childList);
            }
        }, 0);

        return newNode;
    }

    function hideEmptyTreeMessage() {
        var msg = document.querySelector('.empty-tree-message');
        if (msg) msg.style.display = 'none';
    }

    function updateDepthIndicators() {
        document.querySelectorAll('#menu-tree .tree-node').forEach(function(node) {
            var parentList = node.closest('.tree-list');
            var depth = parseInt(parentList.dataset.depth) || 0;
            node.dataset.depth = depth;

            var nodeContent = node.querySelector('.node-content');
            var existingIndicator = nodeContent.querySelector('.depth-indicator');
            if (existingIndicator) existingIndicator.remove();

            if (depth > 0) {
                var depthEl = document.createElement('span');
                depthEl.className = 'depth-indicator text-muted me-2';
                depthEl.textContent = '└';
                nodeContent.insertBefore(depthEl, nodeContent.firstChild);
            }
        });
    }

    function initSortableTree(ul) {
        new Sortable(ul, {
            group: 'shared',
            animation: 150,
            fallbackOnBody: true,
            swapThreshold: 0.5,
            invertSwap: true,
            invertedSwapThreshold: 0.5,
            draggable: '.tree-node, .item-pool-item',
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            onStart: function(evt) {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    zone.style.display = 'block';
                });
            },
            onEnd: function(evt) {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    if (zone.children.length === 0) {
                        zone.style.display = 'none';
                    }
                });
                updateDepthIndicators();
            },
            onAdd: function(evt) {
                if (evt.item.classList.contains('item-pool-item')) {
                    var menuCode = evt.item.dataset.menuCode;
                    var label = evt.item.dataset.label;
                    var url = evt.item.dataset.url || '';
                    var minLevel = parseInt(evt.item.dataset.minLevel) || 0;
                    var depth = parseInt(evt.to.dataset.depth) || 0;
                    var providerType = evt.item.dataset.providerType || 'core';
                    var providerName = evt.item.dataset.providerName || '';
                    var pairCode = evt.item.dataset.pairCode || '';

                    var newNode = createTreeNode(menuCode, label, depth, url, minLevel, providerType, providerName, pairCode);
                    evt.item.replaceWith(newNode);
                    hideEmptyTreeMessage();
                }

                if (evt.to.children.length > 0) {
                    evt.to.style.display = 'block';
                }
            },
            onMove: function(evt) {
                return true;
            }
        });
    }

    // Sortable for item pool
    var itemPool = document.getElementById('item-pool');
    if (itemPool) {
        new Sortable(itemPool, {
            group: { name: 'shared', pull: 'clone', put: false },
            sort: false,
            animation: 150,
            draggable: '.item-pool-item',
            filter: '.btn-add-to-tree',
            preventOnFilter: false,
            onStart: function(evt) {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    zone.style.display = 'block';
                });
            },
            onEnd: function(evt) {
                document.querySelectorAll('.child-drop-zone').forEach(function(zone) {
                    if (zone.children.length === 0) {
                        zone.style.display = 'none';
                    }
                });
            }
        });
    }

    // 모든 tree-list에 Sortable 적용
    document.querySelectorAll('#menu-tree .sortable-tree').forEach(function(ul) {
        initSortableTree(ul);
    });

    // 풀 그룹 접기 — 탭별로 접힘 상태를 따로 기억한다(저장 시 페이지가 리로드되므로)
    MubloPoolCollapse.init('item-pool', 'tree');
    MubloPoolCollapse.init('utility-pool', 'utility');
    MubloPoolCollapse.init('footer-pool', 'footer');
    MubloPoolCollapse.init('mypage-pool', 'mypage');

    // ============================
    // 탭 3: 유틸리티 메뉴 (Sortable 드래그앤드롭)
    // ============================

    (function() {
        var pool = document.getElementById('utility-pool');
        var list = document.getElementById('utility-list');
        if (!pool || !list) return;

        function toActiveStyle(el) {
            el.classList.remove('menu-pool-item');
            el.classList.add('menu-active-item');
            el.style.cursor = '';
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-muted'; icon.style.cursor = 'grab'; }
        }
        function toPoolStyle(el) {
            el.classList.remove('menu-active-item');
            el.classList.add('menu-pool-item');
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-secondary'; icon.style.cursor = ''; }
        }
        function returnToGroup(el) {
            var type = el.dataset.providerType || 'core';
            var name = el.dataset.providerName || '';
            var groupLabel = pool.querySelector('[data-group-label="' + type + '"]');
            if (!groupLabel) { pool.appendChild(el); return; }

            // 같은 제공자 구획 안으로 되돌린다. 제공자까지 맞추지 않으면 그룹 맨 끝,
            // 즉 다른 제공자의 서브 라벨 아래로 들어가 잘못된 소속처럼 보인다.
            var providerLabel = name ? MubloPoolCollapse.findProviderLabel(pool, type, name) : null;
            var start = providerLabel || groupLabel;

            var cursor = start.nextElementSibling;
            var insertBefore = null;
            while (cursor) {
                if (cursor === el) { cursor = cursor.nextElementSibling; continue; }
                // 다음 그룹 라벨 앞에서 멈춘다. 제공자 구획에 넣는 중이면 다음 제공자 라벨에서도 멈춘다.
                if (cursor.dataset && cursor.dataset.groupLabel !== undefined) { insertBefore = cursor; break; }
                if (providerLabel && cursor.classList.contains('pool-provider-label')) { insertBefore = cursor; break; }
                cursor = cursor.nextElementSibling;
            }
            if (insertBefore) { pool.insertBefore(el, insertBefore); } else { pool.appendChild(el); }

            // 접힌 곳으로 돌아오면 조용히 사라진 것처럼 보이므로 해당 그룹·제공자를 펼친다
            el.classList.remove('pool-collapsed', 'pool-collapsed-provider');
            MubloPoolCollapse.reveal(pool, type, name);
        }

        function getItemLabel(el) {
            var span = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (!span) return '';
            var clone = span.cloneNode(true);
            clone.querySelectorAll('.badge, .pair-indicator').forEach(function(x) { x.remove(); });
            return clone.textContent.trim();
        }
        // ── 페어 처리 (데이터 기반) ──
        // 짝 노드를 제거/재생성하지 않는다. 풀에 그대로 두고 d-none으로 숨김/표시만 한다.
        // → 배치/제거를 몇 번 반복해도 멱등. (HTML 문자열 stash/restore의 상태 오염 제거)
        function addPairIndicator(el, label) {
            var indicator = document.createElement('span');
            indicator.className = 'pair-indicator ms-1';
            indicator.style.fontSize = '0.85rem';
            indicator.innerHTML = '<span class="text-muted">↔</span> ' + label;
            var labelEl = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (labelEl) {
                if (labelEl.classList.contains('flex-grow-1')) {
                    labelEl.appendChild(indicator);
                } else {
                    labelEl.after(indicator);
                }
            }
        }
        // el을 list에 배치 → 같은 pairCode의 (보이는) 풀 아이템을 숨기고 인디케이터 표시
        function pairOnPlace(el) {
            var pairCode = el.dataset.pairCode;
            if (!pairCode) return;
            var paired = pool.querySelector(
                '.menu-pool-item[data-pair-code="' + pairCode + '"]:not(.d-none):not([data-item-id="' + el.dataset.itemId + '"])'
            );
            if (!paired) return;
            var pairedLabel = getItemLabel(paired);
            if (!pairedLabel) return;
            el.dataset.pairedId = paired.dataset.itemId;
            addPairIndicator(el, pairedLabel);
            paired.classList.add('d-none'); // 숨김만(노드 유지) → 드래그 불가, 복원 가능
        }
        // el을 풀로 되돌림 → 인디케이터 제거 + 숨겨둔 짝을 data-item-id로 찾아 다시 표시
        function unpairOnRemove(el) {
            el.querySelectorAll('.pair-indicator').forEach(function(x) { x.remove(); });
            if (el.dataset.pairedId) {
                var paired = pool.querySelector('.menu-pool-item[data-item-id="' + el.dataset.pairedId + '"]');
                if (paired) paired.classList.remove('d-none');
            }
            delete el.dataset.pairedId;
        }
        // 리스트에 아이템이 있으면 '여기에 드래그' 안내를 숨기고, 비면 다시 표시
        function syncEmpty() {
            var msg = list.querySelector('.menu-empty-msg');
            if (msg) msg.classList.toggle('d-none', list.querySelector('.menu-active-item') !== null);
        }

        new Sortable(pool, {
            group: { name: 'utility-group', pull: true, put: true },
            draggable: '.menu-pool-item',
            sort: false,
            animation: 150,
            onAdd: function(evt) { toPoolStyle(evt.item); returnToGroup(evt.item); unpairOnRemove(evt.item); syncEmpty(); }
        });
        new Sortable(list, {
            filter: '.menu-empty-msg',
            group: { name: 'utility-group', pull: true, put: true },
            animation: 150,
            onAdd: function(evt) { toActiveStyle(evt.item); pairOnPlace(evt.item); syncEmpty(); MubloPoolCollapse.refresh(pool); }
        });

        document.getElementById('btn-save-utility').addEventListener('click', function() {
            var itemIds = [];
            list.querySelectorAll('[data-item-id]').forEach(function(el) {
                itemIds.push(parseInt(el.dataset.itemId));
                if (el.dataset.pairedId) itemIds.push(parseInt(el.dataset.pairedId));
            });
            MubloRequest.requestJson('/admin/menu/utility-update', { item_ids: itemIds }, { loading: true })
                .then(function(res) { MubloRequest.showToast(res.message || '저장되었습니다.', 'success'); reloadWithTab('utility'); });
        });

        // 페이지 로드 시: list에 함께 저장된 페어를 한 줄로 병합.
        // secondary는 제거하지 않고 풀로 옮겨 숨긴다(노드 유지) → primary 제거 시 그대로 복원.
        (function() {
            var items = Array.from(list.querySelectorAll(':scope > [data-pair-code]'));
            var seen = {};
            items.forEach(function(el) {
                var code = el.dataset.pairCode;
                if (!code) return;
                if (seen[code]) {
                    var primary = seen[code];
                    var secLabel = getItemLabel(el);
                    if (!secLabel) return;
                    primary.dataset.pairedId = el.dataset.itemId;
                    addPairIndicator(primary, secLabel);
                    toPoolStyle(el);
                    el.classList.add('d-none'); // 풀에 숨겨 보관
                    returnToGroup(el);
                } else {
                    seen[code] = el;
                }
            });
        })();
    })();

    // ============================
    // 탭 4: 푸터 메뉴 (Sortable 드래그앤드롭)
    // ============================

    (function() {
        var pool = document.getElementById('footer-pool');
        var list = document.getElementById('footer-list');
        if (!pool || !list) return;

        function toActiveStyle(el) {
            el.classList.remove('menu-pool-item');
            el.classList.add('menu-active-item');
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-muted'; icon.style.cursor = 'grab'; }
        }
        function toPoolStyle(el) {
            el.classList.remove('menu-active-item');
            el.classList.add('menu-pool-item');
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-secondary'; icon.style.cursor = ''; }
        }
        function returnToGroup(el) {
            var type = el.dataset.providerType || 'core';
            var name = el.dataset.providerName || '';
            var groupLabel = pool.querySelector('[data-group-label="' + type + '"]');
            if (!groupLabel) { pool.appendChild(el); return; }

            // 같은 제공자 구획 안으로 되돌린다. 제공자까지 맞추지 않으면 그룹 맨 끝,
            // 즉 다른 제공자의 서브 라벨 아래로 들어가 잘못된 소속처럼 보인다.
            var providerLabel = name ? MubloPoolCollapse.findProviderLabel(pool, type, name) : null;
            var start = providerLabel || groupLabel;

            var cursor = start.nextElementSibling;
            var insertBefore = null;
            while (cursor) {
                if (cursor === el) { cursor = cursor.nextElementSibling; continue; }
                // 다음 그룹 라벨 앞에서 멈춘다. 제공자 구획에 넣는 중이면 다음 제공자 라벨에서도 멈춘다.
                if (cursor.dataset && cursor.dataset.groupLabel !== undefined) { insertBefore = cursor; break; }
                if (providerLabel && cursor.classList.contains('pool-provider-label')) { insertBefore = cursor; break; }
                cursor = cursor.nextElementSibling;
            }
            if (insertBefore) { pool.insertBefore(el, insertBefore); } else { pool.appendChild(el); }

            // 접힌 곳으로 돌아오면 조용히 사라진 것처럼 보이므로 해당 그룹·제공자를 펼친다
            el.classList.remove('pool-collapsed', 'pool-collapsed-provider');
            MubloPoolCollapse.reveal(pool, type, name);
        }

        function getItemLabel(el) {
            var span = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (!span) return '';
            var clone = span.cloneNode(true);
            clone.querySelectorAll('.badge, .pair-indicator').forEach(function(x) { x.remove(); });
            return clone.textContent.trim();
        }
        // ── 페어 처리 (데이터 기반) ──
        // 짝 노드를 제거/재생성하지 않는다. 풀에 그대로 두고 d-none으로 숨김/표시만 한다.
        // → 배치/제거를 몇 번 반복해도 멱등. (HTML 문자열 stash/restore의 상태 오염 제거)
        function addPairIndicator(el, label) {
            var indicator = document.createElement('span');
            indicator.className = 'pair-indicator ms-1';
            indicator.style.fontSize = '0.85rem';
            indicator.innerHTML = '<span class="text-muted">↔</span> ' + label;
            var labelEl = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (labelEl) {
                if (labelEl.classList.contains('flex-grow-1')) {
                    labelEl.appendChild(indicator);
                } else {
                    labelEl.after(indicator);
                }
            }
        }
        // el을 list에 배치 → 같은 pairCode의 (보이는) 풀 아이템을 숨기고 인디케이터 표시
        function pairOnPlace(el) {
            var pairCode = el.dataset.pairCode;
            if (!pairCode) return;
            var paired = pool.querySelector(
                '.menu-pool-item[data-pair-code="' + pairCode + '"]:not(.d-none):not([data-item-id="' + el.dataset.itemId + '"])'
            );
            if (!paired) return;
            var pairedLabel = getItemLabel(paired);
            if (!pairedLabel) return;
            el.dataset.pairedId = paired.dataset.itemId;
            addPairIndicator(el, pairedLabel);
            paired.classList.add('d-none'); // 숨김만(노드 유지) → 드래그 불가, 복원 가능
        }
        // el을 풀로 되돌림 → 인디케이터 제거 + 숨겨둔 짝을 data-item-id로 찾아 다시 표시
        function unpairOnRemove(el) {
            el.querySelectorAll('.pair-indicator').forEach(function(x) { x.remove(); });
            if (el.dataset.pairedId) {
                var paired = pool.querySelector('.menu-pool-item[data-item-id="' + el.dataset.pairedId + '"]');
                if (paired) paired.classList.remove('d-none');
            }
            delete el.dataset.pairedId;
        }
        // 리스트에 아이템이 있으면 '여기에 드래그' 안내를 숨기고, 비면 다시 표시
        function syncEmpty() {
            var msg = list.querySelector('.menu-empty-msg');
            if (msg) msg.classList.toggle('d-none', list.querySelector('.menu-active-item') !== null);
        }

        new Sortable(pool, {
            group: { name: 'footer-group', pull: true, put: true },
            draggable: '.menu-pool-item',
            sort: false,
            animation: 150,
            onAdd: function(evt) { toPoolStyle(evt.item); returnToGroup(evt.item); unpairOnRemove(evt.item); syncEmpty(); }
        });
        new Sortable(list, {
            filter: '.menu-empty-msg',
            group: { name: 'footer-group', pull: true, put: true },
            animation: 150,
            onAdd: function(evt) { toActiveStyle(evt.item); pairOnPlace(evt.item); syncEmpty(); MubloPoolCollapse.refresh(pool); }
        });

        document.getElementById('btn-save-footer').addEventListener('click', function() {
            var itemIds = [];
            list.querySelectorAll('[data-item-id]').forEach(function(el) {
                itemIds.push(parseInt(el.dataset.itemId));
                if (el.dataset.pairedId) itemIds.push(parseInt(el.dataset.pairedId));
            });
            MubloRequest.requestJson('/admin/menu/footer-update', { item_ids: itemIds }, { loading: true })
                .then(function(res) { MubloRequest.showToast(res.message || '저장되었습니다.', 'success'); reloadWithTab('footer'); });
        });

        // 페이지 로드 시: list에 함께 저장된 페어를 한 줄로 병합.
        // secondary는 제거하지 않고 풀로 옮겨 숨긴다(노드 유지) → primary 제거 시 그대로 복원.
        (function() {
            var items = Array.from(list.querySelectorAll(':scope > [data-pair-code]'));
            var seen = {};
            items.forEach(function(el) {
                var code = el.dataset.pairCode;
                if (!code) return;
                if (seen[code]) {
                    var primary = seen[code];
                    var secLabel = getItemLabel(el);
                    if (!secLabel) return;
                    primary.dataset.pairedId = el.dataset.itemId;
                    addPairIndicator(primary, secLabel);
                    toPoolStyle(el);
                    el.classList.add('d-none'); // 풀에 숨겨 보관
                    returnToGroup(el);
                } else {
                    seen[code] = el;
                }
            });
        })();
    })();

    // ============================
    // 탭 5: 마이페이지 메뉴 (Sortable 드래그앤드롭)
    // ============================

    (function() {
        var pool = document.getElementById('mypage-pool');
        var list = document.getElementById('mypage-list');
        if (!pool || !list) return;

        function toActiveStyle(el) {
            el.classList.remove('menu-pool-item');
            el.classList.add('menu-active-item');
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-muted'; icon.style.cursor = 'grab'; }
        }
        function toPoolStyle(el) {
            el.classList.remove('menu-active-item');
            el.classList.add('menu-pool-item');
            var icon = el.querySelector('i');
            if (icon) { icon.className = 'bi bi-arrows-move text-secondary'; icon.style.cursor = ''; }
        }
        function returnToGroup(el) {
            var type = el.dataset.providerType || 'core';
            var name = el.dataset.providerName || '';
            var groupLabel = pool.querySelector('[data-group-label="' + type + '"]');
            if (!groupLabel) { pool.appendChild(el); return; }

            // 같은 제공자 구획 안으로 되돌린다. 제공자까지 맞추지 않으면 그룹 맨 끝,
            // 즉 다른 제공자의 서브 라벨 아래로 들어가 잘못된 소속처럼 보인다.
            var providerLabel = name ? MubloPoolCollapse.findProviderLabel(pool, type, name) : null;
            var start = providerLabel || groupLabel;

            var cursor = start.nextElementSibling;
            var insertBefore = null;
            while (cursor) {
                if (cursor === el) { cursor = cursor.nextElementSibling; continue; }
                // 다음 그룹 라벨 앞에서 멈춘다. 제공자 구획에 넣는 중이면 다음 제공자 라벨에서도 멈춘다.
                if (cursor.dataset && cursor.dataset.groupLabel !== undefined) { insertBefore = cursor; break; }
                if (providerLabel && cursor.classList.contains('pool-provider-label')) { insertBefore = cursor; break; }
                cursor = cursor.nextElementSibling;
            }
            if (insertBefore) { pool.insertBefore(el, insertBefore); } else { pool.appendChild(el); }

            // 접힌 곳으로 돌아오면 조용히 사라진 것처럼 보이므로 해당 그룹·제공자를 펼친다
            el.classList.remove('pool-collapsed', 'pool-collapsed-provider');
            MubloPoolCollapse.reveal(pool, type, name);
        }

        function getItemLabel(el) {
            var span = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (!span) return '';
            var clone = span.cloneNode(true);
            clone.querySelectorAll('.badge, .pair-indicator').forEach(function(x) { x.remove(); });
            return clone.textContent.trim();
        }
        // ── 페어 처리 (데이터 기반) ──
        // 짝 노드를 제거/재생성하지 않는다. 풀에 그대로 두고 d-none으로 숨김/표시만 한다.
        // → 배치/제거를 몇 번 반복해도 멱등. (HTML 문자열 stash/restore의 상태 오염 제거)
        function addPairIndicator(el, label) {
            var indicator = document.createElement('span');
            indicator.className = 'pair-indicator ms-1';
            indicator.style.fontSize = '0.85rem';
            indicator.innerHTML = '<span class="text-muted">↔</span> ' + label;
            var labelEl = el.querySelector('.flex-grow-1') || el.querySelector(':scope > span:not(.badge):not(.pair-indicator)');
            if (labelEl) {
                if (labelEl.classList.contains('flex-grow-1')) {
                    labelEl.appendChild(indicator);
                } else {
                    labelEl.after(indicator);
                }
            }
        }
        // el을 list에 배치 → 같은 pairCode의 (보이는) 풀 아이템을 숨기고 인디케이터 표시
        function pairOnPlace(el) {
            var pairCode = el.dataset.pairCode;
            if (!pairCode) return;
            var paired = pool.querySelector(
                '.menu-pool-item[data-pair-code="' + pairCode + '"]:not(.d-none):not([data-item-id="' + el.dataset.itemId + '"])'
            );
            if (!paired) return;
            var pairedLabel = getItemLabel(paired);
            if (!pairedLabel) return;
            el.dataset.pairedId = paired.dataset.itemId;
            addPairIndicator(el, pairedLabel);
            paired.classList.add('d-none'); // 숨김만(노드 유지) → 드래그 불가, 복원 가능
        }
        // el을 풀로 되돌림 → 인디케이터 제거 + 숨겨둔 짝을 data-item-id로 찾아 다시 표시
        function unpairOnRemove(el) {
            el.querySelectorAll('.pair-indicator').forEach(function(x) { x.remove(); });
            if (el.dataset.pairedId) {
                var paired = pool.querySelector('.menu-pool-item[data-item-id="' + el.dataset.pairedId + '"]');
                if (paired) paired.classList.remove('d-none');
            }
            delete el.dataset.pairedId;
        }
        // 리스트에 아이템이 있으면 '여기에 드래그' 안내를 숨기고, 비면 다시 표시
        function syncEmpty() {
            var msg = list.querySelector('.menu-empty-msg');
            if (msg) msg.classList.toggle('d-none', list.querySelector('.menu-active-item') !== null);
        }

        new Sortable(pool, {
            group: { name: 'mypage-group', pull: true, put: true },
            draggable: '.menu-pool-item',
            sort: false,
            animation: 150,
            onAdd: function(evt) { toPoolStyle(evt.item); returnToGroup(evt.item); unpairOnRemove(evt.item); syncEmpty(); }
        });
        // 시스템 메뉴(회원정보=head / 회원탈퇴=tail)를 항상 목록 양끝에 재고정한다.
        // onMove 가드는 빈 영역 드롭(related=null) 등에서 뚫릴 수 있으므로, 드롭이 끝난 뒤
        // 보정해 불변식을 보장한다. (서버 saveMypageOrder의 sentinel order(0/9999)와 동일한 불변식의 클라판)
        function pinSystemItems() {
            var sys = list.querySelectorAll('.is-system');
            if (sys.length === 0) return;
            var head = sys[0];
            var tail = sys[sys.length - 1];
            if (list.firstElementChild !== head) list.insertBefore(head, list.firstElementChild);
            if (list.lastElementChild !== tail) list.appendChild(tail);
        }

        new Sortable(list, {
            filter: '.menu-empty-msg',
            group: {
                name: 'mypage-group',
                pull: function(to, from, dragEl) {
                    // 시스템 메뉴(회원정보/회원탈퇴)는 목록 밖으로 제거 불가
                    return !dragEl.classList.contains('is-system');
                },
                put: true
            },
            animation: 150,
            // 시스템 메뉴 자체는 드래그(재정렬) 불가
            filter: '.is-system',
            onMove: function(evt) {
                var rel = evt.related;
                if (!rel || !rel.classList.contains('is-system')) return true;
                // 시스템 메뉴는 양끝 고정: head(회원정보) 앞·tail(회원탈퇴) 뒤로는 이동 금지
                var sys = list.querySelectorAll('.is-system');
                var head = sys[0];
                var tail = sys[sys.length - 1];
                if (!evt.willInsertAfter && rel === head) return false;
                if (evt.willInsertAfter && rel === tail) return false;
                return true;
            },
            // onMove를 뚫고 양끝 밖으로 들어온 경우까지 드롭 후 보정
            onAdd: function(evt) { toActiveStyle(evt.item); pairOnPlace(evt.item); pinSystemItems(); syncEmpty(); MubloPoolCollapse.refresh(pool); },
            onEnd: function() { pinSystemItems(); }
        });

        document.getElementById('btn-save-mypage').addEventListener('click', function() {
            var itemIds = [];
            list.querySelectorAll('[data-item-id]').forEach(function(el) {
                itemIds.push(parseInt(el.dataset.itemId));
                if (el.dataset.pairedId) itemIds.push(parseInt(el.dataset.pairedId));
            });
            MubloRequest.requestJson('/admin/menu/mypage-update', { item_ids: itemIds }, { loading: true })
                .then(function(res) { MubloRequest.showToast(res.message || '저장되었습니다.', 'success'); reloadWithTab('mypage'); });
        });

        // 페이지 로드 시: list에 함께 저장된 페어를 한 줄로 병합.
        // secondary는 제거하지 않고 풀로 옮겨 숨긴다(노드 유지) → primary 제거 시 그대로 복원.
        (function() {
            var items = Array.from(list.querySelectorAll(':scope > [data-pair-code]'));
            var seen = {};
            items.forEach(function(el) {
                var code = el.dataset.pairCode;
                if (!code) return;
                if (seen[code]) {
                    var primary = seen[code];
                    var secLabel = getItemLabel(el);
                    if (!secLabel) return;
                    primary.dataset.pairedId = el.dataset.itemId;
                    addPairIndicator(primary, secLabel);
                    toPoolStyle(el);
                    el.classList.add('d-none'); // 풀에 숨겨 보관
                    returnToGroup(el);
                } else {
                    seen[code] = el;
                }
            });
        })();
    })();

    function reloadWithTab(tab) {
        var url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.location.href = url.toString();
    }

    // 탭 전환 시 URL의 tab 쿼리 파라미터 동기화
    document.querySelectorAll('#menuTabs button[data-bs-toggle="tab"]').forEach(function(btn) {
        btn.addEventListener('shown.bs.tab', function(e) {
            var tabId = e.target.getAttribute('data-bs-target').replace('#', '');
            var url = new URL(window.location.href);
            if (tabId === 'items') {
                url.searchParams.delete('tab');
            } else {
                url.searchParams.set('tab', tabId);
            }
            history.replaceState(null, '', url.toString());
        });
    });
});

// 일괄 수정 후 콜백 (전역 함수)
function afterBulkUpdate(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '수정되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '수정에 실패했습니다.', 'error');
    }
}
</script>
