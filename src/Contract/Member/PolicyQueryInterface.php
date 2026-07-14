<?php

namespace Mublo\Contract\Member;

interface PolicyQueryInterface
{
    /** @return list<PolicyDocument> */
    public function activeDocuments(int $domainId): array;

    public function renderDocument(PolicyDocument $document, array $domainConfig): string;
}
