<?php
/**
 * Admin Domains - Index
 *
 * 도메인 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var \Mublo\Entity\Domain\Domain[] $domains
 * @var array $pagination 페이지네이션 정보
 * @var array $searchFields 검색 필드 옵션
 * @var array $statusOptions 상태 옵션
 * @var array $currentSearch 현재 검색 조건
 * @var array $currentFilters 현재 필터 조건
 * @var array $settingsLinks 패키지별 설정 링크 [{label, url, icon, package}]
 * @var array $domainPackagesMap 도메인별 설치 패키지 [domainId => ['Mshop', 'Rental', ...]]
 */
$settingsLinks = $settingsLinks ?? [];
$domainPackagesMap = $domainPackagesMap ?? [];
$domainOwnerMap = $domainOwnerMap ?? [];

// 수정 링크에 붙일 목록 복귀 쿼리 (검색·필터·페이지 유지)
$listQuery = $listQuery ?? '';
$editReturn = rawurlencode('/admin/domains' . ($listQuery !== '' ? '?' . $listQuery : ''));

// 도메인 데이터를 배열로 변환 (ListRenderHelper 용)
$domainsData = [];
foreach ($domains as $domain) {
    $row = [
        'domain_id' => $domain->getDomainId(),
        'domain' => $domain->getDomain(),
        'site_title' => $domain->getSiteTitle(),
        'domain_group' => $domain->getDomainGroup(),
        'status' => $domain->getStatus(),
        'created_at' => $domain->getCreatedAt(),
    ];
    $domainsData[] = $row;
}

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'domain_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center text-nowrap'], '_cell_attr' => ['class' => 'text-center text-nowrap']])
    ->add('domain_id', '번호', ['_th_attr' => ['style' => 'width:60px', 'class' => 'text-nowrap'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->callback('domain', '도메인', function ($row) {
        $domainId = $row['domain_id'];
        $domain = htmlspecialchars($row['domain']);
        $siteTitle = htmlspecialchars($row['site_title'] ?? '');
        $badge = $domainId === 1 ? '<span class="badge bg-warning text-dark ms-1">기본</span>' : '';

        $extLink = '<a href="//' . $domain . '" target="_blank" rel="noopener" class="ms-2 text-muted" title="새 창으로 열기"><i class="bi bi-box-arrow-up-right"></i></a>';

        $html = "<div><strong>{$domain}</strong>{$badge}{$extLink}</div>";
        if ($siteTitle) {
            $html .= '<small class="text-muted">' . $siteTitle . '</small>';
        }
        return $html;
    }, ['_th_attr' => ['class' => 'text-nowrap'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->callback('domain_group', '그룹', function ($row) use ($domainOwnerMap) {
        $group = (string) ($row['domain_group'] ?? '');
        $html = '<div><code>' . htmlspecialchars($group) . '</code></div>';

        // 그룹 경로(1/4/5)를 소유자 체인(admin/site/test1)으로 표기.
        // 자기 상위(조상)는 text-muted, 리프(자기)는 기본색.
        $segs = array_values(array_filter(explode('/', $group), fn($s) => $s !== ''));
        if ($segs) {
            $names = array_map(
                fn($seg) => htmlspecialchars($domainOwnerMap[(int) $seg] ?? $seg),
                $segs
            );
            $self = array_pop($names);
            $ancestors = $names
                ? '<span class="text-muted">' . implode('/', $names) . '/</span>'
                : '';
            $html .= '<small class="d-block">' . $ancestors . $self . '</small>';
        }
        return $html;
    }, ['_th_attr' => ['class' => 'text-nowrap'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->select('status', '상태', $statusOptions, [
        'id_key' => 'domain_id',
        '_th_attr' => ['style' => 'width:100px', 'class' => 'text-nowrap'],
        '_cell_attr' => ['class' => 'text-nowrap']
    ])
    ->callback('created_at', '등록일', function ($row) {
        return htmlspecialchars(substr($row['created_at'] ?? '', 0, 10));
    }, ['_th_attr' => ['style' => 'width:100px', 'class' => 'text-nowrap'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->actions('actions', '관리', function ($row) use ($settingsLinks, $domainPackagesMap, $editReturn) {
        $id = $row['domain_id'];
        $domain = htmlspecialchars($row['domain']);
        $status = $row['status'] ?? '';

        $html = '<a href="/admin/domains/edit/' . $id . '?return=' . $editReturn . '" class="btn btn-sm btn-default">수정</a>';
        if ($status === 'active') {
            $html .= ' <button type="button" class="btn btn-sm btn-primary btn-proxy-login" data-id="' . $id . '" data-domain="' . $domain . '">접속</button>';
        }
        // 패키지별 설정 링크 드롭다운 (해당 도메인에 설치된 패키지만)
        if ($status === 'active' && !empty($settingsLinks)) {
            $enabledPackages = $domainPackagesMap[$id] ?? [];
            $filteredLinks = array_filter($settingsLinks, function ($link) use ($enabledPackages) {
                return empty($link['package']) || in_array($link['package'], $enabledPackages);
            });
            if (!empty($filteredLinks)) {
                $html .= ' <div class="btn-group">';
                $html .= '<button type="button" class="btn btn-sm btn-default dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">';
                $html .= '<i class="bi bi-gear"></i>';
                $html .= '</button>';
                $html .= '<ul class="dropdown-menu dropdown-menu-end">';
                foreach ($filteredLinks as $link) {
                    $label = htmlspecialchars($link['label']);
                    $icon = htmlspecialchars($link['icon'] ?? 'bi-gear');
                    $url = htmlspecialchars($link['url']);
                    $html .= '<li><a class="dropdown-item btn-proxy-settings" href="#" data-id="' . $id . '" data-domain="' . $domain . '" data-redirect="' . $url . '">';
                    $html .= '<i class="bi ' . $icon . ' me-2"></i>' . $label . '</a></li>';
                }
                $html .= '</ul></div>';
            }
        }
        if ($id !== 1) {
            $html .= ' <button type="button" class="btn btn-sm btn-danger btn-delete" data-id="' . $id . '" data-domain="' . $domain . '">삭제</button>';
        }
        return $html;
    }, ['_th_attr' => ['style' => 'width:150px', 'class' => 'text-nowrap'], '_cell_attr' => ['class' => 'text-nowrap']])
    ->build();
?>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '도메인 관리') ?></h3>
            <p>하위 사이트를 관리합니다. 현재 사이트 설정은 <a href="/admin/settings" data-no-active-code>기본 설정</a>에서 변경하세요.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/domains/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 하위 사이트 등록
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/domains">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? $pagination['total'] ?? 0) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <!-- 상태 필터 -->
                        <div class="col col-xl-auto">
                            <select name="status" class="form-select form-select-sm">
                                <option value="">상태: 전체</option>
                                <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($currentFilters['status'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- 검색 필드 -->
                        <div class="col col-xl-auto">
                            <select name="search_field" class="form-select form-select-sm">
                                <option value="">검색 필드</option>
                                <?php foreach ($searchFields as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($currentSearch['field'] ?? '') === $key ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="search_keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="검색어 입력"
                                       value="<?= htmlspecialchars($currentSearch['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($currentSearch['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/domains'"></i>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-sm btn-default">
                                <i class="bi bi-search"></i> 검색
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <!-- 도메인 목록 폼 -->
        <form name="flist" id="flist">
            <!-- 도메인 목록 테이블 -->
            <div class="table-responsive" id="domainTableWrap">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($domainsData)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/domains/list-modify"
                            data-callback="afterBulkUpdate"
                        >
                            <i class="d-inline d-md-none bi bi-pencil-square"></i>
                            <span class="d-none d-md-inline">선택 수정</span>
                        </button>
                        <button
                            type="button"
                            class="btn btn-sm btn-default mublo-submit"
                            data-target="/admin/domains/list-delete"
                            data-callback="afterBulkDelete"
                            data-confirm="선택한 도메인을 삭제하시겠습니까?\n\n삭제된 도메인은 복구할 수 없습니다."
                        >
                            <i class="d-inline d-md-none bi bi-trash"></i>
                            <span class="d-none d-md-inline">선택 삭제</span>
                        </button>
                    </div>
                </div>
                <div class="col-auto">
                    <?= $this->pagination($pagination) ?>
                </div>
            </div>
        </form>
    </div>
</div>

<style>
/* 인라인 셀렉트 변경됨 표시 */
#flist select.list-changed {
    border-color: var(--bs-warning, #ffc107);
    background-color: var(--bs-warning-bg-subtle, #fff3cd);
    font-weight: 600;
}
</style>

<script>
// 전체 선택
document.addEventListener('DOMContentLoaded', function() {
    const checkAll = document.querySelector('input[name="chk_all"]');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="chk[]"]');
            checkboxes.forEach(function(checkbox) {
                checkbox.checked = checkAll.checked;
            });
        });
    }

    // 인라인 셀렉트(상태/계약유형) 변경 시 해당 행 체크박스 자동 체크 + 변경 표시 (회원 관리와 동일)
    var flist = document.getElementById('flist');
    if (flist) {
        // 각 셀렉트의 원래 값 저장 (되돌림 판정용)
        flist.querySelectorAll('select').forEach(function(sel) {
            sel.dataset.original = sel.value;
        });

        flist.addEventListener('change', function(e) {
            var el = e.target;
            if (!el || el.tagName !== 'SELECT') return;
            var row = el.closest('tr');
            if (!row) return;

            // 원래 값과 비교해 변경됨 표시 토글 (되돌리면 해제)
            var changed = el.value !== el.dataset.original;
            el.classList.toggle('list-changed', changed);

            // 행 내 변경된 셀렉트가 하나라도 있으면 체크, 모두 원복이면 해제
            var anyChanged = row.querySelector('select.list-changed') !== null;
            var cb = row.querySelector('input[name="chk[]"]');
            if (cb) cb.checked = anyChanged;
        });
    }

    // 드롭다운 열릴 때 table-responsive overflow 해제 (드롭다운이 잘리지 않도록)
    var tableWrap = document.getElementById('domainTableWrap');
    if (tableWrap) {
        tableWrap.addEventListener('show.bs.dropdown', function() { tableWrap.style.overflow = 'visible'; });
        tableWrap.addEventListener('hidden.bs.dropdown', function() { tableWrap.style.overflow = ''; });
    }

    // 대리 로그인 버튼
    document.querySelectorAll('.btn-proxy-login').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const domainId = this.dataset.id;
            const domainName = this.dataset.domain;

            MubloRequest.showConfirm('"' + domainName + '" 도메인에 관리자로 접속하시겠습니까?', function() {
                MubloRequest.requestJson('/admin/domains/proxy-login/' + domainId).then(function(data) {
                    if (data.data && data.data.redirect) {
                        window.open(data.data.redirect, '_blank');
                    }
                });
            }, { type: 'warning' });
        });
    });

    // 패키지 설정 링크 (대리 로그인 + 리다이렉트)
    document.querySelectorAll('.btn-proxy-settings').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const domainId = this.dataset.id;
            const redirectUrl = this.dataset.redirect;

            MubloRequest.requestJson('/admin/domains/proxy-login/' + domainId, { redirect: redirectUrl }).then(function(data) {
                if (data.data && data.data.redirect) {
                    window.open(data.data.redirect, '_blank');
                }
            });
        });
    });

    // 개별 삭제 버튼
    document.querySelectorAll('.btn-delete').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const domainId = this.dataset.id;
            const domainName = this.dataset.domain;

            MubloRequest.showConfirm('"' + domainName + '" 도메인을 삭제하시겠습니까?\n\n삭제된 도메인은 복구할 수 없습니다.', function() {
                MubloRequest.requestJson('/admin/domains/delete/' + domainId, {}, {
                    method: 'DELETE',
                    loading: true
                }).then(function(data) {
                    location.reload();
                }).catch(function(error) {
                    var message = (error && error.message) ? error.message : '삭제 중 오류가 발생했습니다.';
                    MubloRequest.showAlert(message, 'error');
                });
            }, { type: 'warning' });
        });
    });
});

// 일괄 수정 후 콜백
function afterBulkUpdate(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '수정되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '수정에 실패했습니다.', 'error');
    }
}

// 일괄 삭제 후 콜백
function afterBulkDelete(data) {
    if (data.result === 'success') {
        MubloRequest.showToast(data.message || '삭제되었습니다.', 'success');
        location.reload();
    } else {
        MubloRequest.showAlert(data.message || '삭제에 실패했습니다.', 'error');
    }
}
</script>
