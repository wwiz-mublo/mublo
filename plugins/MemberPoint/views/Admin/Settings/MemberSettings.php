<?php
/**
 * MemberPoint Plugin - 관리자 회원포인트 설정
 *
 * @var string $pageTitle
 * @var array  $settings      카테고리별 설정 ['member' => [...], 'board' => [...]]
 * @var array  $actionLabels  카테고리별 라벨
 * @var array  $levels        회원 레벨 목록
 */

$memberSettings = $settings['member'] ?? [];
?>

<form name="frm" id="frm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>회원 가입, 레벨업 등 회원 활동에 대한 포인트 적립 설정을 관리합니다.</p>
            </div>
        </div>

        <div class="page-block">
            <div class="card mb-4">
                <div class="card-hero">
                    <i class="bi bi-people text-pastel-blue"></i>
                    <span>회원 활동 포인트</span>
                </div>
                <div class="card-body">
                    <table class="table table-bordered align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:140px">액션</th>
                                <th style="width:80px" class="text-center">사용</th>
                                <th>설정</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- 회원가입 -->
                            <?php $signup = $memberSettings['signup'] ?? ['enabled' => true, 'point' => 1000]; ?>
                            <tr>
                                <td>회원가입</td>
                                <td class="text-center">
                                    <input type="hidden" name="formData[member][signup][enabled]" value="0">
                                    <input type="checkbox" class="form-check-input"
                                           name="formData[member][signup][enabled]" value="1"
                                           <?= !empty($signup['enabled']) ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <input type="number" class="form-control form-control-sm" style="width:120px"
                                               name="formData[member][signup][point]"
                                               value="<?= (int) ($signup['point'] ?? 1000) ?>" min="0">
                                        <span class="text-muted">P</span>
                                    </div>
                                </td>
                            </tr>
                            <!-- 레벨업 -->
                            <?php $levelUp = $memberSettings['level_up'] ?? ['enabled' => false, 'levels' => []]; ?>
                            <tr>
                                <td>레벨업</td>
                                <td class="text-center">
                                    <input type="hidden" name="formData[member][level_up][enabled]" value="0">
                                    <input type="checkbox" class="form-check-input"
                                           name="formData[member][level_up][enabled]" value="1"
                                           <?= !empty($levelUp['enabled']) ? 'checked' : '' ?>>
                                </td>
                                <td>
                                    <?php if (empty($levels)): ?>
                                        <span class="text-muted">등록된 레벨이 없습니다.</span>
                                    <?php else: ?>
                                        <?php foreach ($levels as $level): ?>
                                        <?php if (($level['level_value'] ?? 0) <= 1) continue; ?>
                                        <div class="d-flex align-items-center gap-2 mb-1">
                                            <span style="min-width:140px">
                                                Lv<?= $level['level_value'] ?>
                                                <?= htmlspecialchars($level['level_name'] ?? '') ?>
                                            </span>
                                            <input type="number" class="form-control form-control-sm" style="width:100px"
                                                   name="formData[member][level_up][levels][<?= $level['level_value'] ?>]"
                                                   value="<?= (int) ($levelUp['levels'][$level['level_value']] ?? 0) ?>"
                                                   min="0">
                                            <span class="text-muted">P</span>
                                        </div>
                                        <?php endforeach; ?>
                                        <div class="form-text">0 입력 시 해당 레벨은 포인트 미지급</div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- 하단 저장 버튼 -->
        <div class="sticky-act sticky-status">
            <button type="button" class="btn btn-primary mublo-submit"
                    data-target="/admin/member-point/member-settings"
                    data-callback="memberSettingsSaved">
                <i class="bi bi-check-lg"></i> 저장
            </button>
        </div>
    </div>
</form>

<script>
(function() {
    MubloRequest.registerCallback('memberSettingsSaved', function(response) {
        if (response.result === 'success') {
            alert(response.message || '설정이 저장되었습니다.');
        }
    });
})();
</script>
