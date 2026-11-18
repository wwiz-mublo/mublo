<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Block;

use Mublo\Core\Event\AbstractEvent;

/**
 * BlockPositionRenderingEvent
 *
 * 위치별 블록 렌더링(`BlockRenderService::renderPosition()`) 직전에 발행되는 확장점.
 * 확장은 이 요청에서 그 위치를 저장된 블록 대신 다른 HTML 로 그리거나, 블록 캐시를 쓰지 않게 할 수 있다.
 * 저장된 블록 데이터(DB)는 바꾸지 않는다 — 이번 렌더링에만 적용된다.
 *
 * 쓰임:
 *  - 디자인 미리보기: 적용 전 구성을 메인(index)에 그려 보이되, 운영 캐시에 섞이지 않게 캐시를 끈다
 *  - 특별한 진입(예: 브랜드 전용 화면)에서 메인을 코드가 만든 구성으로 대체
 *
 * 규칙:
 *  - `replaceWith()` 는 그 위치 전체를 대체하고 전파를 멈춘다 — 우선순위가 가장 높은 대체가 이긴다.
 *    대체 HTML 은 행 캐시를 거치지 않으며, 캐시가 필요하면 대체하는 쪽이 직접 둔다.
 *  - `disableCache()` 는 이번 렌더링에서 행 목록·행 HTML 캐시를 읽지도 쓰지도 않게 한다.
 *  - 메뉴별 위치(`getMenuCode()` 가 null 이 아님)도 같은 이벤트로 오므로, 전역 위치만 바꾸려면 직접 거른다.
 *
 * 사용 예:
 * ```php
 * public function onBlockPositionRendering(BlockPositionRenderingEvent $event): void
 * {
 *     if ($this->preview === null || $event->getMenuCode() !== null) {
 *         return;
 *     }
 *     $event->disableCache();
 *     if ($event->getPosition() === 'index') {
 *         $event->replaceWith($this->renderPreviewMain($event->getDomainId()));
 *     }
 * }
 * ```
 */
class BlockPositionRenderingEvent extends AbstractEvent
{
    private ?string $replacement = null;
    private bool $cacheDisabled = false;

    public function __construct(
        private readonly int $domainId,
        private readonly string $position,
        private readonly ?string $menuCode = null,
        private readonly bool $isMainScreen = false
    ) {}

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /** 위치 값 (`Mublo\Enum\Block\BlockPosition` 의 value — index, left, right, topbar ...) */
    public function getPosition(): string
    {
        return $this->position;
    }

    /** 메뉴별 위치면 메뉴 코드, 전역 위치면 null */
    public function getMenuCode(): ?string
    {
        return $this->menuCode;
    }

    /** 메인 화면 렌더링인지 */
    public function isMainScreen(): bool
    {
        return $this->isMainScreen;
    }

    /**
     * 이 위치를 저장된 블록 대신 $html 로 그린다. 전파를 멈춘다.
     */
    public function replaceWith(string $html): void
    {
        $this->replacement = $html;
        $this->stopPropagation();
    }

    public function isReplaced(): bool
    {
        return $this->replacement !== null;
    }

    public function getReplacement(): ?string
    {
        return $this->replacement;
    }

    /**
     * 이번 렌더링은 블록 캐시를 읽지도 쓰지도 않는다.
     */
    public function disableCache(): void
    {
        $this->cacheDisabled = true;
    }

    public function isCacheDisabled(): bool
    {
        return $this->cacheDisabled;
    }
}
