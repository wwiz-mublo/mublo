<?php
declare(strict_types=1);
namespace Mublo\Packages\Board\Plugins\BoardReport\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Rendering\FrontFootRenderEvent;
use Mublo\Packages\Board\Event\ArticleActionsCollectEvent;
use Mublo\Packages\Board\Event\ArticleDeletedEvent;
use Mublo\Packages\Board\Event\ArticleViewingEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Mublo\Packages\Board\Plugins\BoardReport\Service\BoardReportService;
use Mublo\Contract\Auth\AuthContextInterface;

/**
 * 게시글 이벤트 구독 — 신고 플러그인의 Board 개입 지점 전부.
 *
 * - ArticleActionsCollectEvent: 상세 화면 버튼 영역에 [신고] 추가 (스킨 무수정)
 * - FrontFootRenderEvent: 신고 모달 주입 — [신고] 클릭을 가로채 페이지 이동
 *   없이 모달로 접수한다. JS 가 없으면 링크가 폴백 페이지(/board/report/form)로
 *   그대로 동작한다 (점진적 향상).
 * - ArticleViewingEvent: 블라인드 글 차단
 * - FileDownloadingEvent: 블라인드 글의 첨부 차단 — 본문을 막고 첨부를 열어두면
 *   블라인드가 반쪽이다. 첨부 URL 은 목록·검색 결과·외부 링크로 이미 퍼져 있어
 *   글을 거치지 않고도 눌린다.
 * - ArticleDeletedEvent: 글 삭제 시 신고·블라인드 정리
 */
class ArticleSubscriber implements EventSubscriberInterface
{
    /** 이 요청이 게시글 상세를 렌더했는가 — 모달은 그 페이지에만 주입한다 */
    private bool $renderedArticleView = false;

    public function __construct(
        private BoardReportService $service,
        private AuthContextInterface $authService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            ArticleActionsCollectEvent::class => 'onActionsCollect',
            FrontFootRenderEvent::class => 'onFrontFoot',
            ArticleViewingEvent::class => 'onArticleViewing',
            FileDownloadingEvent::class => 'onFileDownloading',
            ArticleDeletedEvent::class => 'onArticleDeleted',
        ];
    }

    public function onActionsCollect(ArticleActionsCollectEvent $event): void
    {
        $this->renderedArticleView = true;

        $event->addAction([
            'label' => '신고',
            'url'   => '/board/report/form?article_id=' . $event->getArticleId(),
            'class' => 'board-view__btn--report',
            'icon'  => 'bi-flag',
        ]);
    }

    public function onFrontFoot(FrontFootRenderEvent $event): void
    {
        if (!$this->renderedArticleView) {
            return;
        }

        $event->addHtml($this->modalHtml());
    }

    public function onArticleViewing(ArticleViewingEvent $event): void
    {
        // 관리자는 블라인드 글도 읽을 수 있어야 신고를 판단할 수 있다
        if ($this->authService->isAdmin()) {
            return;
        }

        $reason = $this->service->getBlindReason($event->getDomainId(), $event->getArticleId());
        if ($reason !== null) {
            $event->setBlocked(true);
            $event->setBlockReason($reason);
        }
    }

    public function onFileDownloading(FileDownloadingEvent $event): void
    {
        // 글 열람과 같은 기준 — 관리자는 신고 판단을 위해 첨부도 받을 수 있어야 한다
        if ($this->authService->isAdmin()) {
            return;
        }

        $reason = $this->service->getBlindReason($event->getDomainId(), $event->getArticleId());
        if ($reason !== null) {
            $event->setBlocked(true);
            $event->setBlockReason($reason);
        }
    }

    public function onArticleDeleted(ArticleDeletedEvent $event): void
    {
        $article = $event->getArticle();
        $this->service->cleanupArticle($article->getDomainId(), $article->getArticleId());
    }

    /**
     * 신고 모달 마크업 + 스타일 + 스크립트
     *
     * 자기 완결적으로 유지한다 — 프론트 프레임 스킨이 무엇이든 동작해야
     * 하므로 사이트 토큰(var(--...))은 폴백과 함께만 쓴다.
     */
    private function modalHtml(): string
    {
        $radios = '';
        $first = true;
        foreach (BoardReportService::REASONS as $code => $label) {
            $checked = $first ? 'checked' : '';
            $first = false;
            $code = htmlspecialchars($code);
            $label = htmlspecialchars($label);
            $radios .= "<label class=\"brp-reason\"><input type=\"radio\" name=\"brp_reason\" value=\"{$code}\" {$checked}> {$label}</label>\n";
        }

        return <<<HTML
<div id="brpModal" class="brp-overlay" hidden>
    <div class="brp-panel" role="dialog" aria-modal="true" aria-labelledby="brpTitle">
        <h3 id="brpTitle" class="brp-title">게시글 신고</h3>
        <div id="brpBody">
            <fieldset class="brp-fieldset">
                <legend>신고 사유</legend>
                {$radios}
            </fieldset>
            <textarea id="brpDetail" rows="3" maxlength="2000" placeholder="상세 내용 (선택)"></textarea>
            <div class="brp-actions">
                <button type="button" class="brp-btn" data-brp-close>취소</button>
                <button type="button" class="brp-btn brp-btn--primary" id="brpSubmit">신고하기</button>
            </div>
        </div>
    </div>
</div>
<style>
.brp-overlay{position:fixed;inset:0;z-index:10050;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.6);}
.brp-overlay[hidden]{display:none;} /* hidden 속성이 author display 에 지지 않도록 */
/* 다크에서 --background 는 페이지 배경과 같은 zinc-950 이라 패널이 배경에 묻힌다.
   모달 표면 토큰인 --popover(다크 zinc-900)로 한 단 띄우고, 그림자가 안 먹는
   다크를 위해 테두리로도 경계를 만든다 */
