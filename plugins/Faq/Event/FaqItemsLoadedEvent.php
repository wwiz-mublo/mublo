<?php
declare(strict_types=1);

namespace Mublo\Plugin\Faq\Event;

use Mublo\Core\Event\AbstractEvent;

/**
 * FAQ 항목을 읽은 뒤 확장 값을 붙이는 이벤트
 *
 * - scope 'admin': 관리자 항목 목록(/admin/faq/items). 패키지는 setExtra() 로 항목별 값을 붙인다 —
 *   항목의 ext[키] 로 화면에 내려가 편집 창 확장 칸(FaqItemFormEvent)이 쓴다.
 *   addBadge() 로 목록의 질문 아래에 짧은 표시(예: '연결 상품 2')를 달 수 있다.
 * - scope 'front': 손님 FAQ 화면(/faq). addRelatedLink() 로 답변 아래 '관련 링크'를 단다.
 *
 * 플러그인은 붙은 값의 뜻을 모른다. 항목에 없는 faq_id 로 붙인 값은 버린다.
 */
class FaqItemsLoadedEvent extends AbstractEvent
{
    public const SCOPE_ADMIN = 'admin';
    public const SCOPE_FRONT = 'front';

    /** @var array<int, array<string, mixed>> faq_id => [key => value] */
    private array $extras = [];
    /** @var array<int, string[]> */
    private array $badges = [];
    /** @var array<int, array<int, array{label:string, url:string}>> */
    private array $links = [];
    /** 손님 화면에서 관련 링크 앞에 붙는 말 */
    private string $relatedTitle = '관련 링크';

    /**
     * @param int[] $faqIds 이번에 읽은 항목들
     */
    public function __construct(
        private readonly int $domainId,
        private readonly array $faqIds,
        private readonly string $scope
    ) {
    }

    public function getDomainId(): int
    {
        return $this->domainId;
    }

    /** @return int[] */
    public function getFaqIds(): array
    {
        return $this->faqIds;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function setExtra(int $faqId, string $key, mixed $value): void
    {
        $this->extras[$faqId][$key] = $value;
    }

    public function addBadge(int $faqId, string $text): void
    {
        if (trim($text) !== '') {
            $this->badges[$faqId][] = $text;
        }
    }

    public function addRelatedLink(int $faqId, string $label, string $url): void
    {
        $label = trim($label);
        $url = trim($url);
        if ($label === '' || $url === '') {
            return;
        }
        $this->links[$faqId][] = ['label' => $label, 'url' => $url];
    }

    /** 손님 화면의 관련 링크 제목 (예: '관련 상품') */
    public function setRelatedTitle(string $title): void
    {
        if (trim($title) !== '') {
            $this->relatedTitle = trim($title);
        }
    }

    public function getRelatedTitle(): string
    {
        return $this->relatedTitle;
    }

    /** @return array<string, mixed> */
    public function getExtras(int $faqId): array
    {
        return $this->extras[$faqId] ?? [];
    }

    /** @return string[] */
    public function getBadges(int $faqId): array
    {
        return $this->badges[$faqId] ?? [];
    }

    /** @return array<int, array{label:string, url:string}> */
    public function getRelatedLinks(int $faqId): array
    {
        return $this->links[$faqId] ?? [];
    }
}
