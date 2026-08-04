<?php
/**
 * Admin Menu - Tab 1: 메뉴 아이템 목록
 *
 * Index.php에서 include되어 실행됨.
 * Index.php의 모든 PHP 변수에 접근 가능.
 *
 * @var array $items 메뉴 아이템 목록 (페이징 적용)
 * @var array $pagination 페이지네이션 정보
 * @var array $searchFields 검색 필드 옵션
 * @var array $currentSearch 현재 검색 조건
 * @var string $filterRaw 현재 제공자 필터 raw 값
 * @var array $providerOptions 제공자 옵션 ['plugin' => [...], 'package' => [...]]
 * @var object $columns ListColumnBuilder 빌드 결과 (Index.php에서 생성)
 */
?>
<!-- 검색 영역 -->
<form method="get" name="fsearch" id="fsearch" class="mb-2">
    <input type="hidden" name="tab" value="items">
    <div class="row align-items-center gy-2 gy-xl-0">
        <div class="col-auto">
            <span class="ov">
                <span class="ov-txt"><a href="/admin/menu?tab=items">전체</a></span>
                <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
            </span>
        </div>
        <div class="col col-xl-auto ms-xl-auto">
            <div class="row gx-2">
                <div class="col-auto">
                    <select name="provider_filter" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                        <option value="" <?= ($filterRaw ?? '') === '' ? 'selected' : '' ?>>전체</option>
                        <option value="core" <?= ($filterRaw ?? '') === 'core' ? 'selected' : '' ?>>Core</option>
                        <?php if (!empty($providerOptions['plugin'])): ?>
                        <optgroup label="Plugin">
                            <option value="plugin" <?= ($filterRaw ?? '') === 'plugin' ? 'selected' : '' ?>>└ 전체 Plugin</option>
                            <?php foreach ($providerOptions['plugin'] as $pName): ?>
                            <option value="plugin:<?= htmlspecialchars($pName) ?>"
                                <?= ($filterRaw ?? '') === 'plugin:' . $pName ? 'selected' : '' ?>>
                                └ <?= htmlspecialchars($pName) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                        <?php if (!empty($providerOptions['package'])): ?>
                        <optgroup label="Package">
                            <option value="package" <?= ($filterRaw ?? '') === 'package' ? 'selected' : '' ?>>└ 전체 Package</option>
                            <?php foreach ($providerOptions['package'] as $pName): ?>
                            <option value="package:<?= htmlspecialchars($pName) ?>"
                                <?= ($filterRaw ?? '') === 'package:' . $pName ? 'selected' : '' ?>>
                                └ <?= htmlspecialchars($pName) ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                </div>
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
                        <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/menu?tab=items'"></i>
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

<!-- 메뉴 아이템 목록 폼 -->
<form name="flist" id="flist">
    <!-- 테이블 -->
    <div class="table-responsive">
        <?= $this->listRenderHelper
            ->setColumns($columns)
            ->setRows($items)
            ->setSkin('table/basic')
            ->setWrapAttr(['class' => 'table table-hover align-middle'])
            ->setSort(
                $currentSort['field'] ?? null,
                $currentSort['order'] ?? 'DESC',
                array_filter([
                    'tab' => 'items',
                    'provider_filter' => $filterRaw ?? '',
                    'search_field' => $currentSearch['field'] ?? '',
                    'search_keyword' => $currentSearch['keyword'] ?? '',
                ], fn($v) => $v !== '')
            )
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
                    data-target="/admin/menu/list-modify"
                    data-callback="afterBulkUpdate"
                >
                    <i class="d-inline d-md-none bi bi-pencil-square"></i>
                    <span class="d-none d-md-inline">선택 수정</span>
                </button>
                <button
                    type="button"
                    class="btn btn-sm btn-default"
                    id="btn-bulk-delete"
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
