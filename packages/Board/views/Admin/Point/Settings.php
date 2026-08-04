<?php
/**
 * Board Package - 관리자 포인트 설정
 *
 * @var string $pageTitle
 * @var array  $settings      포인트 설정
 * @var array  $actionLabels  액션별 라벨
 * @var array  $earnActions   적립 액션 목록
 * @var array  $consumeActions 소비 액션 목록
 * @var array  $groups        게시판 그룹 목록
 * @var array  $boards        게시판 목록 (그룹 포함)
 * @var array  $groupConfigs  그룹별 설정 현황 [group_id => config, ...]
 * @var array  $boardConfigs  게시판별 설정 현황 [board_id => config, ...]
 */
?>

<form name="frm" id="frm">
    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle) ?></h3>
                <p>게시글 작성, 댓글, 반응 등 게시판 활동에 대한 포인트 설정을 관리합니다.</p>
            </div>
        </div>

        <div class="page-block sticky-spy">
            <div class="sticky-top">
                <nav id="board-nav" class="navbar">
                    <ul class="nav nav-tabs w-100">
                        <li class="nav-item">
                            <a class="nav-link active" href="#anc_default">기본 설정</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#anc_group">그룹별 설정</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="#anc_board">게시판별 설정</a>
                        </li>
                    </ul>
                </nav>
            </div>

            <div class="sticky-section" data-bs-spy="scroll" data-bs-target="#board-nav">
                <!-- 기본 설정 -->
                <section id="anc_default" data-section="anc_default">
                    <h5 class="mb-4">기본 설정</h5>

                    <div class="card mb-4">
                        <div class="card-hero">
                            <i class="bi bi-coin text-pastel-blue"></i>
                            <span>게시판 포인트 (기본값)</span>
                        </div>
                        <div class="card-body">
                            <h6 class="mb-3 text-success"><i class="bi bi-plus-circle"></i> 포인트 적립</h6>
                            <div class="row gy-3 mb-4">
                                <?php foreach ($earnActions as $action): ?>
                                <?php $cfg = $settings[$action] ?? ['enabled' => false, 'point' => 0, 'revoke' => false]; ?>
                                <div class="col-12 col-sm-6 col-md-3">
                                    <label class="form-label"><?= htmlspecialchars($actionLabels[$action] ?? $action) ?></label>
                                    <div class="input-group mb-1">
                                        <input type="number" class="form-control"
                                               name="formData[<?= $action ?>][point]"
                                               value="<?= (int) ($cfg['point'] ?? 0) ?>" min="0" placeholder="0">
                                        <span class="input-group-text">P</span>
                                    </div>
                                    <div class="d-flex gap-3">
                                        <div class="form-check form-check-inline mb-0">
                                            <input type="hidden" name="formData[<?= $action ?>][enabled]" value="0">
                                            <input type="checkbox" class="form-check-input" id="earn_<?= $action ?>_enabled"
                                                   name="formData[<?= $action ?>][enabled]" value="1"
                                                   <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="earn_<?= $action ?>_enabled">사용</label>
                                        </div>
                                        <div class="form-check form-check-inline mb-0">
                                            <input type="hidden" name="formData[<?= $action ?>][revoke]" value="0">
                                            <input type="checkbox" class="form-check-input" id="earn_<?= $action ?>_revoke"
                                                   name="formData[<?= $action ?>][revoke]" value="1"
                                                   <?= !empty($cfg['revoke']) ? 'checked' : '' ?>>
                                            <label class="form-check-label small" for="earn_<?= $action ?>_revoke">삭제 시 회수</label>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <h6 class="mb-3 text-danger"><i class="bi bi-dash-circle"></i> 포인트 소비 <small class="text-muted fw-normal">(부족 시 접근 차단)</small></h6>
                            <div class="row gy-3 mb-3">
                                <?php foreach ($consumeActions as $action): ?>
                                <?php $cfg = $settings[$action] ?? ['enabled' => false, 'point' => 0]; ?>
                                <div class="col-12 col-sm-6 col-md-3">
                                    <label class="form-label"><?= htmlspecialchars($actionLabels[$action] ?? $action) ?></label>
                                    <div class="input-group mb-1">
                                        <input type="number" class="form-control"
                                               name="formData[<?= $action ?>][point]"
                                               value="<?= (int) ($cfg['point'] ?? 0) ?>" min="0" placeholder="0">
                                        <span class="input-group-text">P</span>
                                    </div>
                                    <div class="form-check mb-0">
                                        <input type="hidden" name="formData[<?= $action ?>][enabled]" value="0">
                                        <input type="checkbox" class="form-check-input" id="consume_<?= $action ?>_enabled"
                                               name="formData[<?= $action ?>][enabled]" value="1"
                                               <?= !empty($cfg['enabled']) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="consume_<?= $action ?>_enabled">사용</label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <p class="text-muted small mb-3">
                                * 읽기/다운로드: 한번 지불한 글/파일은 재차감 없이 접근 가능<br>
                                * 본인 작성 글 열람 시 포인트 차감 없음
                            </p>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-primary mublo-submit"
                                        data-target="/admin/board/point"
                                        data-callback="boardPointSaved">
                                    <i class="bi bi-check-lg"></i> 저장
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- 그룹별 설정 -->
                <section id="anc_group" data-section="anc_group">
                    <h5 class="mb-4">그룹별 설정</h5>

                    <div class="card mb-4">
                        <div class="card-hero">
                            <i class="bi bi-collection text-pastel-green"></i>
                            <span>게시판 그룹 선택</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <select id="groupSelect" class="form-select" style="max-width:300px">
                                    <option value="">그룹을 선택하세요</option>
                                    <?php foreach ($groups as $group): ?>
                                    <option value="<?= $group['group_id'] ?>"
                                            <?= isset($groupConfigs[$group['group_id']]) ? 'data-has-config="1"' : '' ?>>
                                        <?= htmlspecialchars($group['group_name'] ?? '') ?>
                                        <?= isset($groupConfigs[$group['group_id']]) ? ' (설정됨)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">그룹별로 기본값과 다른 포인트를 설정할 수 있습니다.</div>
                            </div>
                            <div id="groupConfigArea" style="display:none"></div>
                        </div>
                    </div>
                </section>

                <!-- 게시판별 설정 -->
                <section id="anc_board" data-section="anc_board">
                    <h5 class="mb-4">게시판별 설정</h5>

                    <div class="card mb-4">
                        <div class="card-hero">
                            <i class="bi bi-kanban text-pastel-purple"></i>
                            <span>게시판 선택</span>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <select id="boardSelect" class="form-select" style="max-width:300px">
                                    <option value="">게시판을 선택하세요</option>
                                    <?php
                                    $currentGroup = null;
                                    foreach ($boards as $bArr):
                                        $gName = $bArr['group_name'] ?? '';
                                        if ($gName !== $currentGroup):
                                            if ($currentGroup !== null) echo '</optgroup>';
                                            $currentGroup = $gName;
                                            echo '<optgroup label="' . htmlspecialchars($gName) . '">';
                                        endif;
                                    ?>
                                    <option value="<?= $bArr['board_id'] ?>"
                                            <?= isset($boardConfigs[$bArr['board_id']]) ? 'data-has-config="1"' : '' ?>>
                                        <?= htmlspecialchars($bArr['board_name'] ?? '') ?>
                                        <?= isset($boardConfigs[$bArr['board_id']]) ? ' (설정됨)' : '' ?>
                                    </option>
                                    <?php endforeach; ?>
                                    <?php if ($currentGroup !== null) echo '</optgroup>'; ?>
                                </select>
                                <div class="form-text">게시판별로 기본값과 다른 포인트를 설정할 수 있습니다.</div>
                            </div>
                            <div id="boardConfigArea" style="display:none"></div>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</form>

