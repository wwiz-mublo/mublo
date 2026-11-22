<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/** 사이트 변경 revision을 코어 테이블에 기록하는 안정 API. */
interface SiteMutationRecorderInterface
{
    /**
     * 활성 Database::transaction() 안에서 호출해야 한다.
     * 실제 DB 변경이 없으면 빈 문맥을 기록하지 않는다.
     */
    public function record(SiteMutationContext $context): SiteMutationReceipt;

    public function currentSiteRevision(int $domainId): int;

    /**
     * @param list<string> $resourceIds
     * @return array<string, array{resource_type:string,revision:int,fingerprint:string}>
     */
    public function resourceRevisions(int $domainId, array $resourceIds): array;
}
