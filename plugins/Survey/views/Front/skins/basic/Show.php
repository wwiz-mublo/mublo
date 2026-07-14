<?php
/**
 * @var array  $survey
 * @var array  $questions
 * @var int    $responseCount
 * @var bool   $canJoin
 * @var string $joinMessage
 */
$surveyId      = (int) $survey['survey_id'];
$responseCount = (int) ($responseCount ?? 0);
$this->assets->addCss('/serve/plugin/Survey/views/Front/skins/basic/style.css');
$this->assets->addJs('/serve/plugin/Survey/views/Front/skins/basic/script.js');
?>
<div class="sv-page">

    <!-- 헤더 -->
    <div class="sv-page-header">
        <h2 class="sv-page-title"><?= htmlspecialchars($survey['title']) ?></h2>
        <?php if (!empty($survey['description'])): ?>
        <p class="sv-page-desc"><?= nl2br(htmlspecialchars($survey['description'])) ?></p>
        <?php endif; ?>
        <div class="sv-page-meta">
            <span class="sv-meta-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
                <span id="sv-count"><?= number_format($responseCount) ?></span>명 참여
            </span>
            <?php if (!empty($survey['end_at'])): ?>
            <span class="sv-meta-badge">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24"
                     fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                ~<?= substr($survey['end_at'], 0, 10) ?>
            </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 참여 불가 안내 -->
    <?php if (!$canJoin): ?>
    <div class="sv-notice sv-notice--warn">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/>
            <line x1="12" y1="16" x2="12.01" y2="16"/>
        </svg>
        <?= htmlspecialchars($joinMessage) ?>
    </div>

    <?php else: ?>
    <!-- 설문 폼 -->
    <div id="sv-form-area">
        <!-- 제출 완료 패널 (숨김, form과 같은 영역 안에서 교체) -->
        <div id="sv-done" style="display:none">
            <div class="sv-done-card">
                <div class="sv-done-icon">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                </div>
                <h3 class="sv-done-title">참여해 주셔서 감사합니다!</h3>
                <p class="sv-done-msg">소중한 의견이 잘 전달되었습니다.</p>
                <div class="sv-done-count">
                    현재 <strong id="sv-done-count"><?= number_format($responseCount + 1) ?></strong>명이 참여했습니다
                </div>
            </div>
        </div>

        <form id="sv-form" data-survey-id="<?= $surveyId ?>" novalidate>
            <?php foreach ($questions as $no => $q):
                $qid     = (int) $q['question_id'];
                $req     = !empty($q['required']);
                $options = $q['options'] ?? [];
            ?>
            <div class="sv-card sv-question" data-qid="<?= $qid ?>">
                <div class="sv-q-num-row">
                    <span class="sv-q-num">Q<?= $no + 1 ?></span>
                    <?php if ($req): ?><span class="sv-required">필수</span><?php endif; ?>
                </div>
                <div class="sv-q-title">
                    <?= htmlspecialchars($q['title']) ?>
                </div>
                <?php if (!empty($q['description'])): ?>
                <div class="sv-q-hint"><?= htmlspecialchars($q['description']) ?></div>
                <?php endif; ?>

                <div class="sv-q-body">
                <?php if ($q['type'] === 'radio'): ?>
                    <?php foreach ($options as $idx => $label): ?>
                    <label class="sv-choice">
                        <input type="radio" name="q_<?= $qid ?>" value="<?= $idx ?>" <?= $req ? 'required' : '' ?>>
                        <span class="sv-choice-mark sv-choice-mark--radio"></span>
                        <?= htmlspecialchars($label) ?>
                    </label>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'checkbox'): ?>
                    <?php foreach ($options as $idx => $label): ?>
                    <label class="sv-choice">
                        <input type="checkbox" class="sv-cb" data-qid="<?= $qid ?>" value="<?= $idx ?>">
                        <span class="sv-choice-mark sv-choice-mark--check"></span>
                        <?= htmlspecialchars($label) ?>
                    </label>
                    <?php endforeach; ?>

                <?php elseif ($q['type'] === 'select'): ?>
                    <select class="sv-select" name="q_<?= $qid ?>" <?= $req ? 'required' : '' ?>>
                        <option value="">선택하세요</option>
                        <?php foreach ($options as $idx => $label): ?>
                        <option value="<?= $idx ?>"><?= htmlspecialchars($label) ?></option>
                        <?php endforeach; ?>
                    </select>

                <?php elseif ($q['type'] === 'text'): ?>
                    <input type="text" class="sv-input" name="q_<?= $qid ?>"
                           placeholder="답변을 입력하세요" <?= $req ? 'required' : '' ?>>

                <?php elseif ($q['type'] === 'textarea'): ?>
                    <textarea class="sv-textarea" name="q_<?= $qid ?>" rows="4"
                              placeholder="답변을 입력하세요" <?= $req ? 'required' : '' ?>></textarea>

                <?php elseif ($q['type'] === 'rating'): ?>
                    <div class="sv-rating" data-qid="<?= $qid ?>">
                        <?php for ($s = 1; $s <= 5; $s++): ?>
                        <button type="button" class="sv-star" data-value="<?= $s ?>">★</button>
                        <?php endfor; ?>
                        <span class="sv-rating-label">선택하세요</span>
                    </div>
                    <input type="hidden" name="q_<?= $qid ?>" class="sv-rating-val">
                <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="sv-submit-row">
                <button type="submit" class="sv-submit-btn" id="sv-submit">
                    <span class="sv-spinner" id="sv-spinner"></span>
                    설문 제출하기
                </button>
            </div>
        </form>
    </div>

    <?php endif; ?>

</div>