.brp-panel{width:min(420px,calc(100vw - 32px));background:var(--popover,#fff);color:var(--popover-foreground,#222);border:1px solid var(--border,#ddd);border-radius:12px;padding:20px;box-shadow:0 12px 40px rgba(0,0,0,.35);}
.brp-title{margin:0 0 14px;font-size:1.1rem;}
.brp-fieldset{border:1px solid var(--border,#ddd);border-radius:8px;padding:10px 14px;margin:0 0 12px;}
.brp-fieldset legend{font-size:.85rem;padding:0 6px;}
.brp-reason{display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;font-size:.95rem;}
#brpDetail{width:100%;border:1px solid var(--border,#ddd);border-radius:8px;padding:10px;margin-bottom:14px;background:inherit;color:inherit;box-sizing:border-box;}
.brp-actions{display:flex;gap:8px;justify-content:flex-end;}
.brp-btn{padding:8px 18px;border:1px solid var(--border,#ccc);border-radius:6px;background:transparent;color:inherit;cursor:pointer;}
/* 다크에서 --primary 는 밝은 색(zinc-200)이므로 글자색을 #fff 로 박으면 안 읽힌다.
   짝 토큰인 --primary-foreground 를 쓰면 라이트/다크 양쪽에서 대비가 유지된다 */
.brp-btn--primary{background:var(--primary,#4f6ef7);border-color:var(--primary,#4f6ef7);color:var(--primary-foreground,#fff);}
.brp-message{padding:14px 4px;text-align:center;}
</style>
<script>
(function () {
    var modal = document.getElementById('brpModal');
    if (!modal) return;
    var articleId = 0;

    // [신고] 링크 가로채기 — JS 없으면 링크가 폴백 페이지로 동작한다
    document.addEventListener('click', function (e) {
        var link = e.target.closest('.board-view__btn--report');
        if (!link) return;
        e.preventDefault();
        var m = (link.getAttribute('href') || '').match(/article_id=(\\d+)/);
        articleId = m ? m[1] : 0;
        modal.hidden = false;
    });

    modal.addEventListener('click', function (e) {
        if (e.target === modal || e.target.closest('[data-brp-close]')) modal.hidden = true;
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') modal.hidden = true;
    });

    document.getElementById('brpSubmit').addEventListener('click', function () {
        var btn = this;
        var checked = modal.querySelector('input[name="brp_reason"]:checked');
        var fd = new FormData();
        var meta = document.querySelector('meta[name="csrf-token"]');
        fd.append('_token', meta ? meta.content : '');
        fd.append('article_id', articleId);
        fd.append('reason', checked ? checked.value : '');
        fd.append('detail', document.getElementById('brpDetail').value.trim());

        btn.disabled = true;
        fetch('/board/report/submit', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: fd
        }).then(function (r) { return r.json(); }).then(function (res) {
            btn.disabled = false;
            var ok = res.result === 'success';
            document.getElementById('brpBody').innerHTML =
                '<div class="brp-message">' + (res.message || (ok ? '신고가 접수되었습니다.' : '접수에 실패했습니다.')) + '</div>'
                + '<div class="brp-actions"><button type="button" class="brp-btn" data-brp-close>닫기</button></div>';
        }).catch(function () {
            btn.disabled = false;
            document.getElementById('brpBody').insertAdjacentHTML('afterbegin',
                '<div class="brp-message" style="color:var(--destructive,#b02a37);">요청에 실패했습니다. 잠시 후 다시 시도해주세요.</div>');
        });
    });
})();
</script>
HTML;
    }
}
