<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

use Mublo\Contract\Site\SiteMutationContext;

/** 제품 중립 operation handler에 전달하는 요청 단위 편집 문맥. */
final readonly class SiteEditContext
{
    /**
     * @param array<string, mixed> $snapshot 검증 시점의 정규화 사이트 snapshot
     * @param array<string, array<string, mixed>> $priorResults 선행 operation의 공개 result
     */
    public function __construct(
        public int $domainId,
        public string $changeSetId,
        public array $snapshot,
        public array $priorResults = [],
        public ?SiteMutationContext $mutationContext = null
    ) {
        if ($domainId <= 0
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di', $changeSetId) !== 1) {
            throw new \InvalidArgumentException('Invalid site edit context identity.');
        }
        if ($mutationContext !== null && $mutationContext->domainId !== $domainId) {
            throw new \InvalidArgumentException('Site edit mutation context domain does not match.');
        }
    }

    public function forApply(SiteMutationContext $mutationContext): self
    {
        return new self(
            $this->domainId,
            $this->changeSetId,
            $this->snapshot,
            $this->priorResults,
            $mutationContext
        );
    }

    /** @param array<string, array<string, mixed>> $priorResults */
    public function withPriorResults(array $priorResults): self
    {
        return new self(
            $this->domainId,
            $this->changeSetId,
            $this->snapshot,
            $priorResults,
            $this->mutationContext
        );
    }
}
