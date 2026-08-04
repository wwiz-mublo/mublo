<?php
declare(strict_types=1);
namespace Mublo\Contract\AI;

/** 도메인별 재사용 AI 자산 공개 조회 계약. */
interface AiAssetCatalogInterface
{
    /** @return AiAssetDescriptor[] */
    public function list(int $domainId, int $limit = 100): array;

    public function find(int $domainId, int $assetId): ?AiAssetDescriptor;

    public function readExtractedText(int $domainId, int $assetId, int $maxChars = 50000): ?string;

    public function readContent(int $domainId, int $assetId, int $maxBytes = 8388608): string;
}
