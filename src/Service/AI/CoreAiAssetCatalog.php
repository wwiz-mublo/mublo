<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

use Mublo\Contract\AI\AiAssetCatalogInterface;
use Mublo\Contract\AI\AiAssetDescriptor;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Repository\AI\AiAssetRepository;

/** @internal AiAssetCatalogInterface의 Core 구현. */
class CoreAiAssetCatalog implements AiAssetCatalogInterface
{
    public function __construct(
        private AiAssetRepository $assets,
        private SecureFileService $files,
    ) {
    }

    public function list(int $domainId, int $limit = 100): array
    {
        if ($domainId < 1) return [];
        return array_map(
            fn (array $asset): AiAssetDescriptor => $this->descriptor($asset),
            $this->assets->listByDomain($domainId, $limit),
        );
    }

    public function find(int $domainId, int $assetId): ?AiAssetDescriptor
    {
        $asset = $this->findRow($domainId, $assetId);
        return $asset ? $this->descriptor($asset) : null;
    }

    public function readExtractedText(int $domainId, int $assetId, int $maxChars = 50000): ?string
    {
        $asset = $this->findRow($domainId, $assetId);
        if (!$asset) return null;
        $text = trim((string) ($asset['extracted_text'] ?? ''));
        if ($text === '') return null;
        return mb_substr($text, 0, max(1, min(100000, $maxChars)));
    }

    public function readContent(int $domainId, int $assetId, int $maxBytes = 8388608): string
    {
        $asset = $this->findRow($domainId, $assetId);
        if (!$asset) throw new \RuntimeException('AI 자산을 찾을 수 없습니다.');
        $limit = max(1, min(33554432, $maxBytes));
        if ((int) $asset['file_size'] > $limit) throw new \RuntimeException('AI 자산이 읽기 크기 제한을 초과했습니다.');
        $path = $this->files->fullPathOf((string) $asset['storage_path']);
        $content = is_file($path) ? file_get_contents($path) : false;
        if ($content === false) throw new \RuntimeException('AI 자산 파일을 읽을 수 없습니다.');
        return $content;
    }

    private function findRow(int $domainId, int $assetId): ?array
    {
        if ($domainId < 1 || $assetId < 1) return null;
        return $this->assets->find($domainId, $assetId);
    }

    private function descriptor(array $asset): AiAssetDescriptor
    {
        $metadata = json_decode((string) ($asset['metadata_json'] ?? ''), true);
        return new AiAssetDescriptor(
            (int) $asset['asset_id'],
            isset($asset['parent_asset_id']) ? (int) $asset['parent_asset_id'] : null,
            (string) $asset['kind'],
            (string) $asset['title'],
            (string) $asset['original_name'],
            (string) $asset['extension'],
            (string) $asset['mime_type'],
            (int) $asset['file_size'],
            is_array($metadata) ? $metadata : [],
            isset($asset['created_at']) ? (string) $asset['created_at'] : null,
        );
    }
}
