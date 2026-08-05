<?php
/**
 * Admin Boardarticle - Index
 *
 * 게시글 관리 목록
 *
 * View Context 접근:
 * - $this->columns() : ListColumnBuilder 팩토리
 * - $this->listRenderHelper : ListRenderHelper 인스턴스
 * - $this->pagination($data) : 페이지네이션 렌더링
 *
 * @var string $pageTitle 페이지 제목
 * @var array $articles 게시글 목록
 * @var array $pagination 페이지네이션 정보
 * @var array $filters 필터 조건
 * @var array $boards 게시판 목록
 * @var array $statusOptions 상태 옵션
 * @var array $searchFieldOptions 검색 필드 옵션
 */

// 컬럼 정의
$columns = $this->columns()
    ->checkbox('chk', '', ['id_key' => 'article_id', '_th_attr' => ['style' => 'width:40px', 'class' => 'text-center'], '_cell_attr' => ['class' => 'text-center text-nowrap']])
    ->add('article_id', 'ID', [
        'render' => fn($row) => '<small class="text-muted">#' . $row['article_id'] . '</small>',
        '_th_attr' => ['style' => 'width:60px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->add('board_name', '게시판', [
        'render' => fn($row) => '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . htmlspecialchars($row['board_name'] ?? '-') . '</span>',
        '_th_attr' => ['style' => 'width:100px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->add('title', '제목', [
        'render' => function($row) {
            $html = '';
            if ($row['is_notice']) {
                $html .= '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle me-1">공지</span>';
            }
            if ($row['is_secret']) {
                $html .= '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle me-1">비밀</span>';
            }
            // 글 개별 레벨이 게시판 정책보다 낮으면 그 글만 권한이 풀려 있다는 뜻이다.
            // 화면에 드러나지 않으면 신고를 받고서야 알게 된다.
            // 키가 없으면 조용히 건너뛴다 — ErrorHandler 가 notice 도 예외로 올리므로
            // 컬럼이 빠진 데이터로 이 뷰를 그리면 배지가 아니라 목록 전체가 죽는다.
            foreach ([['read_level', 'board_read_level', '읽기'], ['download_level', 'board_download_level', '다운로드']] as [$key, $boardKey, $label]) {
                $articleLevel = $row[$key] ?? null;
                $boardLevel = $row[$boardKey] ?? null;
                if ($articleLevel === null || $boardLevel === null) {
                    continue;
                }
                if ((int) $articleLevel < (int) $boardLevel) {
                    $html .= '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle me-1"'
                        . ' title="게시판 설정(Lv.' . (int) $boardLevel . ')보다 낮은 개별 설정(Lv.' . (int) $articleLevel . ')">'
                        . $label . ' 권한 열림</span>';
                }
            }
            $title = mb_substr($row['title'], 0, 50);
            $title .= mb_strlen($row['title']) > 50 ? '...' : '';
            $html .= '<a href="/admin/board/article/view?id=' . $row['article_id'] . '" class="text-decoration-none">' . htmlspecialchars($title) . '</a>';
            $slug = htmlspecialchars($row['board_slug'] ?? '', ENT_QUOTES);
            if ($slug !== '') {
                $html .= ' <a href="/board/' . $slug . '/view/' . $row['article_id'] . '" target="_blank" rel="noopener"'
                    . ' class="board-ext-link" title="프론트 게시글 새창으로 보기"><i class="bi bi-box-arrow-up-right"></i></a>';
            }
            return $html;
        }
    ])
    ->add('author', '작성자', [
        'render' => function($row) {
            $name = $row['author_name'] ?? null;
            if ($name) {
                $isMember = !empty($row['member_id']);
                return $isMember
                    ? htmlspecialchars($name)
                    : '<span class="text-muted">' . htmlspecialchars($name) . '</span>';
            }
            return '<span class="text-muted">-</span>';
        },
        '_th_attr' => ['style' => 'width:120px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->add('view_count', '조회', [
        'render' => fn($row) => '<small class="text-muted">' . number_format($row['view_count'] ?? 0) . '</small>',
        '_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center text-nowrap']
    ])
    ->add('comment_count', '댓글', [
        'render' => fn($row) => '<small class="text-muted">' . number_format($row['comment_count'] ?? 0) . '</small>',
        '_th_attr' => ['style' => 'width:60px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center text-nowrap']
    ])
    ->add('status', '상태', [
        'render' => function($row) {
            $class = match ($row['status']) {
                'published' => 'success',
                'draft' => 'warning',
                'deleted' => 'secondary',
                default => 'secondary',
            };
            $label = match ($row['status']) {
                'published' => '발행',
                'draft' => '임시',
                'deleted' => '삭제',
                default => $row['status'],
            };
            return '<span class="badge bg-' . $class . '-subtle text-' . $class . '-emphasis border border-' . $class . '-subtle">' . $label . '</span>';
        },
        '_th_attr' => ['style' => 'width:70px', 'class' => 'text-center'],
        '_td_attr' => ['class' => 'text-center text-nowrap']
    ])
    ->add('created_at', '작성일', [
        'render' => fn($row) => '<small class="text-muted">' . date('Y-m-d H:i', strtotime($row['created_at'])) . '</small>',
        '_th_attr' => ['style' => 'width:160px'],
        '_td_attr' => ['class' => 'text-nowrap']
    ])
    ->actions('actions', '관리', function($row) {
        $id = $row['article_id'];
        return '
            <a href="/admin/board/article/view?id=' . $id . '" class="btn btn-sm btn-default">보기</a>
            <a href="/admin/board/article/edit?id=' . $id . '" class="btn btn-sm btn-default">수정</a>
        ';
    }, ['_th_attr' => ['style' => 'width:110px'], '_td_attr' => ['class' => 'text-nowrap']])
    ->build();

// 게시판 옵션 생성
$boardOptions = [];
foreach ($boards as $item) {
    $groupName = $item['group_name'] ?: '-';
    $boardOptions[$item['config']->getBoardId()] = "[{$groupName}] " . $item['config']->getBoardName();
}
?>
<style>
.board-ext-link {
    color: var(--bs-secondary-color, #adb5bd);
    opacity: .4;
    text-decoration: none;
    transition: opacity .12s, color .12s;
}
.board-ext-link:hover {
    opacity: 1;
    color: var(--bs-default-bg, #18181b);
}
</style>
<div class="page-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '게시글 관리') ?></h3>
            <p>전체 게시글을 관리합니다.</p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/article/create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg"></i> 글쓰기
            </a>
        </div>
    </div>

    <div class="page-block">
        <!-- 검색 영역 -->
        <form method="get" name="fsearch" id="fsearch" class="mb-2">
            <div class="row align-items-center gy-2 gy-xl-0">
                <div class="col-auto">
                    <span class="ov">
                        <span class="ov-txt"><a href="/admin/board/article">전체</a></span>
                        <span class="ov-num"><b><?= number_format($pagination['totalItems'] ?? 0) ?></b> 개</span>
                    </span>
                </div>
                <div class="col col-xl-auto ms-xl-auto">
                    <div class="row gx-2">
                        <div class="col col-xl-auto">
                            <select name="board_id" class="form-select form-select-sm">
                                <option value="">전체 게시판</option>
                                <?php foreach ($boardOptions as $boardId => $boardName): ?>
                                <option value="<?= $boardId ?>" <?= ($filters['board_id'] ?? '') == $boardId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($boardName) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach ($statusOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <select name="search_field" class="form-select form-select-sm">
                                <?php foreach ($searchFieldOptions as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($filters['search_field'] ?? '') === $value ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($label) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col col-xl-auto">
                            <div class="search-wrapper">
                                <label for="search_keyword" class="visually-hidden">검색</label>
                                <input type="text" name="keyword" id="search_keyword" class="form-control form-control-sm"
                                       placeholder="검색어 입력"
                                       value="<?= htmlspecialchars($filters['keyword'] ?? '') ?>">
                                <i class="bi bi-search search-icon"></i>
                                <?php if (!empty($filters['keyword'])): ?>
                                <i class="bi bi-x-lg search-reset-icon" onclick="location.href='/admin/board/article'"></i>
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

        <!-- 게시글 목록 -->
        <form name="flist" id="flist">
            <div class="table-responsive">
                <?= $this->listRenderHelper
                    ->setColumns($columns)
                    ->setRows($articles)
                    ->setSkin('table/basic')
                    ->setWrapAttr(['class' => 'table table-hover align-middle'])
                    ->showHeader(true)
                    ->render() ?>
            </div>

            <!-- 하단 액션바 + 페이지네이션 -->
            <div class="row gx-2 justify-content-between align-items-center my-2">
                <div class="col-auto">
                    <div class="d-flex gap-1">
                        <select class="form-select form-select-sm border-default" name="bulk_action" style="width:auto;">
                            <option value="">상태 변경</option>
                            <option value="published">발행</option>
                            <option value="draft">임시저장</option>
                            <option value="deleted">삭제</option>
                        </select>
                        <button type="button" class="btn btn-outline-default btn-sm" onclick="bulkStatusUpdate()">
                            <span class="d-none d-md-inline">적용</span>
                        </button>
                        <button type="button" class="btn btn-default btn-sm" onclick="bulkDelete()">
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
});

// 선택된 ID 가져오기
function getSelectedIds() {
    const checked = document.querySelectorAll('input[name="chk[]"]:checked');
    return Array.from(checked).map(c => parseInt(c.value));
}

// 상태 일괄 변경
function bulkStatusUpdate() {
    const action = document.querySelector('select[name="bulk_action"]').value;
    if (!action) {
        alert('상태를 선택해주세요.');
        return;
    }

    const articleIds = getSelectedIds();
    if (articleIds.length === 0) {
        alert('게시글을 선택해주세요.');
        return;
    }

    const statusLabels = { 'published': '발행', 'draft': '임시저장', 'deleted': '삭제' };
    if (!confirm(`선택한 ${articleIds.length}개의 게시글을 "${statusLabels[action]}"(으)로 변경하시겠습니까?`)) {
        return;
    }

    MubloRequest.requestJson('/admin/board/article/bulk-status-update', {
        article_ids: articleIds,
        status: action
    }).then(response => {
        if (response.result === 'success') {
            alert(response.message || '처리되었습니다.');
            location.reload();
        } else {
            alert(response.message || '처리에 실패했습니다.');
        }
    }).catch(err => {
        alert('오류가 발생했습니다.');
        console.error(err);
    });
}

// 선택 삭제
function bulkDelete() {
    const articleIds = getSelectedIds();
    if (articleIds.length === 0) {
        alert('삭제할 게시글을 선택해주세요.');
        return;
    }

    if (!confirm(`선택한 ${articleIds.length}개의 게시글을 삭제하시겠습니까?`)) {
        return;
    }

    MubloRequest.requestJson('/admin/board/article/bulk-delete', {
        article_ids: articleIds
    }).then(response => {
        if (response.result === 'success') {
            alert(response.message || '삭제되었습니다.');
            location.reload();
        } else {
            alert(response.message || '삭제에 실패했습니다.');
        }
    }).catch(err => {
        alert('오류가 발생했습니다.');
        console.error(err);
    });
}
</script>
