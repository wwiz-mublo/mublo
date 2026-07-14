<?php
/**
 * Admin Member - Form
 *
 * 회원 등록/수정 공용 폼
 *
 * @var string $pageTitle 페이지 제목
 * @var string $mode 'create' 또는 'edit'
 * @var array|null $member 회원 정보 (수정 시)
 * @var array $fieldDefinitions 추가 필드 정의
 * @var array $fieldValues 추가 필드 값 [field_id => value]
 * @var array $levelOptions 등급 옵션 [level_value => level_name]
 * @var array $statusOptions 상태 옵션 [status => label]
 */

$isEdit = ($mode === 'edit');
$memberId = $member['member_id'] ?? 0;
$submitUrl = $isEdit ? "/admin/member/update/{$memberId}" : '/admin/member/store';
?>
<form id="member-form" autocomplete="off">
    <?php if ($isEdit): ?>
    <input type="hidden" name="formData[member_id]" value="<?= $memberId ?>">
    <?php endif; ?>

    <div class="page-container form-container">
        <div class="page-title">
            <div class="page-title-text">
                <h3><?= htmlspecialchars($pageTitle ?? '회원 관리') ?></h3>
                <p>
                    <?php if ($isEdit): ?>
                    회원 정보와 추가 필드, 등급·상태를 수정합니다.
                    <?php else: ?>
                    새로운 회원을 등록하고 등급·상태를 지정합니다.
                    <?php endif; ?>
                </p>
            </div>
            <div class="page-title-actions">
                <a href="<?= htmlspecialchars($listUrl ?? '/admin/member') ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-list"></i> 목록
                </a>
                <button type="button"
                        class="btn btn-sm btn-primary mublo-submit"
                        data-target="<?= $submitUrl ?>"
                        data-callback="onMemberFormSuccess"
                        data-loading="true"
                        id="btn-save">
                    <i class="bi bi-check-lg"></i> 저장
                </button>
            </div>
        </div>

        <!-- 폼 내용 -->
        <div class="page-block row">
            <!-- 기본 정보 -->
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-person text-pastel-blue"></i>
                        <span>기본 정보</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    아이디 <span class="text-danger">*</span>
                                </label>
                                <?php if ($isEdit): ?>
                                <input type="text"
                                       class="form-control"
                                       value="<?= htmlspecialchars($member['user_id'] ?? '') ?>"
                                       disabled>
                                <div class="form-text">아이디는 변경할 수 없습니다.</div>
                                <?php else: ?>
                                <div class="input-group">
                                    <input type="text"
                                           name="formData[user_id]"
                                           id="input-user_id"
                                           class="form-control"
                                           placeholder="영문, 숫자 4~20자"
                                           pattern="^[a-zA-Z0-9]{4,20}$"
                                           data-duplicate-checked="false"
                                           autocomplete="off"
                                           required>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-check-duplicate"
                                            data-field="user_id"
                                            data-input="input-user_id">
                                        중복확인
                                    </button>
                                </div>
                                <div class="form-text" id="feedback-user_id">영문, 숫자 4~20자로 입력</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    비밀번호 <?php if (!$isEdit): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>
                                <input type="password"
                                       name="formData[password]"
                                       class="form-control"
                                       placeholder="<?= $isEdit ? '변경 시에만 입력' : '최소 6자 이상' ?>"
                                       minlength="6"
                                       autocomplete="new-password"
                                       <?= $isEdit ? '' : 'required' ?>>
                                <div class="form-text">
                                    <?= $isEdit ? '비워두면 기존 비밀번호 유지' : '최소 6자 이상 입력' ?>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">
                                    닉네임 <span class="text-danger">*</span>
                                </label>
                                <div class="input-group">
                                    <input type="text"
                                           name="formData[nickname]"
                                           id="input-nickname"
                                           class="form-control"
                                           placeholder="2~20자"
                                           minlength="2"
                                           maxlength="20"
                                           value="<?= htmlspecialchars($member['nickname'] ?? '') ?>"
                                           data-duplicate-checked="<?= $isEdit ? 'true' : 'false' ?>"
                                           required>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-check-duplicate"
                                            data-field="nickname"
                                            data-input="input-nickname">
                                        중복확인
                                    </button>
                                </div>
                                <div class="form-text" id="feedback-nickname">2~20자로 입력</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- 추가 필드 -->
                <?php if (!empty($fieldDefinitions)): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-card-list text-pastel-green"></i>
                        <span>추가 정보</span>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php // 필드당 1행(전용 영역) — col-12 ?>
                            <?php foreach ($fieldDefinitions as $field):
                                $fieldId = $field['field_id'];
                                $fieldName = $field['field_name'];
                                $fieldLabel = $field['field_label'];
                                $fieldType = $field['field_type'];
                                $isRequired = (bool) ($field['is_required'] ?? false);
                                $isEncrypted = (bool) ($field['is_encrypted'] ?? false);
                                $isUnique = (bool) ($field['is_unique'] ?? false);
                                $value = $fieldValues[$fieldId] ?? '';
                                $options = !empty($field['field_options']) ? json_decode($field['field_options'], true) : [];
                            ?>
                            <div class="col-12 mb-3">
                                <label class="form-label">
                                    <?php if ($isEncrypted): ?><i class="bi bi-shield-lock text-warning me-1"></i><?php endif; ?>
                                    <?= htmlspecialchars($fieldLabel) ?>
                                    <?php if ($isRequired): ?><span class="text-danger">*</span><?php endif; ?>
                                </label>

                                <?php if ($fieldType === 'textarea'): ?>
                                <textarea name="fields[<?= $fieldId ?>]"
                                          class="form-control"
                                          rows="3"
                                          <?= $isRequired ? 'required' : '' ?>><?= htmlspecialchars($value) ?></textarea>

                                <?php elseif ($fieldType === 'select'): ?>
                                <select name="fields[<?= $fieldId ?>]" class="form-select" <?= $isRequired ? 'required' : '' ?>>
                                    <option value="">선택하세요</option>
                                    <?php if (is_array($options)):
                                        foreach ($options as $opt):
                                            $optValue = $opt['value'] ?? '';
                                            $optLabel = $opt['label'] ?? $optValue;
                                    ?>
                                    <option value="<?= htmlspecialchars($optValue) ?>"
                                            <?= $value === $optValue ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($optLabel) ?>
                                    </option>
                                    <?php endforeach; endif; ?>
                                </select>

                                <?php elseif ($fieldType === 'radio'): ?>
                                <div>
                                    <?php if (is_array($options)):
                                        foreach ($options as $idx => $opt):
                                            $optValue = $opt['value'] ?? '';
                                            $optLabel = $opt['label'] ?? $optValue;
                                    ?>
                                    <div class="form-check form-check-inline">
                                        <input type="radio"
                                               class="form-check-input"
                                               name="fields[<?= $fieldId ?>]"
                                               id="field_<?= $fieldId ?>_<?= $idx ?>"
                                               value="<?= htmlspecialchars($optValue) ?>"
                                               <?= $value === $optValue ? 'checked' : '' ?>
                                               <?= $isRequired ? 'required' : '' ?>>
                                        <label class="form-check-label" for="field_<?= $fieldId ?>_<?= $idx ?>">
                                            <?= htmlspecialchars($optLabel) ?>
                                        </label>
                                    </div>
                                    <?php endforeach; endif; ?>
                                </div>

                                <?php elseif ($fieldType === 'checkbox'): ?>
                                <div>
                                    <?php
                                    $checkedValues = is_array($value) ? $value : (is_string($value) && $value ? explode(',', $value) : []);
                                    if (is_array($options)):
                                        foreach ($options as $idx => $opt):
                                            $optValue = $opt['value'] ?? '';
                                            $optLabel = $opt['label'] ?? $optValue;
                                    ?>
                                    <div class="form-check form-check-inline">
                                        <input type="checkbox"
                                               class="form-check-input"
                                               name="fields[<?= $fieldId ?>][]"
                                               id="field_<?= $fieldId ?>_<?= $idx ?>"
                                               value="<?= htmlspecialchars($optValue) ?>"
                                               <?= in_array($optValue, $checkedValues) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="field_<?= $fieldId ?>_<?= $idx ?>">
                                            <?= htmlspecialchars($optLabel) ?>
                                        </label>
                                    </div>
                                    <?php endforeach; endif; ?>
                                </div>

                                <?php elseif ($fieldType === 'address'): ?>
                                <?php
                                    $addrValue = is_array($value) ? $value : (is_string($value) ? json_decode($value, true) : []);
                                    $addrValue = is_array($addrValue) ? $addrValue : [];
                                ?>
                                <div class="address-field">
                                    <div class="input-group mb-2">
                                        <input type="text"
                                               name="fields[<?= $fieldId ?>][zipcode]"
                                               class="form-control"
                                               placeholder="우편번호"
                                               value="<?= htmlspecialchars($addrValue['zipcode'] ?? '') ?>"
                                               id="zipcode_<?= $fieldId ?>"
                                               readonly>
                                        <button type="button" class="btn btn-outline-secondary btn-search-address"
                                                data-field-id="<?= $fieldId ?>">
                                            <i class="bi bi-search"></i> 검색
                                        </button>
                                    </div>
                                    <input type="text"
                                           name="fields[<?= $fieldId ?>][address1]"
                                           class="form-control mb-2"
                                           placeholder="기본 주소"
                                           value="<?= htmlspecialchars($addrValue['address1'] ?? '') ?>"
                                           id="address1_<?= $fieldId ?>"
                                           readonly>
                                    <input type="text"
                                           name="fields[<?= $fieldId ?>][address2]"
                                           class="form-control"
                                           placeholder="상세 주소 (직접 입력)"
                                           value="<?= htmlspecialchars($addrValue['address2'] ?? '') ?>"
                                           id="address2_<?= $fieldId ?>">
                                </div>

                                <?php elseif ($fieldType === 'date'): ?>
                                <input type="date"
                                       name="fields[<?= $fieldId ?>]"
                                       class="form-control"
                                       value="<?= htmlspecialchars($value) ?>"
                                       <?= $isRequired ? 'required' : '' ?>>

                                <?php elseif ($fieldType === 'number'): ?>
                                <input type="number"
                                       name="fields[<?= $fieldId ?>]"
                                       class="form-control"
                                       value="<?= htmlspecialchars($value) ?>"
                                       <?= $isRequired ? 'required' : '' ?>>

                                <?php elseif ($fieldType === 'email'): ?>
                                <?php if ($isUnique): ?>
                                <div class="input-group">
                                    <input type="email"
                                           name="fields[<?= $fieldId ?>]"
                                           id="input-field-<?= $fieldId ?>"
                                           class="form-control"
                                           placeholder="example@email.com"
                                           value="<?= htmlspecialchars($value) ?>"
                                           data-duplicate-checked="<?= $isEdit ? 'true' : 'false' ?>"
                                           <?= $isRequired ? 'required' : '' ?>>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-check-duplicate"
                                            data-field="<?= htmlspecialchars($fieldName) ?>"
                                            data-input="input-field-<?= $fieldId ?>">
                                        중복확인
                                    </button>
                                </div>
                                <div class="form-text" id="feedback-field-<?= $fieldId ?>"></div>
                                <?php else: ?>
                                <input type="email"
                                       name="fields[<?= $fieldId ?>]"
                                       class="form-control"
                                       placeholder="example@email.com"
                                       value="<?= htmlspecialchars($value) ?>"
                                       <?= $isRequired ? 'required' : '' ?>>
                                <?php endif; ?>

                                <?php elseif ($fieldType === 'tel'): ?>
                                <?php if ($isUnique): ?>
                                <div class="input-group">
                                    <input type="tel"
                                           name="fields[<?= $fieldId ?>]"
                                           id="input-field-<?= $fieldId ?>"
                                           class="form-control mask-hp"
                                           placeholder="010-1234-5678"
                                           value="<?= htmlspecialchars($value) ?>"
                                           data-duplicate-checked="<?= $isEdit ? 'true' : 'false' ?>"
                                           <?= $isRequired ? 'required' : '' ?>>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-check-duplicate"
                                            data-field="<?= htmlspecialchars($fieldName) ?>"
                                            data-input="input-field-<?= $fieldId ?>">
                                        중복확인
                                    </button>
                                </div>
                                <div class="form-text" id="feedback-field-<?= $fieldId ?>"></div>
                                <?php else: ?>
                                <input type="tel"
                                       name="fields[<?= $fieldId ?>]"
                                       class="form-control mask-hp"
                                       placeholder="010-1234-5678"
                                       value="<?= htmlspecialchars($value) ?>"
                                       <?= $isRequired ? 'required' : '' ?>>
                                <?php endif; ?>

                                <?php elseif (in_array($fieldType, ['file', 'avatar'], true)): ?>
                                <?php
                                    // 어드민 자체 구현(Bootstrap 마크업 + 하단 관리자 전용 JS). 프론트 위젯/CSS 비의존.
                                    $isAvatar = ($fieldType === 'avatar');
                                    $cfg = $field['field_config'] ?? '{}';
                                    if (is_string($cfg)) { $cfg = json_decode($cfg, true) ?: []; }
                                    $maxSize = (int) ($cfg['max_size'] ?? 5);
                                    $allowedExt = $cfg['allowed_ext'] ?? '';
                                    $fileMeta = is_array($value) ? $value : null;
                                    $hasExisting = $fileMeta && !empty($fileMeta['filename']);
                                    $existingUrl = $fileMeta['url'] ?? '';
                                    $accept = $isAvatar
                                        ? ' accept="image/*"'
                                        : ($allowedExt ? ' accept=".' . implode(',.', array_map('trim', explode(',', $allowedExt))) . '"' : '');
                                    $metaVal = $hasExisting ? htmlspecialchars(json_encode($fileMeta), ENT_QUOTES) : '';
                                    // 확장자는 보존하고 base만 truncate (끝에서 자르면 확장자가 잘리므로)
                                    $fnBase = $fnExt = '';
                                    if ($hasExisting) {
                                        $fn = $fileMeta['filename'];
                                        $dot = strrpos($fn, '.');
                                        if ($dot !== false && $dot > 0) { $fnBase = substr($fn, 0, $dot); $fnExt = substr($fn, $dot); }
                                        else { $fnBase = $fn; }
                                    }
                                ?>
                                <div class="d-flex gap-3 align-items-start">
                                    <div class="flex-grow-1 min-w-0">
                                        <input type="hidden" name="fields[<?= $fieldId ?>]" id="mfield_<?= $fieldId ?>_meta" value="<?= $metaVal ?>" data-orig="<?= $metaVal ?>">

                                        <?php if ($hasExisting): ?>
                                        <div id="mfield_<?= $fieldId ?>_current" class="d-flex align-items-center gap-2 form-control bg-body-tertiary border rounded mb-2">
                                            <i class="bi bi-file-earmark text-muted"></i>
                                            <span class="d-inline-flex flex-grow-1 min-w-0 js-mfield-fn"><span class="text-truncate min-w-0"><?= htmlspecialchars($fnBase) ?></span><span class="flex-shrink-0"><?= htmlspecialchars($fnExt) ?></span></span>
                                            <?php if (!empty($fileMeta['size'])): ?>
                                            <span class="text-muted small">(<?= round($fileMeta['size'] / 1024, 1) ?>KB)</span>
                                            <?php endif; ?>
                                            <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle d-none js-mfield-delmark">삭제 예정</span>
                                            <div class="ms-auto d-flex align-items-center gap-2">
                                                <?php if ($existingUrl): ?>
                                                <a href="<?= htmlspecialchars($existingUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener"
                                                   class="btn btn-sm btn-link link-primary p-0" title="다운로드"><i class="bi bi-download"></i></a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-sm btn-link link-danger p-0 js-mfield-delete"
                                                        data-field-id="<?= $fieldId ?>" title="삭제"><i class="bi bi-trash"></i></button>
                                            </div>
                                        </div>
                                        <?php endif; ?>

                                        <input type="file" id="mfield_<?= $fieldId ?>"
                                               class="form-control js-mfield-file"
                                               data-field-id="<?= $fieldId ?>" data-max-size="<?= $maxSize ?>"<?= $isAvatar ? ' data-avatar="1"' : '' ?><?= $accept ?>>
                                        <div class="form-text">
                                            <?= $allowedExt ? '허용 파일: ' . htmlspecialchars($allowedExt) . ' (최대 ' . $maxSize . 'MB)' : '최대 ' . $maxSize . 'MB' ?>
                                        </div>

                                        <div id="mfield_<?= $fieldId ?>_result" class="align-items-center gap-2 form-control bg-primary-subtle border border-primary-subtle rounded mt-2" style="display:none;">
                                            <i class="bi bi-check-circle-fill text-primary"></i>
                                            <span class="js-mfield-name fw-medium d-inline-flex flex-grow-1 min-w-0"><span class="text-truncate min-w-0 js-fn-base"></span><span class="flex-shrink-0 js-fn-ext"></span></span>
                                            <button type="button" class="btn btn-sm btn-link link-danger p-0 ms-auto js-mfield-cancel"
                                                    data-field-id="<?= $fieldId ?>" title="취소"><i class="bi bi-x-lg"></i></button>
                                        </div>
                                    </div>

                                    <?php if ($isAvatar): ?>
                                    <div id="mfield_<?= $fieldId ?>_preview" data-existing-url="<?= htmlspecialchars($existingUrl, ENT_QUOTES) ?>"
                                         class="flex-shrink-0" style="width:96px;height:96px;<?= $existingUrl ? '' : 'display:none;' ?>">
                                        <img id="mfield_<?= $fieldId ?>_preview_img" src="<?= htmlspecialchars($existingUrl, ENT_QUOTES) ?>" alt="아바타"
                                             class="w-100 h-100 rounded border" style="object-fit:cover;">
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php else: // text (기본) ?>
                                <?php if ($isUnique): ?>
                                <div class="input-group">
                                    <input type="text"
                                           name="fields[<?= $fieldId ?>]"
                                           id="input-field-<?= $fieldId ?>"
                                           class="form-control"
                                           value="<?= htmlspecialchars($value) ?>"
                                           data-duplicate-checked="<?= $isEdit ? 'true' : 'false' ?>"
                                           <?= $isRequired ? 'required' : '' ?>>
                                    <button type="button"
                                            class="btn btn-outline-secondary btn-check-duplicate"
                                            data-field="<?= htmlspecialchars($fieldName) ?>"
                                            data-input="input-field-<?= $fieldId ?>">
                                        중복확인
                                    </button>
                                </div>
                                <div class="form-text" id="feedback-field-<?= $fieldId ?>"></div>
                                <?php else: ?>
                                <input type="text"
                                       name="fields[<?= $fieldId ?>]"
                                       class="form-control"
                                       value="<?= htmlspecialchars($value) ?>"
                                       <?= $isRequired ? 'required' : '' ?>>
                                <?php endif; ?>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- 플러그인 확장 섹션 -->
                <?php if (!empty($pluginSections)): ?>
                    <?php foreach ($pluginSections as $sectionHtml): ?>
                        <?= $sectionHtml ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- 등급/상태 설정 -->
            <div class="col-lg-4">
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-gear text-pastel-purple"></i>
                        <span>회원 설정</span>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">회원 등급</label>
                            <?php $isSelf = $isSelf ?? false; $disabledLevels = $disabledLevels ?? []; ?>
                            <select name="formData[level_value]" class="form-select" <?= $isSelf ? 'disabled' : '' ?>>
                                <?php foreach ($levelOptions as $levelValue => $levelName): ?>
                                <option value="<?= htmlspecialchars($levelValue) ?>"
                                        <?= ($member['level_value'] ?? 1) == $levelValue ? 'selected' : '' ?>
                                        <?= in_array($levelValue, $disabledLevels) ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($levelName) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($isSelf): ?>
                            <div class="form-text">본인의 등급은 변경할 수 없습니다.</div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">계정 상태</label>
                            <select name="formData[status]" class="form-select">
                                <?php foreach ($statusOptions as $statusValue => $statusLabel): ?>
                                <option value="<?= htmlspecialchars($statusValue) ?>"
                                        <?= ($member['status'] ?? 'active') === $statusValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($statusLabel) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php if ($isEdit && ($adminIsSuper ?? false)): ?>
                        <div class="mb-0">
                            <label class="form-label">플랫폼 권한</label>
                            <div class="form-check form-switch">
                                <input type="hidden" name="formData[can_create_site]" value="0">
                                <input class="form-check-input" type="checkbox"
                                       name="formData[can_create_site]" value="1"
                                       id="canCreateSite"
                                       <?= ($member['can_create_site'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="canCreateSite">
                                    사이트 생성 가능
                                </label>
                            </div>
                            <div class="form-text">활성화 시 이 회원이 하위 도메인(사이트)을 생성할 수 있습니다.</div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isEdit): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-clock-history text-pastel-sky"></i>
                        <span>가입 정보</span>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-0">
                            <dt class="col-sm-5 text-muted fw-normal">보유 포인트</dt>
                            <dd class="col-sm-7"><?= number_format($member['point'] ?? 0) ?> P</dd>

                            <dt class="col-sm-5 text-muted fw-normal">가입일</dt>
                            <dd class="col-sm-7"><?= htmlspecialchars($member['created_at'] ?? '-') ?></dd>

                            <dt class="col-sm-5 text-muted fw-normal">최종 로그인</dt>
                            <dd class="col-sm-7 mb-0"><?= htmlspecialchars($member['last_login_at'] ?? '-') ?></dd>
                        </dl>
                    </div>
                </div>

                <?php if (!empty($recentPointLogs)): ?>
                <div class="card mb-4">
                    <div class="card-hero">
                        <i class="bi bi-coin text-pastel-orange"></i>
                        <span>최근 포인트 내역</span>
                        <a href="/admin/point?member_id=<?= $memberId ?>" class="btn btn-xs btn-outline-secondary ms-auto">전체보기</a>
                    </div>
                    <div class="card-body">
                        <table class="table mb-0">
                            <thead>
                                <tr>
                                    <th>발생 일시</th>
                                    <th>내용</th>
                                    <th class="text-end">포인트</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentPointLogs as $log): ?>
                            <tr>
                                <td class="text-muted ps-3 text-nowrap small">
                                    <?= str_replace(' ', '<br>', substr($log['created_at'], 2)) ?>
                                </td>
                                <td class="text-truncate" title="<?= htmlspecialchars($log['message']) ?>">
                                    <?= htmlspecialchars($log['message']) ?>
                                </td>
                                <td class="text-end pe-3 fw-bold text-nowrap <?= $log['amount'] >= 0 ? 'text-primary' : 'text-danger' ?>">
                                    <?= $log['amount'] >= 0 ? '+' : '' ?><?= number_format($log['amount']) ?> P
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<!-- 다음 주소 검색 API -->
<script src="//t1.daumcdn.net/mapjsapi/bundle/postcode/prod/postcode.v2.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var memberId = <?= json_encode($memberId) ?>;

    // ========================================
    // 중복 체크 버튼 클릭
    // ========================================
    document.querySelectorAll('.btn-check-duplicate').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldName = this.dataset.field;
            var inputId = this.dataset.input;
            var inputEl = document.getElementById(inputId);
            var feedbackEl = document.getElementById('feedback-' + inputId.replace('input-', ''));

            if (!inputEl) return;

            var value = inputEl.value.trim();
            if (!value) {
                showFeedback(feedbackEl, '값을 입력해주세요.', 'warning');
                inputEl.focus();
                return;
            }

            // AJAX 중복 체크
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

            MubloRequest.requestJson('/admin/member/check-duplicate', {
                field_name: fieldName,
                value: value,
                member_id: memberId || null
            }).then(function(response) {
                btn.disabled = false;
                btn.textContent = '중복확인';

                if (response.result === 'success') {
                    if (response.data.duplicate) {
                        showFeedback(feedbackEl, response.message, 'danger');
                        inputEl.classList.add('is-invalid');
                        inputEl.classList.remove('is-valid');
                        inputEl.dataset.duplicateChecked = 'false';
                    } else {
                        showFeedback(feedbackEl, response.message, 'success');
                        inputEl.classList.add('is-valid');
                        inputEl.classList.remove('is-invalid');
                        inputEl.dataset.duplicateChecked = 'true';
                    }
                } else {
                    showFeedback(feedbackEl, response.message || '오류가 발생했습니다.', 'danger');
                }
            }).catch(function(err) {
                btn.disabled = false;
                btn.textContent = '중복확인';
                showFeedback(feedbackEl, '서버 오류가 발생했습니다.', 'danger');
            });
        });
    });

    // 피드백 표시 함수
    function showFeedback(el, message, type) {
        if (!el) return;
        el.textContent = message;
        el.className = 'form-text';
        if (type === 'success') el.classList.add('text-success');
        else if (type === 'danger') el.classList.add('text-danger');
        else if (type === 'warning') el.classList.add('text-warning');
    }

    // 입력값 변경 시 중복체크 상태 초기화
    document.querySelectorAll('[data-duplicate-checked]').forEach(function(input) {
        input.addEventListener('input', function() {
            this.dataset.duplicateChecked = 'false';
            this.classList.remove('is-valid', 'is-invalid');
            var feedbackEl = document.getElementById('feedback-' + this.id.replace('input-', ''));
            if (feedbackEl) {
                feedbackEl.textContent = '';
                feedbackEl.className = 'form-text';
            }
        });
    });

    // ========================================
    // 폼 제출 전 중복 체크 검증 (MubloRequest 통합)
    // ========================================
    MubloRequest.configure({
        formValidator: function(form) {
            var uncheckedFields = [];
            form.querySelectorAll('[data-duplicate-checked="false"]').forEach(function(input) {
                if (input.value.trim()) {
                    var label = input.closest('.col-md-6')?.querySelector('.form-label')?.textContent?.trim() || input.name;
                    uncheckedFields.push(label.replace('*', '').trim());
                }
            });

            if (uncheckedFields.length > 0) {
                MubloRequest.showAlert('다음 필드의 중복 확인이 필요합니다:\n- ' + uncheckedFields.join('\n- '), 'warning');
                return false;
            }
            return true;
        }
    });

    // ========================================
    // 주소 검색 버튼 클릭
    // ========================================
    document.querySelectorAll('.btn-search-address').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var fieldId = this.dataset.fieldId;
            new daum.Postcode({
                oncomplete: function(data) {
                    document.getElementById('zipcode_' + fieldId).value = data.zonecode;
                    document.getElementById('address1_' + fieldId).value = data.roadAddress || data.jibunAddress;
                    document.getElementById('address2_' + fieldId).focus();
                }
            }).open();
        });
    });

    // ========================================
    // 폼 제출 성공 콜백
    // ========================================
    window.onMemberFormSuccess = function(response) {
        MubloRequest.showToast(response.message || '저장되었습니다.', 'success');
        const redirect = response.data?.redirect || <?= json_encode($listUrl ?? '/admin/member') ?>;
        location.href = redirect;
    };

    // ── 추가 정보: 파일/아바타 업로드 (관리자 자체 구현) ─────────────────
    // temp 업로드는 도메인 검증 엔드포인트 재사용, 메타는 fields[id] hidden으로 제출.
    (function() {
        const UPLOAD_URL = '/member/upload-field-file';
        const el = function(id) { return document.getElementById(id); };

        // 확장자 보존 분리 (base만 truncate)
        function splitFileName(name) {
            const i = name.lastIndexOf('.');
            return i > 0 ? { base: name.slice(0, i), ext: name.slice(i) } : { base: name, ext: '' };
        }

        function setPreview(fid, src) {
            const box = el('mfield_' + fid + '_preview');
            const img = el('mfield_' + fid + '_preview_img');
            if (!box || !img) return;
            // 이전에 만든 blob URL이 있으면 메모리 해제
            if (img.dataset.objurl) { URL.revokeObjectURL(img.dataset.objurl); img.dataset.objurl = ''; }
            if (src) {
                img.src = src;
                if (src.indexOf('blob:') === 0) img.dataset.objurl = src;
                box.style.display = '';
                box.style.opacity = '';
            } else {
                img.src = '';
                box.style.display = 'none';
            }
        }

        // 아바타 미리보기 흐리게(삭제 예정 표시)
        function setPreviewDim(fid, dim) {
            const box = el('mfield_' + fid + '_preview');
            if (box) box.style.opacity = dim ? '0.4' : '';
        }

        // 기존 파일 칩의 "삭제 예정" 시각 상태 토글
        function setDeleteMark(fid, marked) {
            const chip = el('mfield_' + fid + '_current');
            if (!chip) return;
            const mark = chip.querySelector('.js-mfield-delmark');
            const fn = chip.querySelector('.js-mfield-fn');
            const btn = chip.querySelector('.js-mfield-delete');
            if (mark) mark.classList.toggle('d-none', !marked);
            if (fn) {
                fn.classList.toggle('text-decoration-line-through', marked);
                fn.classList.toggle('text-muted', marked);
            }
            if (btn) {
                const ic = btn.querySelector('i');
                if (ic) ic.className = marked ? 'bi bi-arrow-counterclockwise' : 'bi bi-trash';
                btn.title = marked ? '되돌리기' : '삭제';
                btn.classList.toggle('link-danger', !marked);
                btn.classList.toggle('link-info', marked);
            }
            setPreviewDim(fid, marked);
        }

        function onPick(input) {
            const fid = input.dataset.fieldId;
            const max = parseInt(input.dataset.maxSize || '5', 10);
            const file = input.files[0];
            if (!file) return;
            if (file.size > max * 1024 * 1024) {
                MubloRequest.showToast(max + 'MB를 초과했습니다.', 'error');
                input.value = '';
                return;
            }
            if (input.dataset.avatar === '1') {
                const reader = new FileReader();
                reader.onload = function(e) { setPreview(fid, e.target.result); };
                reader.readAsDataURL(file);
            }
            const fd = new FormData();
            fd.append('file', file);
            fd.append('field_id', fid);
            MubloRequest.sendRequest({ method: 'POST', url: UPLOAD_URL, payloadType: 'form', data: fd })
                .then(function(res) {
                    const meta = el('mfield_' + fid + '_meta');
                    if (meta) meta.value = JSON.stringify(res.data);
                    const result = el('mfield_' + fid + '_result');
                    if (result) {
                        const parts = splitFileName(res.data.filename || '');
                        const b = result.querySelector('.js-fn-base'); if (b) b.textContent = parts.base;
                        const ex = result.querySelector('.js-fn-ext'); if (ex) ex.textContent = parts.ext;
                        result.style.display = 'flex';
                    }
                    const cur = el('mfield_' + fid + '_current');
                    if (cur) cur.style.display = 'none';
                })
                .catch(function() { MubloRequest.showToast('파일 업로드에 실패했습니다.', 'error'); });
        }

        // 신규 선택 취소 → 원본 상태로 복귀
        function cancelNew(fid) {
            const meta = el('mfield_' + fid + '_meta'); if (meta) meta.value = meta.dataset.orig || '';
            const input = el('mfield_' + fid); if (input) input.value = '';
            const result = el('mfield_' + fid + '_result'); if (result) result.style.display = 'none';
            const cur = el('mfield_' + fid + '_current'); if (cur) cur.style.display = 'flex';
            setDeleteMark(fid, false);
            const box = el('mfield_' + fid + '_preview');
            if (box) setPreview(fid, box.dataset.existingUrl || '');
        }

        // 삭제/되돌리기 토글 — confirm 없이 '삭제 예정'만 표시. 실제 삭제는 저장 시 반영.
        function toggleDelete(fid) {
            const meta = el('mfield_' + fid + '_meta');
            if (!meta) return;
            if (meta.value === '__delete__') {
                meta.value = meta.dataset.orig || '';
                setDeleteMark(fid, false);
            } else {
                meta.value = '__delete__';
                setDeleteMark(fid, true);
            }
        }

        document.querySelectorAll('.js-mfield-file').forEach(function(input) {
            input.addEventListener('change', function() { onPick(input); });
        });
        document.addEventListener('click', function(e) {
            const del = e.target.closest('.js-mfield-delete');
            if (del) { toggleDelete(del.dataset.fieldId); return; }
            const cancel = e.target.closest('.js-mfield-cancel');
            if (cancel) { cancelNew(cancel.dataset.fieldId); }
        });
    })();
});

<?php if (!empty($pluginScripts)): ?>
<?php foreach ($pluginScripts as $script): ?>
<?= $script ?>

<?php endforeach; ?>
<?php endif; ?>
</script>