<script>
(function() {
    var actionLabels   = <?= json_encode($actionLabels, JSON_UNESCAPED_UNICODE) ?>;
    var earnActions    = <?= json_encode($earnActions) ?>;
    var consumeActions = <?= json_encode($consumeActions) ?>;

    function renderScopeForm(scopeType, scopeId, config, defaults, enabledReactions) {
        var reactionPoints = (config && config.reaction_points) ? config.reaction_points : {};
        var html = '<form id="' + scopeType + 'ConfigForm">';

        // 적립 액션
        html += '<h6 class="mb-3 text-success"><i class="bi bi-plus-circle"></i> 포인트 적립</h6>';
        html += '<div class="row gy-3 mb-4">';
        earnActions.forEach(function(action) {
            if (action === 'reaction_received' && scopeType === 'board' && (!enabledReactions || Object.keys(enabledReactions).length === 0)) {
                return;
            }

            var cfg     = (config && config[action]) ? config[action] : null;
            var def     = defaults[action] || {};
            var enabled = cfg ? (cfg.enabled ? true : false) : (def.enabled ? true : false);
            var point   = cfg ? (cfg.point || 0) : (def.point || 0);
            var revoke  = cfg ? (cfg.revoke ? true : false) : (def.revoke ? true : false);
            var suffix  = !cfg ? ' <small class="text-muted">(기본값)</small>' : '';

            html += '<div class="col-12 col-sm-6 col-md-3">';
            html += '<label class="form-label">' + (actionLabels[action] || action) + suffix + '</label>';
            html += '<div class="input-group mb-1">';
            html += '<input type="number" class="form-control" name="formData[' + action + '][point]" value="' + point + '" min="0" placeholder="0">';
            html += '<span class="input-group-text">P</span>';
            html += '</div>';
            html += '<div class="d-flex gap-3">';
            html += '<div class="form-check form-check-inline mb-0">';
            html += '<input type="hidden" name="formData[' + action + '][enabled]" value="0">';
            html += '<input type="checkbox" class="form-check-input" id="s_' + scopeType + '_' + action + '_en" name="formData[' + action + '][enabled]" value="1"' + (enabled ? ' checked' : '') + '>';
            html += '<label class="form-check-label small" for="s_' + scopeType + '_' + action + '_en">사용</label>';
            html += '</div>';
            html += '<div class="form-check form-check-inline mb-0">';
            html += '<input type="hidden" name="formData[' + action + '][revoke]" value="0">';
            html += '<input type="checkbox" class="form-check-input" id="s_' + scopeType + '_' + action + '_rv" name="formData[' + action + '][revoke]" value="1"' + (revoke ? ' checked' : '') + '>';
            html += '<label class="form-check-label small" for="s_' + scopeType + '_' + action + '_rv">삭제 시 회수</label>';
            html += '</div>';
            html += '</div>';

            // 반응 타입별 개별 포인트
            if (action === 'reaction_received' && scopeType === 'board' && enabledReactions && Object.keys(enabledReactions).length > 0) {
                html += '<div class="mt-2">';
                Object.keys(enabledReactions).forEach(function(rType) {
                    var rCfg   = enabledReactions[rType];
                    var rPoint = (reactionPoints[rType] !== undefined) ? reactionPoints[rType] : '';
                    html += '<div class="input-group input-group-sm mb-1">';
                    html += '<span class="input-group-text" style="color:' + (rCfg.color || '#6c757d') + '">' + (rCfg.icon || '') + ' ' + (rCfg.label || rType) + '</span>';
                    html += '<input type="number" class="form-control" name="formData[reaction_points][' + rType + ']" value="' + rPoint + '" min="0" placeholder="' + point + '">';
                    html += '<span class="input-group-text">P</span>';
                    html += '</div>';
                });
                html += '<div class="form-text">* 미입력 시 기본값 적용</div>';
                html += '</div>';
            }

            html += '</div>';
        });
        html += '</div>';

        // 소비 액션
        html += '<h6 class="mb-3 text-danger"><i class="bi bi-dash-circle"></i> 포인트 소비 <small class="text-muted fw-normal">(부족 시 접근 차단)</small></h6>';
        html += '<div class="row gy-3 mb-3">';
        consumeActions.forEach(function(action) {
            var cfg     = (config && config[action]) ? config[action] : null;
            var def     = defaults[action] || {};
            var enabled = cfg ? (cfg.enabled ? true : false) : (def.enabled ? true : false);
            var point   = cfg ? (cfg.point || 0) : (def.point || 0);
            var suffix  = !cfg ? ' <small class="text-muted">(기본값)</small>' : '';

            html += '<div class="col-12 col-sm-6 col-md-3">';
            html += '<label class="form-label">' + (actionLabels[action] || action) + suffix + '</label>';
            html += '<div class="input-group mb-1">';
            html += '<input type="number" class="form-control" name="formData[' + action + '][point]" value="' + point + '" min="0" placeholder="0">';
            html += '<span class="input-group-text">P</span>';
            html += '</div>';
            html += '<div class="form-check mb-0">';
            html += '<input type="hidden" name="formData[' + action + '][enabled]" value="0">';
            html += '<input type="checkbox" class="form-check-input" id="s_' + scopeType + '_' + action + '_en" name="formData[' + action + '][enabled]" value="1"' + (enabled ? ' checked' : '') + '>';
            html += '<label class="form-check-label small" for="s_' + scopeType + '_' + action + '_en">사용</label>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';
        html += '<div class="d-flex gap-2">';
        html += '<button type="button" class="btn btn-primary mublo-submit" data-target="/admin/board/point/' + scopeType + '/' + scopeId + '">';
        html += '<i class="bi bi-check-lg"></i> 저장</button>';
        html += '<button type="button" class="btn btn-outline-secondary" onclick="resetScopeConfig(\'' + scopeType + '\', ' + scopeId + ')">';
        html += '<i class="bi bi-arrow-counterclockwise"></i> 기본값으로 초기화</button>';
        html += '</div>';
        html += '</form>';
        return html;
    }

    function loadScopeConfig(scopeType, scopeId, targetEl) {
        if (!scopeId) {
            targetEl.style.display = 'none';
            return;
        }

        MubloRequest.requestQuery('/admin/board/point/' + scopeType + '/' + scopeId)
            .then(function(response) {
                var d = response.data || {};
                targetEl.innerHTML = renderScopeForm(scopeType, scopeId, d.config, d.defaults, d.enabledReactions || null);
                targetEl.style.display = 'block';
            });
    }

    window.resetScopeConfig = function(scopeType, scopeId) {
        if (!confirm('이 설정을 삭제하고 기본값으로 복원하시겠습니까?')) return;

        MubloRequest.requestJson('/admin/board/point/' + scopeType + '/' + scopeId, {}, { method: 'DELETE' })
            .then(function() {
                var selectEl = document.getElementById(scopeType + 'Select');
                var option   = selectEl.querySelector('option[value="' + scopeId + '"]');
                if (option) {
                    option.removeAttribute('data-has-config');
                    option.textContent = option.textContent.replace(' (설정됨)', '');
                }
                document.getElementById(scopeType + 'ConfigArea').style.display = 'none';
                selectEl.value = '';
            });
    };

    document.getElementById('groupSelect').addEventListener('change', function() {
        loadScopeConfig('group', this.value, document.getElementById('groupConfigArea'));
    });

    document.getElementById('boardSelect').addEventListener('change', function() {
        loadScopeConfig('board', this.value, document.getElementById('boardConfigArea'));
    });

    MubloRequest.registerCallback('boardPointSaved', function(response) {
        if (response.result === 'success') {
            alert(response.message || '설정이 저장되었습니다.');
        }
    });
})();
</script>
