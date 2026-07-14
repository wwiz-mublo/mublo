<?php
/**
 * 기본 정보 설정 섹션
 *
 * @var array $editorOptions 에디터 옵션
 * @var array $siteConfig 사이트 설정 (레이아웃 포함)
 */
?>
<!-- 사이트 정보 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-globe text-pastel-blue"></i>
        <span>사이트 정보</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="site_title" class="form-label">사이트명 <span class="text-danger">*</span></label>
                <input type="text" name="formData[site][site_title]" value="" id="site_title" class="form-control" placeholder="사이트명">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="site_subtitle" class="form-label">사이트 부제</label>
                <input type="text" name="formData[site][site_subtitle]" value="" id="site_subtitle" class="form-control" placeholder="사이트 부제">
            </div>
        </div>
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="admin_email" class="form-label">관리자 이메일</label>
                <input type="email" name="formData[site][admin_email]" value="" id="admin_email" class="form-control" placeholder="admin@example.com">
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label d-block">알림 이메일 발송</label>
                <div class="form-check form-switch">
                    <input type="checkbox" class="form-check-input" id="email_channel_enabled"
                           name="formData[site][email_channel_enabled]" value="1"
                           <?= !empty($siteConfig['email_channel_enabled'] ?? true) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="email_channel_enabled">알림 이메일 발송 활성화</label>
                </div>
                <div class="form-text">비활성화 시 알림 이메일(주문·폼 접수·상태 변경 등) 발송이 중단됩니다. 비밀번호 재설정 등 계정 필수 메일은 계속 발송됩니다.</div>
            </div>
        </div>
    </div>
</div>

