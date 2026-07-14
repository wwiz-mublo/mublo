<?php

namespace Mublo\Contract\Member;

final readonly class PolicyDocument
{
    public function __construct(
        public int $policyId,
        public int $revisionId,
        public int $domainId,
        public string $version,
        public string $title,
        public string $content,
        public string $contentHash,
        public bool $required,
        public bool $active,
        public string $createdAt,
    ) {
    }
}
