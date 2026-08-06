<?php
/**
 * Board View (basic skin)
 *
 * 게시글 상세 기본 스킨
 *
 * @var array $article 게시글 (ArticlePresenter 변환 완료)
 * @var array $board 게시판 설정
 * @var array|null $prev 이전 글 (Presenter 변환 완료)
 * @var array|null $next 다음 글 (Presenter 변환 완료)
 * @var bool $canModify 수정 권한
 * @var bool $canDelete 삭제 권한
 * @var bool $canComment 댓글 권한
 * @var bool $canReact 반응 권한
 * @var bool $canDownload 다운로드 권한
 * @var bool $isGuestArticle 비회원 작성 글 여부
 * @var array $comments 댓글 목록
 *
 * [Presenter 제공 필드]
 * - author_name / author_name_masked: 글쓴이 (원본/마스킹, 이스케이프 완료)
 * - author_id / author_id_masked: 아이디 (원본/마스킹, 비회원 null)
 * - title_safe: htmlspecialchars 적용된 제목
 * - url / edit_url: URL
 * - date_full / date_relative: 날짜 포맷
 * - view_count_formatted / comment_count_formatted: 포맷된 통계
 * - badges: 배지 배열
 * - is_new / is_updated: 상태 플래그
 */

$boardSlug = htmlspecialchars($board['board_slug'] ?? '');
$boardName = htmlspecialchars($board['board_name'] ?? '');
// 목록으로 돌아갈 때 페이지/검색/카테고리 컨텍스트 복원
$listQuery = $this->getQueryString();
$listQuerySuffix = $listQuery !== '' ? '?' . htmlspecialchars($listQuery, ENT_QUOTES) : '';
$useComment = !empty($board['use_comment']);
$useReaction = !empty($board['use_reaction']);
$reactionInfo = $reactionInfo ?? null;
$enabledReactions = $enabledReactions ?? [];
// 방어적 가드: 이모지·라벨이 모두 있는 반응만 노출 (손상 데이터 렌더 방지)
$enabledReactions = array_filter(
    $enabledReactions,
    fn($cfg) => trim((string) ($cfg['icon'] ?? '')) !== '' && trim((string) ($cfg['label'] ?? '')) !== ''
);

$articleId = (int) ($article['article_id'] ?? 0);
$content = $article['content'] ?? '';
$isNotice = in_array('notice', $article['badges']);
$isSecret = in_array('secret', $article['badges']);

$viewer = $mublo['viewer'];
$viewerMember = $viewer['member'];
$isLoggedIn = !empty($viewer['authenticated']);
$currentNickname = htmlspecialchars($viewerMember['displayName'] ?? '');

$this->assets->addCss('/serve/package/Board/views/Front/Board/basic/_assets/css/board.css');
$this->assets->addCss('/serve/package/Board/assets/css/code-block.css');
$this->assets->addJs('/serve/package/Board/assets/js/code-block.js');
?>

