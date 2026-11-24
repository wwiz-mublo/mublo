<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * FAQ 항목 편집 창 확장 이벤트
 *
 * 관리자 FAQ 화면(/admin/faq)을 그릴 때 한 번 발행한다. 패키지는 항목 편집 창(#itemModal)의
 * 답변 칸 아래에 자기 입력 칸(HTML + 스크립트)을 넣을 수 있다. 플러그인은 그 칸이 무엇인지 모른다.
 *
 * 편집 창과 확장 칸은 DOM 이벤트로 주고받는다 (#itemModal 요소에서 발생):
 *   - 'faq:item-open'    detail = { item: 항목(새 항목이면 null) } — 칸을 그 항목 값으로 채운다.
 *                         항목에는 FaqItemsLoadedEvent(scope=admin)가 붙인 ext 값이 들어 있다.
 *   - 'faq:item-collect' detail = { data: 저장할 값 } — 칸의 값을 data 에 넣는다.
 *                         넣은 값은 저장 요청에 그대로 실려 FaqItemSavedEvent::getInput() 으로 돌아온다.
 *
 * 패키지 구독 예시:
 *   $event->addSection('<div class="mb-3">…</div><script>…</script>');
 */
class FaqItemFormEvent extends AbstractEvent
{
    /** @var string[] */
    private array $sections = [];

    public function __construct(private readonly int $domainId)
    {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /** 편집 창에 넣을 HTML 조각 (그대로 출력된다 — 이스케이프는 넣는 쪽 책임) */
    public function addSection(string $html): void
    {
        if (trim($html) !== '') {
            $this->sections[] = $html;
        }
    }

    /** @return string[] */
    public function getSections(): array
    {
        return $this->sections;
    }
}
