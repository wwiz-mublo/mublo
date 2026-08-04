<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Repository\AI\AiAssetRepository;

class AiAssetService
{
    public function __construct(
        private AiAssetRepository $assets,
        private AiAssetPolicy $policy,
        private DocumentTextExtractor $extractor,
        private SecureFileService $files,
    ) {}

    public function list(int $domainId): array
    {
        return array_map(fn (array $asset) => $this->publicAsset($asset), $this->assets->listByDomain($domainId));
    }

    public function detail(int $domainId, int $assetId): ?array
    {
        $asset = $this->assets->find($domainId, $assetId);
        if (!$asset) return null;

        return $this->publicAsset($asset) + [
            'sha256' => (string) ($asset['sha256'] ?? ''),
            'extracted_text' => (string) ($asset['extracted_text'] ?? ''),
            'created_by' => isset($asset['created_by']) ? (int) $asset['created_by'] : null,
            'updated_at' => $asset['updated_at'] ?? null,
            'file_url' => '/admin/block-editor/ai-asset-file?id=' . (int) $asset['asset_id'],
        ];
    }

    public function upload(UploadedFile $file, int $domainId, ?int $memberId = null): array
    {
        $checked = $this->policy->assertUpload($file);
        $sha256 = hash_file('sha256', $file->getTmpName());
        $existing = $this->assets->findByHash($domainId, $sha256);
        if ($existing) return $this->publicAsset($existing) + ['duplicate' => true];

        $extracted = $this->extractor->extract($file->getTmpName(), $checked['extension']);
        $upload = $this->files->uploadTemp($file, $domainId, [
            'allowed_ext' => AiConfig::assets()['allowed_extensions'],
            'max_size' => 32,
        ]);
        if ($upload->isFailure()) throw new \InvalidArgumentException($upload->getMessage());

        $relativeTemp = trim((string) $upload->getRelativePath(), '/') . '/' . $upload->getStoredName();
        $entityId = bin2hex(random_bytes(8));
        $stored = $this->files->moveFinal($relativeTemp, $domainId, 'ai-assets', $entityId);
        try {
            $assetId = $this->assets->create([
                'domain_id' => $domainId,
                'kind' => $checked['is_image'] ? 'image' : 'document',
                'title' => pathinfo($file->getName(), PATHINFO_FILENAME),
                'original_name' => $file->getName(),
                'extension' => $checked['extension'],
                'mime_type' => $stored->mimeType,
                'file_size' => $stored->size,
                'storage_path' => $stored->relativePath,
                'sha256' => $sha256,
                'extracted_text' => $extracted,
                'metadata_json' => json_encode($checked['metadata'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'created_by' => $memberId,
            ]);
        } catch (\Throwable $e) {
            $this->files->delete($stored->relativePath);
            throw $e;
        }
        return $this->publicAsset($this->assets->find($domainId, $assetId) ?? []) + ['duplicate' => false];
    }

    public function resolveForPrompt(int $domainId, array $assetIds): array
    {
        $config = AiConfig::assets();
        $ids = array_values(array_unique(array_filter(array_map('intval', $assetIds), fn (int $id) => $id > 0)));
        if (count($ids) > $config['max_selected_assets']) {
            throw new \InvalidArgumentException('한 요청에서 선택할 수 있는 자료는 최대 ' . $config['max_selected_assets'] . '개입니다.');
        }
        $rows = $this->assets->findMany($domainId, $ids);
        if (count($rows) !== count($ids)) throw new \InvalidArgumentException('선택한 AI 자료를 찾을 수 없습니다.');
        $byId = [];
        foreach ($rows as $row) $byId[(int) $row['asset_id']] = $row;
        $ordered = array_map(fn (int $id) => $byId[$id], $ids);

        $bytes = array_sum(array_map(fn (array $a) => (int) $a['file_size'], $ordered));
        $images = count(array_filter($ordered, fn (array $a) => $a['kind'] === 'image'));
        if ($bytes > $config['max_selected_bytes'] || $images > $config['max_selected_images']) {
            throw new \InvalidArgumentException('선택한 자료의 이미지 수 또는 전체 크기가 허용 한도를 초과했습니다.');
        }

        $textChars = 0;
        return array_map(function (array $asset) use (&$textChars, $config): array {
            $text = (string) ($asset['extracted_text'] ?? '');
            $remaining = max(0, $config['max_extracted_chars_per_request'] - $textChars);
            $text = mb_substr($text, 0, $remaining);
            $textChars += mb_strlen($text);
            return [
                'id' => (int) $asset['asset_id'],
                'kind' => $asset['kind'],
                'name' => $asset['original_name'],
                'mime_type' => $asset['mime_type'],
                'extension' => $asset['extension'],
                'size' => (int) $asset['file_size'],
                'path' => $this->files->fullPathOf($asset['storage_path']),
                'text' => $text,
            ];
        }, $ordered);
    }

    public function findFile(int $domainId, int $assetId): ?array
    {
        $asset = $this->assets->find($domainId, $assetId);
        if (!$asset) return null;
        $path = $this->files->fullPathOf($asset['storage_path']);
        if (!is_file($path)) return null;
        return ['asset' => $asset, 'path' => $path];
    }

    public function delete(int $domainId, int $assetId): bool
    {
        $asset = $this->assets->find($domainId, $assetId);
        if (!$asset) return false;
        if (!$this->assets->delete($domainId, $assetId)) return false;
        $this->files->delete($asset['storage_path']);
        return true;
    }

    public function editImage(int $domainId, int $assetId, string $operation, array $input, ?int $memberId = null): array
    {
        $found = $this->findFile($domainId, $assetId);
        if (!$found || $found['asset']['kind'] !== 'image') throw new \InvalidArgumentException('편집할 이미지를 찾을 수 없습니다.');
        $source = $this->loadImage($found['path'], $found['asset']['mime_type']);
        $width = imagesx($source); $height = imagesy($source);

        $edited = match ($operation) {
            'rotate_left' => imagerotate($source, 90, 0),
            'rotate_right' => imagerotate($source, -90, 0),
            'flip_horizontal' => $this->flipped($source, IMG_FLIP_HORIZONTAL),
            'flip_vertical' => $this->flipped($source, IMG_FLIP_VERTICAL),
            'crop' => imagecrop($source, $this->cropRect($input, $width, $height)),
            'crop_square' => imagecrop($source, $this->aspectCropRect($width, $height, 1.0)),
            'crop_16_9' => imagecrop($source, $this->aspectCropRect($width, $height, 16 / 9)),
            'resize' => $this->resized($source, $input, $width, $height),
            'resize_half' => $this->resized($source, ['width' => max(1, intdiv($width, 2)), 'height' => max(1, intdiv($height, 2))], $width, $height),
            default => throw new \InvalidArgumentException('지원하지 않는 이미지 편집 작업입니다.'),
        };
        if (!$edited instanceof \GdImage) {
            imagedestroy($source);
            throw new \InvalidArgumentException('이미지를 편집하지 못했습니다.');
        }
        if ($edited !== $source) imagedestroy($source);

        $entity = bin2hex(random_bytes(8));
        $targetExtension = function_exists('imagewebp') ? 'webp' : 'png';
        $targetMime = $targetExtension === 'webp' ? 'image/webp' : 'image/png';
        $storedName = bin2hex(random_bytes(16)) . '.' . $targetExtension;
        $relative = "D{$domainId}/ai-assets/{$entity}/{$storedName}";
        $fullPath = $this->files->fullPathOf($relative);
        $dir = dirname($fullPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new \RuntimeException('이미지 저장 폴더를 만들 수 없습니다.');
        $saved = $targetExtension === 'webp' ? imagewebp($edited, $fullPath, 88) : imagepng($edited, $fullPath, 6);
        if (!$saved) throw new \RuntimeException('편집 이미지를 저장하지 못했습니다.');
        $newWidth = imagesx($edited); $newHeight = imagesy($edited); imagedestroy($edited);

        $sha = hash_file('sha256', $fullPath);
        $existing = $this->assets->findByHash($domainId, $sha);
        if ($existing) { @unlink($fullPath); @rmdir($dir); return $this->publicAsset($existing) + ['duplicate' => true]; }
        try {
            $newId = $this->assets->create([
                'domain_id' => $domainId, 'parent_asset_id' => $assetId, 'kind' => 'image',
                'title' => $found['asset']['title'] . ' - 편집본', 'original_name' => $storedName,
                'extension' => $targetExtension, 'mime_type' => $targetMime, 'file_size' => filesize($fullPath) ?: 0,
                'storage_path' => $relative, 'sha256' => $sha,
                'metadata_json' => json_encode(['width' => $newWidth, 'height' => $newHeight, 'operation' => $operation], JSON_UNESCAPED_UNICODE),
                'created_by' => $memberId,
            ]);
        } catch (\Throwable $e) { @unlink($fullPath); throw $e; }
        return $this->publicAsset($this->assets->find($domainId, $newId) ?? []) + ['duplicate' => false];
    }

    private function publicAsset(array $asset): array
    {
        $meta = json_decode((string) ($asset['metadata_json'] ?? ''), true);
        return [
            'id' => (int) ($asset['asset_id'] ?? 0), 'parent_id' => isset($asset['parent_asset_id']) ? (int) $asset['parent_asset_id'] : null,
            'kind' => $asset['kind'] ?? '', 'title' => $asset['title'] ?? '', 'name' => $asset['original_name'] ?? '',
            'extension' => $asset['extension'] ?? '', 'mime_type' => $asset['mime_type'] ?? '', 'size' => (int) ($asset['file_size'] ?? 0),
            'metadata' => is_array($meta) ? $meta : [], 'created_at' => $asset['created_at'] ?? null,
            'preview_url' => ($asset['kind'] ?? '') === 'image' ? '/admin/block-editor/ai-asset-file?id=' . (int) ($asset['asset_id'] ?? 0) : null,
        ];
    }

    private function loadImage(string $path, string $mime): \GdImage
    {
        $data = file_get_contents($path);
        $image = $data === false ? false : @imagecreatefromstring($data);
        if (!$image instanceof \GdImage || !str_starts_with($mime, 'image/')) throw new \InvalidArgumentException('이미지를 읽을 수 없습니다.');
        imagealphablending($image, true); imagesavealpha($image, true);
        return $image;
    }

    private function flipped(\GdImage $image, int $mode): \GdImage
    {
        imageflip($image, $mode); return $image;
    }

    private function cropRect(array $input, int $width, int $height): array
    {
        $x = min($width - 1, max(0, (int) ($input['x'] ?? 0)));
        $y = min($height - 1, max(0, (int) ($input['y'] ?? 0)));
        $w = min($width - $x, max(1, (int) ($input['width'] ?? $width)));
        $h = min($height - $y, max(1, (int) ($input['height'] ?? $height)));
        return ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h];
    }

    private function resized(\GdImage $source, array $input, int $width, int $height): \GdImage
    {
        $targetW = max(1, min(4096, (int) ($input['width'] ?? $width)));
        $targetH = max(1, min(4096, (int) ($input['height'] ?? round($height * $targetW / $width))));
        $result = imagecreatetruecolor($targetW, $targetH);
        imagealphablending($result, false); imagesavealpha($result, true);
        imagecopyresampled($result, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);
        return $result;
    }

    private function aspectCropRect(int $width, int $height, float $ratio): array
    {
        if ($width / $height > $ratio) {
            $cropHeight = $height; $cropWidth = max(1, (int) round($height * $ratio));
        } else {
            $cropWidth = $width; $cropHeight = max(1, (int) round($width / $ratio));
        }
        return [
            'x' => max(0, intdiv($width - $cropWidth, 2)),
            'y' => max(0, intdiv($height - $cropHeight, 2)),
            'width' => $cropWidth, 'height' => $cropHeight,
        ];
    }
}
