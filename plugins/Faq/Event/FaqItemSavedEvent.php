<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * FAQ 항목 저장(등록·수정) 뒤 이벤트
 *
 * 저장 요청에 실린 값 전체를 그대로 넘긴다 — 편집 창 확장 칸(FaqItemFormEvent)이 'faq:item-collect' 로
 * 넣은 값을 패키지가 getInput() 으로 꺼내 자기 테이블에 저장한다. 확장 칸이 넣지 않은 키는 없다
 * (다른 곳에서 저장했을 때 패키지 값을 지우지 않도록, 키가 없으면 건드리지 않는다).
 */
class FaqItemSavedEvent extends AbstractEvent
{
    public function __construct(
        private readonly int $domainId,
        private readonly int $faqId,
        private readonly array $input,
        private readonly bool $isUpdate
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    public function getFaqId(): int
    {
        return $this->faqId;
    }

    public function isUpdate(): bool
    {
        return $this->isUpdate;
    }

    public function hasInput(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function getInput(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }
}
