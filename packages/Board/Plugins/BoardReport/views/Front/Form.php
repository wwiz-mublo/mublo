<?php
$csrfToken = $mublo['security']['csrfToken'];
/**
 * 게시글 신고 폼 (프론트)
 *
 * JS 의존 없는 순수 폼 — 어떤 프레임/게시판 스킨에서도 동작한다.
 *
 * @var string $pageTitle
 * @var int    $articleId
 * @var string $articleTitle
 * @var array  $reasons [code => label]
 * @var bool   $sent   접수 완료 여부
 * @var string $error  실패 메시지
 */
$reasons = $reasons ?? [];
?>
<div style="max-width: 560px; margin: 40px auto; padding: 0 16px;">
    <h1 style="font-size: 1.35rem; margin-bottom: 8px;">
        <i class="bi bi-flag"></i> <?= htmlspecialchars($pageTitle ?? '게시글 신고') ?>
    </h1>
    <p style="color: var(--muted-foreground, #6c757d); font-size: 0.9rem; margin-bottom: 20px;">
        신고 대상: <strong><?= htmlspecialchars($articleTitle) ?></strong>
    </p>

    <?php if (!empty($sent)): ?>
        <div style="padding: 16px; border: 1px solid #c6e6c6; background: #f2fbf2; border-radius: 8px; margin-bottom: 16px;">
            신고가 접수되었습니다. 운영자가 확인 후 처리합니다.
        </div>
        <a href="javascript:history.back()" style="display:inline-block; padding: 8px 16px; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: inherit;">돌아가기</a>
    <?php else: ?>
        <?php if (!empty($error)): ?>
            <div style="padding: 12px 16px; border: 1px solid #f0c6c6; background: #fdf3f3; border-radius: 8px; margin-bottom: 16px; color: #b02a37;">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="/board/report/submit">
            <input type="hidden" name="_token" value="<?= htmlspecialchars($csrfToken ?? '') ?>">
            <input type="hidden" name="article_id" value="<?= (int) $articleId ?>">

            <fieldset style="border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin-bottom: 16px;">
                <legend style="font-size: 0.95rem; padding: 0 6px;">신고 사유</legend>
                <?php $first = true; foreach ($reasons as $code => $label): ?>
                    <label style="display: flex; align-items: center; gap: 8px; padding: 6px 2px; cursor: pointer;">
                        <input type="radio" name="reason" value="<?= htmlspecialchars($code) ?>" <?= $first ? 'checked' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </label>
                <?php $first = false; endforeach; ?>
            </fieldset>

            <label style="display: block; margin-bottom: 6px; font-size: 0.9rem;">상세 내용 (선택)</label>
            <textarea name="detail" rows="4" maxlength="2000"
                      style="width: 100%; border: 1px solid #ddd; border-radius: 8px; padding: 10px; margin-bottom: 16px;"
                      placeholder="신고 사유를 구체적으로 적어주시면 처리에 도움이 됩니다."></textarea>

            <div style="display: flex; gap: 8px;">
                <button type="submit" style="padding: 10px 20px; border: 0; border-radius: 6px; background: var(--primary, #4f6ef7); color: #fff; cursor: pointer;">신고하기</button>
                <a href="javascript:history.back()" style="display:inline-block; padding: 10px 20px; border: 1px solid #ccc; border-radius: 6px; text-decoration: none; color: inherit;">취소</a>
            </div>
        </form>
    <?php endif; ?>
</div>
