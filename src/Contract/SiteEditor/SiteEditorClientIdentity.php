<?php
declare(strict_types=1);

namespace Mublo\Contract\SiteEditor;

/** 사이트 편집 API 요청을 승인한 제품 인증 어댑터의 최소 결과. */
final readonly class SiteEditorClientIdentity
{
    /** @param list<string> $scopes */
    public function __construct(
        public int $domainId,
        public string $clientId,
        public array $scopes
    ) {
        if ($domainId <= 0 || trim($clientId) === '') {
            throw new \InvalidArgumentException('Invalid site editor client identity.');
        }
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z][a-z0-9_.:-]*$/D', $scope) !== 1) {
                throw new \InvalidArgumentException('Invalid site editor client scope.');
            }
        }
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}
