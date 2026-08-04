<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

interface PolicyQueryInterface
{
    /** @return list<PolicyDocument> */
    public function activeDocuments(int $domainId): array;

    public function findDocument(int $domainId, int $policyId): ?PolicyDocument;

    public function renderDocument(PolicyDocument $document, array $domainConfig): string;
}
