<?php
/**
 * Admin Boardcategory - Form
 *
 * 게시판 카테고리 생성/수정 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var bool $isEdit 수정 모드 여부
 * @var array|null $category 카테고리 데이터
 * @var int $boardCount 사용 게시판 수 (수정 시)
 */

$category = $category ?? [];
$isEdit = $isEdit ?? false;
?>
<form name="frm" id="frm">
<div class="page-container form-container">
    <div class="page-title">
        <div class="page-title-text">
            <h3><?= htmlspecialchars($pageTitle ?? '카테고리 추가') ?></h3>
            <p>
                <?php if ($isEdit): ?>
                카테고리 정보를 수정합니다.
                <?php if (($boardCount ?? 0) > 0): ?>
                <span class="text-info">(<?= $boardCount ?>개의 게시판에서 사용 중)</span>
                <?php endif; ?>
                <?php else: ?>
                새로운 카테고리를 생성합니다.
                <?php endif; ?>
            </p>
        </div>
        <div class="page-title-actions">
            <a href="/admin/board/category" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-list"></i> 목록
            </a>
            <button type="button"
                class="btn btn-sm btn-primary mublo-submit"
                data-target="/admin/board/category/store"
                data-callback="categorySaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>

    <!-- 숨김 필드 -->
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[category_id]" value="<?= $category['category_id'] ?? '' ?>">
    <?php endif; ?>

    <!-- 기본 정보 -->
    <div class="page-block">
        <div class="card">
            <div class="card-hero">
                <i class="bi bi-info-circle text-pastel-blue"></i>
                <span>기본 정보</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label">슬러그 <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="text"
                                   class="form-control"
                                   name="formData[category_slug]"
                                   id="category_slug"
                                   value="<?= htmlspecialchars($category['category_slug'] ?? '') ?>"
                                   pattern="[a-z0-9\-]+"
                                   placeholder="general"
                                   required
                                   <?= $isEdit && ($boardCount ?? 0) > 0 ? 'readonly' : '' ?>>
                            <button type="button" class="btn btn-outline-secondary" id="btn-check-slug">
                                중복확인
                            </button>
                        </div>
                        <div class="form-text">영문 소문자, 숫자, 하이픈(-) 사용 가능</div>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label class="form-label">카테고리명 <span class="text-danger">*</span></label>
                        <input type="text"
                               class="form-control"
                               name="formData[category_name]"
                               value="<?= htmlspecialchars($category['category_name'] ?? '') ?>"
                               placeholder="일반"
                               required>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">카테고리 설명</label>
                    <input type="text"
                           class="form-control"
                           name="formData[category_description]"
                           value="<?= htmlspecialchars($category['category_description'] ?? '') ?>"
                           placeholder="카테고리에 대한 간단한 설명 (선택사항)">
                </div>
                <div class="mb-3">
                    <label class="form-label">상태</label>
                    <div class="form-check form-switch">
                        <input type="checkbox"
                               class="form-check-input"
                               name="formData[is_active]"
                               id="is_active"
                               value="1"
                               <?= ($category['is_active'] ?? true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="is_active">사용</label>
                    </div>
                    <div class="form-text">비활성화된 카테고리는 게시판에서 선택할 수 없습니다.</div>
                </div>
            </div>
        </div>
    </div>

</div>
</form>

<script>
// 저장 완료 콜백
MubloRequest.registerCallback('categorySaved', function(response) {
    if (response.result === 'success') {
        alert(response.message || '저장되었습니다.');
        if (response.data && response.data.redirect) {
            location.href = response.data.redirect;
        } else {
            location.href = '/admin/board/category';
        }
    } else {
        alert(response.message || '저장에 실패했습니다.');
    }
});

// 슬러그 중복 확인
document.getElementById('btn-check-slug')?.addEventListener('click', function() {
    const slugInput = document.getElementById('category_slug');
    const slug = slugInput.value.trim();

    if (!slug) {
        alert('슬러그를 입력해주세요.');
        slugInput.focus();
        return;
    }

    // 형식 검증
    if (!/^[a-z0-9-]+$/.test(slug)) {
        alert('슬러그는 영문 소문자, 숫자, 하이픈만 사용 가능합니다.');
        return;
    }

    const excludeId = document.querySelector('input[name="formData[category_id]"]')?.value || 0;

    MubloRequest.requestJson('/admin/board/category/check-slug', {
        slug: slug,
        exclude_id: parseInt(excludeId)
    }).then(response => {
        if (response.result === 'success') {
            alert(response.message || '사용 가능한 슬러그입니다.');
        } else {
            alert(response.message || '사용할 수 없는 슬러그입니다.');
        }
    }).catch(err => {
        alert('확인 중 오류가 발생했습니다.');
        console.error(err);
    });
});

// 슬러그 입력 시 소문자 변환
document.getElementById('category_slug')?.addEventListener('input', function() {
    this.value = this.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
});
</script>
