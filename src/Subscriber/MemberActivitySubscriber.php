<?php
declare(strict_types=1);
namespace Mublo\Subscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Member\MemberFormRenderingEvent;
use Mublo\Core\Event\Tracking\PageViewedEvent;
use Mublo\Entity\Domain\Domain;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Repository\Member\MemberActivityRepository;
use Mublo\Service\Member\Event\MemberWithdrawnEvent;

/**
 * 회원 활동 기록
 *
 * - PageViewedEvent: 사이트 설정 '회원 활동 기록'이 켜져 있으면 로그인 회원이 본 페이지를 남긴다.
 *   로그인·회원정보 화면은 남기지 않고, 같은 주소를 1분 안에 다시 보면(새로고침) 한 번만 남긴다.
 *   기록할 때 1% 확률로 보관 기간이 지난 기록을 지운다 (크론 없이 굴러가게).
 * - MemberFormRenderingEvent: 관리자 회원 수정 화면에 "최근 활동" 카드를 끼운다.
 * - MemberWithdrawnEvent: 탈퇴한 회원의 기록을 지운다.
 *
 * 기록은 페이지 렌더 뒤의 부가 작업이라 실패해도 화면에 영향을 주지 않는다.
 */
class MemberActivitySubscriber implements EventSubscriberInterface
{
    /** 남기지 않는 페이지 유형 (로그인·가입, 내 정보) */
    private const SKIP_PAGE_TYPES = ['auth', 'member'];

    /** 같은 주소 재조회를 하나로 보는 간격 (초) */
    private const DEDUPE_SECONDS = 60;

    /** 관리자 화면에 보여 줄 최근 기록 수 */
    private const RECENT_LIMIT = 30;

    /**
     * @param \Closure(): ?Domain $domainResolver 현재 요청의 사이트 (부팅 시점엔 Context 가 없어 늦게 해석)
     * @param \Closure(): int     $random         0~99 난수 (테스트 주입용)
     */
    public function __construct(
        private MemberActivityRepository $repository,
        private \Closure $domainResolver,
        private ?Logger $logger = null,
        private ?\Closure $random = null,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PageViewedEvent::class => 'onPageViewed',
            MemberFormRenderingEvent::class => 'onMemberFormRendering',
            MemberWithdrawnEvent::class => 'onMemberWithdrawn',
        ];
    }

    public function onPageViewed(PageViewedEvent $event): void
    {
        $memberId = (int) ($event->getMemberId() ?? 0);
        $domainId = $event->getDomainId();
        if ($memberId <= 0 || $domainId <= 0 || in_array($event->getPageType(), self::SKIP_PAGE_TYPES, true)) {
            return;
        }

        $domain = ($this->domainResolver)();
        if ($domain === null || !$domain->isMemberActivityLogEnabled()) {
            return;
        }

        $url = mb_substr($event->getUrl(), 0, 500);

        try {
            $latest = $this->repository->findLatest($domainId, $memberId);
            if ($latest !== null
                && $latest['page_url'] === $url
                && time() - (int) strtotime((string) $latest['created_at']) < self::DEDUPE_SECONDS
            ) {
                return;
            }

            $this->repository->insert(
                $domainId,
                $memberId,
                mb_substr($event->getPageType(), 0, 30),
                $url,
                mb_substr(trim($event->getPageTitle()), 0, 200)
            );

            $roll = $this->random !== null ? ($this->random)() : random_int(0, 99);
            if ($roll === 0) {
                $before = date('Y-m-d H:i:s', time() - $domain->getMemberActivityRetentionDays() * 86400);
                $this->repository->purgeOlderThan($domainId, $before);
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('회원 활동 기록 실패', [
                'domain_id' => $domainId,
                'member_id' => $memberId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function onMemberFormRendering(MemberFormRenderingEvent $event): void
    {
        $memberId = (int) ($event->getMemberId() ?? 0);
        if (!$event->isEdit() || $memberId <= 0) {
            return;
        }

        $domain = $event->getContext()->getDomainInfo();
        $domainId = (int) ($event->getContext()->getDomainId() ?? 0);
        if ($domain === null || $domainId <= 0) {
            return;
        }

        $enabled = $domain->isMemberActivityLogEnabled();
        try {
            $rows = $this->repository->findRecent($domainId, $memberId, self::RECENT_LIMIT);
        } catch (\Throwable) {
            // 마이그레이션 전 — 켜 두지 않았다면 조용히 넘어간다
            $rows = [];
        }

        if (!$enabled && $rows === []) {
            return;
        }

        $event->addSection($this->renderSection($rows, $enabled, $domain->getMemberActivityRetentionDays()), 600);
    }

    public function onMemberWithdrawn(MemberWithdrawnEvent $event): void
    {
        try {
            $this->repository->deleteByMember($event->getMemberId());
        } catch (\Throwable $e) {
            $this->logger?->error('탈퇴 회원 활동 기록 삭제 실패 — 수동 정리 필요', [
                'member_id' => $event->getMemberId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<int, array{page_url:string,page_title:string,created_at:string}> $rows
     */
    private function renderSection(array $rows, bool $enabled, int $retentionDays): string
    {
        $h = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $note = $enabled
            ? '회원이 본 페이지를 최근 ' . self::RECENT_LIMIT . '건까지 보여 줍니다. 기록은 ' . $retentionDays . '일 동안 보관합니다.'
            : '회원 활동 기록이 꺼져 있습니다(기본 설정). 아래는 켜져 있던 동안의 기록입니다.';

        $html = '<div class="card mb-4"><div class="card-hero">'
            . '<i class="bi bi-clock-history text-pastel-purple"></i><span>최근 활동</span></div>'
            . '<div class="card-body"><p class="form-text mt-0 mb-2">' . $h($note) . '</p>';

        if ($rows === []) {
            return $html . '<p class="text-muted small mb-0">기록이 없습니다.</p></div></div>';
        }

        $html .= '<div class="table-responsive"><table class="table table-sm align-middle mb-0">'
            . '<thead><tr><th style="width:150px">시각</th><th>페이지</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $url = (string) ($row['page_url'] ?? '');
            $title = trim((string) ($row['page_title'] ?? '')) ?: $url;
            // 상대 경로만 링크로 — 기록된 값이 외부 주소로 새지 않게
            $link = str_starts_with($url, '/') && !str_starts_with($url, '//')
                ? '<a href="' . $h($url) . '" target="_blank" rel="noopener">' . $h($title) . '</a>'
                : $h($title);
            $html .= '<tr><td class="small text-muted text-nowrap">' . $h((string) ($row['created_at'] ?? '')) . '</td>'
                . '<td class="small">' . $link
                . ($title !== $url ? '<br><span class="text-muted">' . $h($url) . '</span>' : '')
                . '</td></tr>';
        }

        return $html . '</tbody></table></div></div></div>';
    }
}