<!-- 기본 설정 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-gear text-pastel-purple"></i>
        <span>기본 설정</span>
    </div>
    <div class="card-body">
        <div class="row gy-3 gy-md-0">
            <div class="col-12 col-sm-6 col-md-4">
                <label for="editor" class="form-label">에디터</label>
                <select name="formData[site][editor]" id="editor" class="form-select">
                    <?php foreach ($editorOptions ?? ['mublo-editor' => 'Mublo Editor'] as $value => $label): ?>
                    <option value="<?= htmlspecialchars($value) ?>" <?= ($siteConfig['editor'] ?? 'mublo-editor') === $value ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">게시판 등에서 사용할 에디터</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="per_page" class="form-label">페이지당 목록 수</label>
                <select name="formData[site][per_page]" id="per_page" class="form-select">
                    <?php foreach ([10, 15, 20, 30, 50] as $n): ?>
                    <option value="<?= $n ?>" <?= ($siteConfig['per_page'] ?? 20) == $n ? 'selected' : '' ?>><?= $n ?>개</option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">게시판 등 목록 기본 표시 개수</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label for="use_email_as_userid" class="form-label">아이디 설정</label>
                <select name="formData[site][use_email_as_userid]" id="use_email_as_userid" class="form-select">
                    <option value="0" <?= empty($siteConfig['use_email_as_userid']) ? 'selected' : '' ?>>영문, 숫자, 밑줄(_)만 허용</option>
                    <option value="1" <?= !empty($siteConfig['use_email_as_userid']) ? 'selected' : '' ?>>이메일만 허용</option>
                </select>
                <div class="form-text">회원 아이디 입력 형식</div>
            </div>
        </div>
        <div class="row gy-3 gy-md-0 mt-3">
            <div class="col-12 col-sm-6 col-md-3">
                <label for="join_type" class="form-label">가입 방식</label>
                <select name="formData[site][join_type]" id="join_type" class="form-select">
                    <option value="immediate" <?= ($siteConfig['join_type'] ?? 'immediate') === 'immediate' ? 'selected' : '' ?>>바로 가입</option>
                    <option value="approval" <?= ($siteConfig['join_type'] ?? 'immediate') === 'approval' ? 'selected' : '' ?>>관리자 승인</option>
                </select>
                <div class="form-text">가입 즉시 활성화 또는 승인 필요</div>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label for="default_level_value" class="form-label">가입 시 기본 레벨</label>
                <select name="formData[site][default_level_value]" id="default_level_value" class="form-select">
                    <?php foreach ($levels ?? [] as $level): ?>
                    <option value="<?= (int)($level['level_value'] ?? 0) ?>"
                        <?= ($siteConfig['default_level_value'] ?? 1) == ($level['level_value'] ?? 0) ? 'selected' : '' ?>>
                        Lv<?= (int)($level['level_value'] ?? 0) ?> <?= htmlspecialchars($level['level_name'] ?? '') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">신규 가입 회원 레벨</div>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label for="password_min_length" class="form-label">비밀번호 최소 글자</label>
                <input type="number" name="formData[site][password_min_length]" id="password_min_length"
                       class="form-control" min="4" max="32"
                       value="<?= (int)($siteConfig['password_min_length'] ?? 6) ?>">
                <div class="form-text">가입·변경 시 적용</div>
            </div>
            <div class="col-12 col-sm-6 col-md-5">
                <label class="form-label d-block">비밀번호 복잡도 규칙 (선택)</label>
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="password_require_lower"
                           name="formData[site][password_require_lower]" value="1"
                           <?= !empty($siteConfig['password_require_lower']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="password_require_lower">소문자</label>
                </div>
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="password_require_number"
                           name="formData[site][password_require_number]" value="1"
                           <?= !empty($siteConfig['password_require_number']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="password_require_number">숫자</label>
                </div>
                <div class="form-check form-check-inline">
                    <input type="checkbox" class="form-check-input" id="password_require_special"
                           name="formData[site][password_require_special]" value="1"
                           <?= !empty($siteConfig['password_require_special']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="password_require_special">특수문자</label>
                </div>
                <div class="form-text">체크한 문자 종류를 반드시 포함 (기존 로그인 영향 없음)</div>
            </div>
        </div>
    </div>
</div>

<!-- 레이아웃 설정 -->
<div class="card mb-4">
    <div class="card-hero">
        <i class="bi bi-layout-three-columns text-pastel-sky"></i>
        <span>레이아웃 설정</span>
    </div>
    <div class="card-body">
        <!-- 레이아웃 형태 -->
        <div class="mb-3">
            <label class="form-label">레이아웃 형태</label>
            <div class="form-text mb-2">PC 환경의 페이지 레이아웃입니다. 모바일에서는 적용되지 않습니다.</div>
            <div class="d-flex flex-wrap gap-3" id="layout-type-selector">
                <label class="layout-pick">
                    <input type="radio" name="formData[site][layout_type]" value="full">
                    <div class="layout-pick__box">
                        <div class="layout-pick__content"></div>
                    </div>
                    <small>전체</small>
                </label>
                <label class="layout-pick">
                    <input type="radio" name="formData[site][layout_type]" value="left-sidebar">
                    <div class="layout-pick__box">
                        <div class="layout-pick__sidebar"></div>
                        <div class="layout-pick__content"></div>
                    </div>
                    <small>좌측 사이드바</small>
                </label>
                <label class="layout-pick">
                    <input type="radio" name="formData[site][layout_type]" value="right-sidebar">
                    <div class="layout-pick__box">
                        <div class="layout-pick__content"></div>
                        <div class="layout-pick__sidebar"></div>
                    </div>
                    <small>우측 사이드바</small>
                </label>
                <label class="layout-pick">
                    <input type="radio" name="formData[site][layout_type]" value="three-column">
                    <div class="layout-pick__box">
                        <div class="layout-pick__sidebar"></div>
                        <div class="layout-pick__content" style="min-width: 30px;"></div>
                        <div class="layout-pick__sidebar"></div>
                    </div>
                    <small>3단</small>
                </label>
            </div>
        </div>

        <!-- 사이드바 설정 -->
        <div class="d-flex flex-wrap gap-4 mb-3" id="sidebar-width-options" style="display:none">
            <div id="left-width-group">
                <label class="form-label">좌측 사이드바</label>
                <div class="d-flex gap-3 align-items-center">
                    <div class="input-group" style="max-width:160px">
                        <input type="number" name="formData[site][layout_left_width]" class="form-control" min="100" max="500" value="250">
                        <span class="input-group-text">px</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" class="form-check-input" name="formData[site][sidebar_left_mobile]" value="1" id="sidebar_left_mobile">
                        <label class="form-check-label text-nowrap" for="sidebar_left_mobile">모바일 출력</label>
                    </div>
                </div>
            </div>
            <div id="right-width-group">
                <label class="form-label">우측 사이드바</label>
                <div class="d-flex gap-3 align-items-center">
                    <div class="input-group" style="max-width:160px">
                        <input type="number" name="formData[site][layout_right_width]" class="form-control" min="100" max="500" value="250">
                        <span class="input-group-text">px</span>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input type="checkbox" class="form-check-input" name="formData[site][sidebar_right_mobile]" value="1" id="sidebar_right_mobile">
                        <label class="form-check-label text-nowrap" for="sidebar_right_mobile">모바일 출력</label>
                    </div>
                </div>
            </div>
        </div>

        <hr>

        <!-- 넓이 설정 -->
        <div class="row gy-3 gy-md-0 mb-3">
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">사이트 최대 넓이</label>
                <div class="input-group">
                    <input type="number" name="formData[site][layout_max_width]" class="form-control" min="0" max="2560" value="1200">
                    <span class="input-group-text">px</span>
                </div>
                <div class="form-text">헤더, 푸터를 포함한 전체 넓이 (0 = 제한 없음)</div>
            </div>
            <div class="col-12 col-sm-6 col-md-4">
                <label class="form-label">내용 최대 넓이</label>
                <div class="input-group">
                    <input type="number" name="formData[site][content_max_width]" class="form-control" min="0" max="2560" value="0">
                    <span class="input-group-text">px</span>
                </div>
                <div class="form-text">본문 영역 넓이 (0 = 레이아웃에 맞춤)</div>
            </div>
        </div>

        <!-- 메인화면 레이아웃 -->
        <div class="mb-2">
            <div class="form-check form-switch">
                <input type="checkbox" class="form-check-input" name="formData[site][use_main_layout]" value="1" id="use_main_layout">
                <label class="form-check-label" for="use_main_layout">메인화면에 레이아웃 적용</label>
            </div>
            <div class="form-text">OFF 시 메인화면은 사이드바 없이 전체 넓이로 표시됩니다.</div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var radios = document.querySelectorAll('input[name="formData[site][layout_type]"]');
    var sidebarOptions = document.getElementById('sidebar-width-options');
    var leftGroup = document.getElementById('left-width-group');
    var rightGroup = document.getElementById('right-width-group');

    function updateSidebarVisibility() {
        var selected = document.querySelector('input[name="formData[site][layout_type]"]:checked');
        if (!selected || !sidebarOptions) return;
        var val = selected.value;

        var showLeft = (val === 'left-sidebar' || val === 'three-column');
        var showRight = (val === 'right-sidebar' || val === 'three-column');

        sidebarOptions.style.display = (showLeft || showRight) ? '' : 'none';
        if (leftGroup) leftGroup.style.display = showLeft ? '' : 'none';
        if (rightGroup) rightGroup.style.display = showRight ? '' : 'none';
    }

    radios.forEach(function(radio) {
        radio.addEventListener('change', updateSidebarVisibility);
    });

    // 초기 상태 (fillFormData 이후 실행을 위해 약간 지연)
    setTimeout(updateSidebarVisibility, 50);
});
</script>