<div class="board-view">
    <!-- 게시판 헤더 -->
    <div class="board-view__board-header">
        <h2 class="board-view__board-name">
            <a href="/board/<?= $boardSlug ?>"><?= $boardName ?></a>
        </h2>
    </div>

    <!-- 글 헤더 -->
    <div class="board-view__header">
        <h3 class="board-view__title">
            <?php if ($isNotice): ?>
                <span class="board-view__badge board-view__badge--notice">공지</span>
            <?php endif; ?>
            <?php if ($isSecret): ?>
                <span class="board-view__icon board-view__icon--secret">🔒</span>
            <?php endif; ?>
            <?= $article['title_safe'] ?>
        </h3>
        <div class="board-view__meta">
            <?php
            $articleAuthorMenu = $this->memberActionMenu(
                $article['author_actions'] ?? [],
                (string) ($article['author_public_id'] ?? ''),
                [
                    'placement' => 'board.article_author',
                    'compact' => true,
                    'ariaLabel' => '글 작성자 메뉴',
                    'triggerLabel' => htmlspecialchars_decode((string) $article['author_name'], ENT_QUOTES | ENT_HTML5),
                ]
            );
            ?>
            <span class="board-view__author"><?= $articleAuthorMenu !== '' ? $articleAuthorMenu : $article['author_name'] ?></span>
            <span class="board-view__date"><?= $article['date_full'] ?></span>
            <span class="board-view__views">조회 <?= $article['view_count_formatted'] ?></span>
            <?php if ((int) ($article['comment_count'] ?? 0) > 0): ?>
                <span class="board-view__comments">댓글 <?= $article['comment_count_formatted'] ?></span>
            <?php endif; ?>
            <?php if ($article['is_updated']): ?>
                <span class="board-view__updated">수정됨</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 본문 -->
    <div class="board-view__content">
        <?= $content ?>
    </div>

    <!-- 첨부파일 -->
    <?php if (!empty($attachments)): ?>
    <div class="board-view__attachments">
        <h4 class="board-view__attachments-title">첨부 파일 <span class="board-view__attachments-count"><?= count($attachments) ?></span></h4>
        <?php
        // 파일 종류(presenter file_type) → 아이콘 매핑은 이 스킨이 소유 (다른 스킨은 다른 아이콘셋 가능)
        $attachmentIcons = [
            'image'        => 'bi-file-earmark-image',
            'pdf'          => 'bi-file-earmark-pdf',
            'document'     => 'bi-file-earmark-word',
            'spreadsheet'  => 'bi-file-earmark-excel',
            'presentation' => 'bi-file-earmark-ppt',
            'archive'      => 'bi-file-earmark-zip',
            'text'         => 'bi-file-earmark-text',
            'video'        => 'bi-file-earmark-play',
            'audio'        => 'bi-file-earmark-music',
            'file'         => 'bi-file-earmark',
        ];

        // 다운로드 레벨이 모자라도 파일 이름과 용량은 그대로 보여주고, 클릭도 받는다.
        // 회색 글씨만 두면 방문자는 링크가 죽은 건지 자기 권한이 모자란 건지 알 수 없다.
        // 클릭하면 이유를 모달로 알려주고(비회원은 로그인으로 갈지 묻는다), 로그인이
        // 끝나면 이 글로 돌아온다.
        $loginToDownloadUrl = '/login?redirect=' . rawurlencode(
            (string) ($article['url'] ?? '/board/' . ($board['board_slug'] ?? ''))
        );
        ?>
        <ul class="board-view__attachment-list">
            <?php foreach ($attachments as $att): ?>
            <li class="board-view__attachment-item">
                <?php if ($canDownload && !empty($att['download_url'])): ?>
                <a href="<?= htmlspecialchars($att['download_url'], ENT_QUOTES) ?>" class="board-view__attachment-link">
                    <span class="board-view__attachment-icon"><i class="bi <?= $attachmentIcons[$att['file_type'] ?? 'file'] ?? 'bi-file-earmark' ?>"></i></span>
                    <span class="board-view__attachment-name"><?= htmlspecialchars($att['original_name']) ?></span>
                    <span class="board-view__attachment-size">(<?= number_format($att['file_size'] / 1024, 1) ?>KB)</span>
                </a>
                <?php elseif (!$canDownload): ?>
                <button type="button" class="board-view__attachment-link board-view__attachment-link--locked" data-attachment-locked>
                    <span class="board-view__attachment-icon"><i class="bi <?= $attachmentIcons[$att['file_type'] ?? 'file'] ?? 'bi-file-earmark' ?>"></i></span>
                    <span class="board-view__attachment-name"><?= htmlspecialchars($att['original_name']) ?></span>
                    <span class="board-view__attachment-size">(<?= number_format($att['file_size'] / 1024, 1) ?>KB)</span>
                    <span class="board-view__attachment-lock" aria-hidden="true"><i class="bi bi-lock"></i></span>
                </button>
                <?php else: ?>
                <?php /* 권한은 있는데 주소를 만들 수 없다 = 첨부 데이터 이상. 깨진 링크로 404 를
                         내보내는 대신 받을 수 없음을 그대로 보여준다. */ ?>
                <span class="board-view__attachment-link board-view__attachment-link--broken">
                    <span class="board-view__attachment-icon"><i class="bi <?= $attachmentIcons[$att['file_type'] ?? 'file'] ?? 'bi-file-earmark' ?>"></i></span>
                    <span class="board-view__attachment-name"><?= htmlspecialchars($att['original_name']) ?></span>
                    <span class="board-view__attachment-note">(받을 수 없는 첨부입니다)</span>
                </span>
                <?php endif; ?>
                <?php if ($att['download_count'] > 0): ?>
                <span class="board-view__attachment-downloads">다운로드 <?= $att['download_count'] ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (!$canDownload): ?>
        <script>
        (function () {
            const loginUrl = <?= json_encode($loginToDownloadUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;

            document.querySelectorAll('[data-attachment-locked]').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    if (isLoggedIn) {
                        MubloRequest.showAlert('이 첨부파일을 받을 권한이 없습니다.', 'error');
                        return;
                    }
                    MubloRequest.showConfirm('로그인 후 다운로드 가능합니다.', function () {
                        location.href = loginUrl;
                    }, { type: 'warning', title: '로그인 필요', confirmText: '로그인', cancelText: '닫기' });
                });
            });
        })();
        </script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 링크 -->
    <?php if (!empty($links)): ?>
    <div class="board-view__links">
        <h4 class="board-view__links-title">관련 링크 <span class="board-view__links-count"><?= count($links) ?></span></h4>
        <ul class="board-view__link-list">
            <?php foreach ($links as $lnk): ?>
            <li class="board-view__link-item">
                <a href="<?= htmlspecialchars($lnk['link_url']) ?>" class="board-view__link-anchor" target="_blank" rel="noopener noreferrer">
                    <span class="board-view__link-icon"><i class="bi bi-link-45deg"></i></span>
                    <span class="board-view__link-text"><?= htmlspecialchars($lnk['link_title'] ?: $lnk['link_url']) ?></span>
                </a>
                <?php if (!empty($lnk['link_title'])): ?>
                <span class="board-view__link-url"><?= htmlspecialchars($lnk['link_url']) ?></span>
                <?php endif; ?>
                <?php if ($lnk['click_count'] > 0): ?>
                <span class="board-view__link-clicks">클릭 <?= $lnk['click_count'] ?></span>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- 반응 영역 -->
    <?php if ($useReaction && !empty($enabledReactions)):
        $reactionCounts = $reactionInfo['counts'] ?? [];
        // 합계는 활성 반응 카운트의 합 (비활성/삭제된 반응 제외 → 버튼 합과 일치)
        $reactionTotal = 0;
        foreach ($enabledReactions as $rType => $rConfig) {
            $reactionTotal += (int) ($reactionCounts[$rType] ?? 0);
        }
    ?>
    <div class="board-reaction" id="board-reaction" data-article-id="<?= $articleId ?>">
        <div class="board-reaction__buttons">
            <?php foreach ($enabledReactions as $type => $config):
                $count = $reactionCounts[$type] ?? 0;
                $isActive = ($reactionInfo['my_reaction'] ?? null) === $type;
                $label = htmlspecialchars($config['label'] ?? $type);
                $icon = $config['icon'] ?? '';
                $color = htmlspecialchars($config['color'] ?? '#3B82F6');
            ?>
                <button type="button"
                        class="board-reaction__btn<?= $isActive ? ' board-reaction__btn--active' : '' ?>"
                        data-type="<?= htmlspecialchars($type) ?>"
                        data-color="<?= $color ?>"
                        style="<?= $isActive ? '--reaction-color: ' . $color : '' ?>"
                        <?= !$canReact ? 'disabled' : '' ?>>
                    <span class="board-reaction__icon"><?= $icon ?></span>
                    <span class="board-reaction__meta">
                        <span class="board-reaction__label"><?= $label ?></span>
                        <span class="board-reaction__count" id="reaction-count-<?= htmlspecialchars($type) ?>"><?= (int) $count ?></span>
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
        <p class="board-reaction__summary"<?= $reactionTotal > 0 ? '' : ' hidden' ?> id="board-reaction-summary">
            총 <strong id="board-reaction-total"><?= (int) $reactionTotal ?></strong>명이 반응했습니다.
        </p>
        <?php if (!$isLoggedIn): ?>
            <p class="board-reaction__login-hint">반응을 남기려면 <a href="/login">로그인</a>하세요.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- 버튼 영역 -->
    <div class="board-view__actions">
        <div class="board-view__actions-left">
            <a href="/board/<?= $boardSlug ?><?= $listQuerySuffix ?>" class="board-view__btn board-view__btn--list">목록</a>
            <?php /* 플러그인 액션 (ArticleActionsCollectEvent) — 신고 등 */ ?>
            <?php foreach (($extraActions ?? []) as $act): ?>
                <a href="<?= htmlspecialchars($act['url']) ?>"
                   class="board-view__btn <?= htmlspecialchars($act['class'] ?? '') ?>">
                    <?php if (!empty($act['icon'])): ?><i class="bi <?= htmlspecialchars($act['icon']) ?>"></i> <?php endif; ?><?= htmlspecialchars($act['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="board-view__actions-right">
            <?php if ($canModify): ?>
                <a href="<?= $article['edit_url'] ?>" class="board-view__btn board-view__btn--edit">수정</a>
            <?php elseif (!empty($isGuestArticle)): ?>
                <a href="<?= $article['url'] ?>?manage=1" class="board-view__btn board-view__btn--edit">글 관리</a>
            <?php endif; ?>
            <?php if ($canDelete): ?>
                <button type="button" class="board-view__btn board-view__btn--delete" data-article-id="<?= $articleId ?>">삭제</button>
            <?php endif; ?>
            <?php if ($canWrite): ?>
                <a href="/board/<?= $boardSlug ?>/write" class="board-view__btn board-view__btn--write">글쓰기</a>
            <?php else: ?>
                <span class="board-view__btn board-view__btn--write board-view__btn--disabled"
                      aria-disabled="true" title="글쓰기 권한이 없습니다">글쓰기</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- 이전/다음 글 -->
    <?php if ($prev || $next): ?>
        <div class="board-view__adjacent">
            <?php if ($next): ?>
                <div class="board-view__adjacent-item board-view__adjacent-item--next">
                    <span class="board-view__adjacent-label">다음글</span>
                    <a href="<?= $next['url'] ?>" class="board-view__adjacent-link">
                        <?= $next['title_safe'] ?>
                    </a>
                </div>
            <?php endif; ?>
            <?php if ($prev): ?>
                <div class="board-view__adjacent-item board-view__adjacent-item--prev">
                    <span class="board-view__adjacent-label">이전글</span>
                    <a href="<?= $prev['url'] ?>" class="board-view__adjacent-link">
                        <?= $prev['title_safe'] ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- ========================================
         댓글 영역
         ======================================== -->
    <?php if ($useComment): ?>
    <div class="board-comment" id="board-comment" data-board-slug="<?= $boardSlug ?>" data-article-id="<?= $articleId ?>">
        <h4 class="board-comment__title">댓글 <span class="board-comment__count" id="comment-count"><?= $article['comment_count_formatted'] ?></span></h4>

        <!-- 댓글 목록 -->
        <div class="board-comment__list" id="comment-list">
            <?php if (empty($comments)): ?>
                <p class="board-comment__empty">등록된 댓글이 없습니다.</p>
            <?php else: ?>
                <?php foreach ($comments as $c):
                    $cId = (int) $c['comment_id'];
                    $cDepth = (int) ($c['depth'] ?? 0);
                    $cContent = nl2br(htmlspecialchars($c['content'] ?? ''));
                    $cDate = $c['created_at'] ? date('Y-m-d H:i', strtotime($c['created_at'])) : '';
                    $cAuthor = htmlspecialchars($c['author_name'] ?? '익명');
                    $cIsSecret = !empty($c['is_secret']);
                    $isOwn = !empty($c['is_own']);
                    $cAuthorMenu = $this->memberActionMenu(
                        $c['author_actions'] ?? [],
                        (string) ($c['author_public_id'] ?? ''),
                        [
                            'placement' => 'board.comment_author',
                            'compact' => true,
                            'ariaLabel' => '댓글 작성자 메뉴',
                            'triggerLabel' => (string) ($c['author_name'] ?? '익명'),
                        ]
                    );
                ?>
                    <div id="comment-<?= $cId ?>" class="board-comment__item board-comment__item--depth-<?= $cDepth ?>" data-comment-id="<?= $cId ?>" style="margin-left: <?= $cDepth * 30 ?>px;">
                        <div class="board-comment__item-header">
                            <span class="board-comment__item-author"><?= $cAuthorMenu !== '' ? $cAuthorMenu : $cAuthor ?></span>
                            <span class="board-comment__item-date"><?= $cDate ?></span>
                        </div>
                        <div class="board-comment__item-content">
                            <?php if ($cIsSecret): ?>
                                <span class="board-comment__icon--secret">🔒</span>
                            <?php endif; ?>
                            <span class="board-comment__item-text"><?= $cContent ?></span>
                        </div>
                        <div class="board-comment__item-actions">
                            <?php if ($canComment): ?>
                                <button type="button" class="board-comment__btn board-comment__btn--reply" data-parent-id="<?= $cId ?>">답글</button>
                            <?php endif; ?>
                            <?php if ($isOwn): ?>
                                <button type="button" class="board-comment__btn board-comment__btn--edit" data-comment-id="<?= $cId ?>">수정</button>
                                <button type="button" class="board-comment__btn board-comment__btn--delete" data-comment-id="<?= $cId ?>">삭제</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 댓글 작성 폼 -->
        <?php if ($canComment): ?>
        <div class="board-comment__form-wrap" id="comment-form-wrap">
            <div class="board-comment__form" id="comment-form">
                <?php if (!$isLoggedIn): ?>
                    <div class="board-comment__form-guest">
                        <input type="text" id="comment-guest-name" class="board-comment__input" placeholder="이름" maxlength="50">
                        <input type="password" id="comment-guest-password" class="board-comment__input" placeholder="비밀번호" maxlength="50">
                    </div>
                <?php endif; ?>
                <div class="board-comment__form-content">
                    <textarea id="comment-content" class="board-comment__textarea" rows="3" maxlength="16000" placeholder="댓글을 입력하세요."></textarea>
                </div>
                <div class="board-comment__form-actions">
                    <label class="board-comment__secret-label">
                        <input type="checkbox" id="comment-is-secret"> 비밀댓글
                    </label>
                    <button type="button" id="comment-submit-btn" class="board-comment__btn board-comment__btn--submit">등록</button>
                </div>
                <input type="hidden" id="comment-parent-id" value="">
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    (function() {
        const boardSlug = <?= json_encode((string) $boardSlug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const articleId = <?= $articleId ?>;
        const isLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;

        // 게시글 삭제
        const articleDeleteBtn = document.querySelector('.board-view__btn--delete[data-article-id]');
        if (articleDeleteBtn) {
            articleDeleteBtn.addEventListener('click', function() {
                MubloRequest.showConfirm('게시글을 삭제하시겠습니까?', function() {
                    MubloRequest.sendRequest({
                        url: '/board/' + boardSlug + '/delete/' + articleId,
                        method: 'POST',
                        data: {},
                        payloadType: MubloRequest.PayloadType.JSON,
                        loading: true
                    }).then(function(res) {
                        if (res.result === 'success') {
                            location.href = (res.data && res.data.redirect) || ('/board/' + boardSlug);
                        } else {
                            MubloRequest.showAlert(res.message || '게시글 삭제에 실패했습니다.', 'error');
                        }
                    });
                }, { type: 'warning' });
            });
        }

        // 댓글 등록
        const submitBtn = document.getElementById('comment-submit-btn');
        if (submitBtn) {
            submitBtn.addEventListener('click', function() {
                const content = document.getElementById('comment-content').value.trim();
                if (!content) {
                    MubloRequest.showAlert('댓글 내용을 입력해주세요.', 'warning');
                    return;
                }

                const payload = {
                    article_id: articleId,
                    content: content,
                    parent_id: document.getElementById('comment-parent-id').value || null,
                    is_secret: document.getElementById('comment-is-secret').checked
                };

                if (!isLoggedIn) {
                    payload.author_name = document.getElementById('comment-guest-name').value.trim();
                    payload.author_password = document.getElementById('comment-guest-password').value;
                    if (!payload.author_name) { MubloRequest.showAlert('이름을 입력해주세요.', 'warning'); return; }
                    if (!payload.author_password) { MubloRequest.showAlert('비밀번호를 입력해주세요.', 'warning'); return; }
                }

                MubloRequest.sendRequest({
                    url: '/board/' + boardSlug + '/comment',
                    method: 'POST',
                    data: payload,
                    payloadType: MubloRequest.PayloadType.JSON,
                    loading: true
                }).then(function(res) {
                    if (res.result === 'success') {
                        location.reload();
                    } else {
                        MubloRequest.showAlert(res.message || '댓글 등록에 실패했습니다.', 'error');
                    }
                });
            });
        }

        // 답글 버튼
        document.querySelectorAll('.board-comment__btn--reply').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const parentId = this.dataset.parentId;
                const parentItem = this.closest('.board-comment__item');
                const formWrap = document.getElementById('comment-form-wrap');

                // 폼 이동
                parentItem.after(formWrap);
                document.getElementById('comment-parent-id').value = parentId;
                document.getElementById('comment-content').focus();
                document.getElementById('comment-content').placeholder = '답글을 입력하세요.';
            });
        });

        // 댓글 삭제
        document.querySelectorAll('.board-comment__btn--delete').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const commentId = this.dataset.commentId;
                MubloRequest.showConfirm('댓글을 삭제하시겠습니까?', function() {
                    MubloRequest.sendRequest({
                        url: '/board/' + boardSlug + '/comment/' + commentId + '/delete',
                        method: 'POST',
                        data: {},
                        payloadType: MubloRequest.PayloadType.JSON,
                        loading: true
                    }).then(function(res) {
                        if (res.result === 'success') {
                            location.reload();
                        } else {
                            MubloRequest.showAlert(res.message || '댓글 삭제에 실패했습니다.', 'error');
                        }
                    });
                }, { type: 'warning' });
            });
        });

        // 댓글 수정
        document.querySelectorAll('.board-comment__btn--edit').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const commentId = this.dataset.commentId;
                const item = this.closest('.board-comment__item');
                const contentEl = item.querySelector('.board-comment__item-content');
                const textEl = contentEl.querySelector('.board-comment__item-text');
                const originalText = textEl ? textEl.innerText.trim() : '';
                const isSecret = contentEl.querySelector('.board-comment__icon--secret') !== null;

                // 수정 폼으로 교체
                contentEl.innerHTML =
                    '<textarea class="board-comment__textarea board-comment__textarea--edit" rows="3" maxlength="16000">' +
                    MubloRequest.escapeHtml(originalText) +
                    '</textarea>' +
                    '<div class="board-comment__edit-actions">' +
                    '<button type="button" class="board-comment__btn board-comment__btn--save">저장</button>' +
                    '<button type="button" class="board-comment__btn board-comment__btn--cancel">취소</button>' +
                    '</div>';

                // 저장
                contentEl.querySelector('.board-comment__btn--save').addEventListener('click', function() {
                    const newContent = contentEl.querySelector('textarea').value.trim();
                    if (!newContent) { MubloRequest.showAlert('댓글 내용을 입력해주세요.', 'warning'); return; }

                    MubloRequest.sendRequest({
                        url: '/board/' + boardSlug + '/comment/' + commentId + '/update',
                        method: 'POST',
                        data: { content: newContent },
                        payloadType: MubloRequest.PayloadType.JSON,
                        loading: true
                    }).then(function(res) {
                        if (res.result === 'success') {
                            location.reload();
                        } else {
                            MubloRequest.showAlert(res.message || '댓글 수정에 실패했습니다.', 'error');
                        }
                    });
                });

                // 취소
                contentEl.querySelector('.board-comment__btn--cancel').addEventListener('click', function() {
                    contentEl.replaceChildren();
                    if (isSecret) {
                        const icon = document.createElement('span');
                        icon.className = 'board-comment__icon--secret';
                        icon.textContent = '🔒';
                        contentEl.append(icon, document.createTextNode(' '));
                    }

                    const restoredText = document.createElement('span');
                    restoredText.className = 'board-comment__item-text';
                    originalText.split('\n').forEach(function(line, index) {
                        if (index > 0) restoredText.append(document.createElement('br'));
                        restoredText.append(document.createTextNode(line));
                    });
                    contentEl.append(restoredText);
                });
            });
        });
    })();
    </script>
    <?php endif; ?>

    <?php if ($useReaction && !empty($enabledReactions) && $canReact): ?>
    <script>
    (function() {
        const boardSlug = <?= json_encode((string) $boardSlug, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const articleId = <?= $articleId ?>;

        function refreshReactionSummary() {
            let total = 0;
            document.querySelectorAll('.board-reaction__count').forEach(function(countEl) {
                total += parseInt(countEl.textContent, 10) || 0;
            });
            const summary = document.getElementById('board-reaction-summary');
            const totalEl = document.getElementById('board-reaction-total');
            if (totalEl) totalEl.textContent = total;
            if (summary) summary.hidden = total === 0;
        }

        document.querySelectorAll('.board-reaction__btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (this.disabled) return;

                const reactionType = this.dataset.type;

                MubloRequest.sendRequest({
                    url: '/board/' + boardSlug + '/reaction',
                    method: 'POST',
                    data: {
                        article_id: articleId,
                        reaction_type: reactionType
                    },
                    payloadType: MubloRequest.PayloadType.JSON,
                    loading: false
                }).then(function(res) {
                    if (res.result === 'success') {
                        const data = res.data;

                        // 모든 버튼 비활성 스타일 제거
                        document.querySelectorAll('.board-reaction__btn').forEach(function(b) {
                            b.classList.remove('board-reaction__btn--active');
                            b.style.removeProperty('--reaction-color');
                        });

                        // 카운트 업데이트
                        document.querySelectorAll('.board-reaction__btn').forEach(function(b) {
                            const type = b.dataset.type;
                            const countEl = document.getElementById('reaction-count-' + type);
                            if (countEl) {
                                const c = (data.counts && data.counts[type]) ? data.counts[type] : 0;
                                countEl.textContent = c;
                            }
                        });

                        // 내 반응 활성화
                        if (data.my_reaction) {
                            const activeBtn = document.querySelector('.board-reaction__btn[data-type="' + data.my_reaction + '"]');
                            if (activeBtn) {
                                activeBtn.classList.add('board-reaction__btn--active');
                                activeBtn.style.setProperty('--reaction-color', activeBtn.dataset.color);
                            }
                        }

                        // 합계 요약 갱신
                        refreshReactionSummary();
                    } else {
                        MubloRequest.showAlert(res.message || '처리에 실패했습니다.', 'error');
                    }
                });
            });
        });
    })();
    </script>
    <?php endif; ?>
</div>
