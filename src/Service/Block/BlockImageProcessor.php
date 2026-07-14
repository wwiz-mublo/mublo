<?php

namespace Mublo\Service\Block;

use Mublo\Infrastructure\Storage\FileUploader;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Repository\Block\BlockImageReferenceRepository;

/**
 * 블록 행/칸 이미지의 업로드와 삭제를 담당한다.
 *
 * HTTP Request를 받지 않고 원시 $_FILES 구조만 입력받아 컨트롤러 밖에서
 * 업로드 동작을 특성화 테스트할 수 있게 한다.
 */
class BlockImageProcessor
{
    private string $publicPath;
    private string $storagePath;

    public function __construct(
        private FileUploader $uploader,
        ?string $publicPath = null,
        ?string $storagePath = null,
        private ?BlockImageReferenceRepository $referenceRepository = null
    ) {
        $this->publicPath = $publicPath ?? MUBLO_PUBLIC_PATH;
        $this->storagePath = $storagePath ?? MUBLO_PUBLIC_STORAGE_PATH;
    }

    public function processColumnImages(
        int $columnIndex,
        array $contentItems,
        int $domainId,
        ?array $files,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array {
        return $this->processImageItems(
            $contentItems,
            fn (int $imgIndex, string $type): ?UploadedFile => $files === null
                ? null
                : $this->getNestedFile($files, $columnIndex, $imgIndex, $type),
            $files !== null,
            ($columnIndex + 1) . '번 칸',
            $domainId,
            $mutation,
            $uploadErrors
        );
    }

    /**
     * 스택 자식 콘텐츠(contents[j])의 이미지 업로드 — 파일 채널은
     * column_content_images[칸][콘텐츠][이미지][pc|mo] 로 칸 scalar 채널과 분리된다.
     */
    public function processStackContentImages(
        int $columnIndex,
        int $contentIndex,
        array $contentItems,
        int $domainId,
        ?array $files,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array {
        return $this->processImageItems(
            $contentItems,
            fn (int $imgIndex, string $type): ?UploadedFile => $files === null
                ? null
                : $this->getStackContentFile($files, $columnIndex, $contentIndex, $imgIndex, $type),
            $files !== null,
            ($columnIndex + 1) . '번 칸 ' . ($contentIndex + 1) . '번 콘텐츠',
            $domainId,
            $mutation,
            $uploadErrors
        );
    }

    /**
     * 이미지 항목 목록 공통 처리 — 업로드(pc/mo_has_file)·삭제(pc/mo_del).
     * $hasFiles 가 false 면 기존 계약대로 has_file 플래그를 건드리지 않는다.
     *
     * @param callable(int, string): ?UploadedFile $fileResolver
     */
    private function processImageItems(
        array $contentItems,
        callable $fileResolver,
        bool $hasFiles,
        string $labelPrefix,
        int $domainId,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors
    ): array {
        $images = $contentItems;

        foreach ($images as $imgIndex => &$image) {
            if (!empty($image['pc_has_file']) && $hasFiles) {
                $pcFile = $fileResolver($imgIndex, 'pc');
                if ($pcFile && !$pcFile->isValid()) {
                    $uploadErrors[] = sprintf(
                        '%s %d번 이미지(PC): %s',
                        $labelPrefix,
                        $imgIndex + 1,
                        $this->invalidUploadMessage($pcFile)
                    );
                } elseif ($pcFile) {
                    $result = $this->uploader->upload($pcFile, $domainId, [
                        'subdirectory' => 'block/images',
                        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    ]);

                    if ($result->isSuccess()) {
                        $oldImage = $image['pc_image'] ?? '';
                        $image['pc_image'] = '/storage/' . $result->getRelativePath() . '/' . $result->getStoredName();
                        $mutation->recordCreated($image['pc_image']);
                        $mutation->recordObsolete((string) $oldImage);
                    } else {
                        $uploadErrors[] = sprintf(
                            '%s %d번 이미지(PC): %s',
                            $labelPrefix,
                            $imgIndex + 1,
                            $result->getMessage()
                        );
                    }
                }
                unset($image['pc_has_file']);
            }

            if (!empty($image['mo_has_file']) && $hasFiles) {
                $moFile = $fileResolver($imgIndex, 'mo');
                if ($moFile && !$moFile->isValid()) {
                    $uploadErrors[] = sprintf(
                        '%s %d번 이미지(모바일): %s',
                        $labelPrefix,
                        $imgIndex + 1,
                        $this->invalidUploadMessage($moFile)
                    );
                } elseif ($moFile) {
                    $result = $this->uploader->upload($moFile, $domainId, [
                        'subdirectory' => 'block/images',
                        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    ]);

                    if ($result->isSuccess()) {
                        $oldImage = $image['mo_image'] ?? '';
                        $image['mo_image'] = '/storage/' . $result->getRelativePath() . '/' . $result->getStoredName();
                        $mutation->recordCreated($image['mo_image']);
                        $mutation->recordObsolete((string) $oldImage);
                    } else {
                        $uploadErrors[] = sprintf(
                            '%s %d번 이미지(모바일): %s',
                            $labelPrefix,
                            $imgIndex + 1,
                            $result->getMessage()
                        );
                    }
                }
                unset($image['mo_has_file']);
            }

            if (!empty($image['pc_del'])) {
                $mutation->recordObsolete((string) ($image['pc_image'] ?? ''));
                $image['pc_image'] = '';
                unset($image['pc_del']);
            }

            if (!empty($image['mo_del'])) {
                $mutation->recordObsolete((string) ($image['mo_image'] ?? ''));
                $image['mo_image'] = '';
                unset($image['mo_del']);
            }
        }
        unset($image);

        return $images;
    }

    public function processTitleImages(
        int $columnIndex,
        array $titleConfig,
        int $domainId,
        ?array $files,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array {
        return $this->processTitleConfigImages(
            $titleConfig,
            fn (string $type): ?UploadedFile => $files === null
                ? null
                : $this->getTitleImageFile($files, $columnIndex, $type),
            $files !== null,
            ($columnIndex + 1) . '번 칸',
            $domainId,
            $mutation,
            $uploadErrors
        );
    }

    /**
     * 스택 자식 콘텐츠의 제목 이미지 — 파일 채널은
     * column_content_title_images[칸][콘텐츠][pc|mo] 로 칸 채널과 분리된다.
     */
    public function processStackContentTitleImages(
        int $columnIndex,
        int $contentIndex,
        array $titleConfig,
        int $domainId,
        ?array $files,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array {
        return $this->processTitleConfigImages(
            $titleConfig,
            fn (string $type): ?UploadedFile => $files === null
                ? null
                : $this->getStackContentTitleImageFile($files, $columnIndex, $contentIndex, $type),
            $files !== null,
            ($columnIndex + 1) . '번 칸 ' . ($contentIndex + 1) . '번 콘텐츠',
            $domainId,
            $mutation,
            $uploadErrors
        );
    }

    /**
     * 제목 설정 공통 처리 — 업로드(pc/mo_image_has_file)·삭제(pc/mo_image_del).
     *
     * @param callable(string): ?UploadedFile $fileResolver
     */
    private function processTitleConfigImages(
        array $titleConfig,
        callable $fileResolver,
        bool $hasFiles,
        string $labelPrefix,
        int $domainId,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors
    ): array {
        foreach (['pc', 'mo'] as $type) {
            $key = "{$type}_image";
            $hasFileKey = "{$type}_image_has_file";
            $delKey = "{$type}_image_del";

            if (!empty($titleConfig[$delKey])) {
                $mutation->recordObsolete((string) ($titleConfig[$key] ?? ''));
                $titleConfig[$key] = '';
                unset($titleConfig[$delKey]);
                continue;
            }

            if (!empty($titleConfig[$hasFileKey]) && $hasFiles) {
                $file = $fileResolver($type);
                $label = $type === 'pc' ? 'PC' : '모바일';
                if ($file && !$file->isValid()) {
                    $uploadErrors[] = sprintf(
                        '%s 제목 이미지(%s): %s',
                        $labelPrefix,
                        $label,
                        $this->invalidUploadMessage($file)
                    );
                } elseif ($file) {
                    $result = $this->uploader->upload($file, $domainId, [
                        'subdirectory' => 'block/titles',
                        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                    ]);

                    if ($result->isSuccess()) {
                        $oldImage = $titleConfig[$key] ?? '';
                        $titleConfig[$key] = '/storage/' . $result->getRelativePath() . '/' . $result->getStoredName();
                        $mutation->recordCreated($titleConfig[$key]);
                        $mutation->recordObsolete((string) $oldImage);
                    } else {
                        $uploadErrors[] = sprintf(
                            '%s 제목 이미지(%s): %s',
                            $labelPrefix,
                            $label,
                            $result->getMessage()
                        );
                    }
                }
                unset($titleConfig[$hasFileKey]);
            }
        }

        return $titleConfig;
    }

    public function processBackgroundConfig(
        array $formData,
        int $domainId,
        ?array $rawFile,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array
    {
        $bgConfig = [];

        if (!empty($formData['bg_color'])) {
            $bgConfig['color'] = $formData['bg_color'];
        }

        if (!empty($formData['bg_gradient'])) {
            $bgConfig['gradient'] = $formData['bg_gradient'];
        }

        $existingImage = $formData['bg_image_old'] ?? '';
        $deleteImage = !empty($formData['bg_image_del']);
        $uploadedFile = $rawFile
            ? $this->uploadedFileFromParts(
                $rawFile['name'] ?? null,
                $rawFile['type'] ?? '',
                $rawFile['tmp_name'] ?? null,
                $rawFile['error'] ?? UPLOAD_ERR_NO_FILE,
                $rawFile['size'] ?? 0
            )
            : null;

        if ($uploadedFile && !$uploadedFile->isValid()) {
            if ($existingImage) {
                $bgConfig['image'] = $existingImage;
            }
            $uploadErrors[] = '배경 이미지: ' . $this->invalidUploadMessage($uploadedFile);
        } elseif ($uploadedFile) {
            $result = $this->uploader->upload($uploadedFile, $domainId, [
                'subdirectory' => 'block/bg',
                'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
            ]);

            if ($result->isSuccess()) {
                $bgConfig['image'] = '/storage/' . $result->getRelativePath() . '/' . $result->getStoredName();
                $mutation->recordCreated($bgConfig['image']);
                $mutation->recordObsolete((string) $existingImage);
            } else {
                if ($existingImage) {
                    $bgConfig['image'] = $existingImage;
                }
                $uploadErrors[] = '배경 이미지: ' . $result->getMessage();
            }
        } elseif (!$deleteImage && $existingImage) {
            $bgConfig['image'] = $existingImage;
        } elseif ($deleteImage && $existingImage) {
            $mutation->recordObsolete((string) $existingImage);
        }

        if (!empty($bgConfig['image'])) {
            $bgConfig['size'] = $formData['bg_size'] ?? 'cover';
            $bgConfig['position'] = $formData['bg_position'] ?? 'center center';
            $bgConfig['repeat'] = $formData['bg_repeat'] ?? 'no-repeat';
            $bgConfig['attachment'] = $formData['bg_attachment'] ?? 'scroll';
        }

        $formData['background_config'] = $bgConfig;

        unset($formData['bg_color']);
        unset($formData['bg_gradient']);
        unset($formData['bg_image_old']);
        unset($formData['bg_image_del']);
        unset($formData['bg_size']);
        unset($formData['bg_position']);
        unset($formData['bg_repeat']);
        unset($formData['bg_attachment']);

        return $formData;
    }

    /** DB 저장 성공 후 더 이상 참조되지 않는 기존 파일을 제거한다. */
    public function commit(BlockImageMutationPlan $mutation): void
    {
        $this->cleanupUnreferenced($mutation->obsoleteImages());
    }

    /** 저장 실패 또는 미리보기 종료 시 이번 요청에서 생성한 파일만 제거한다. */
    public function rollback(BlockImageMutationPlan $mutation): void
    {
        foreach ($mutation->createdImages() as $imageUrl) {
            $this->deleteBlockImage($imageUrl);
        }
    }

    /** @param string[] $imageUrls */
    public function cleanupUnreferenced(array $imageUrls): void
    {
        foreach (array_unique($imageUrls) as $imageUrl) {
            if ($this->referenceRepository?->isReferenced($imageUrl)) {
                continue;
            }
            $this->deleteBlockImage($imageUrl);
        }
    }

    /**
     * 행·칸 JSON에서 관리 대상 업로드 URL을 재귀적으로 수집한다.
     *
     * @return string[]
     */
    public function collectManagedImages(mixed ...$values): array
    {
        $images = [];
        $walk = function (mixed $value) use (&$walk, &$images): void {
            if (is_string($value)) {
                if (str_starts_with($value, '/storage/')) {
                    $images[$value] = true;
                    return;
                }
                // HTML 직접입력 안의 업로드 경로도 고아 정리 대상에 포함한다.
                if (str_contains($value, '/storage/')) {
                    preg_match_all('#/storage/[A-Za-z0-9_./-]+#', $value, $matches);
                    foreach ($matches[0] ?? [] as $url) {
                        $images[$url] = true;
                    }
                }
                return;
            }
            if (is_array($value)) {
                foreach ($value as $item) {
                    $walk($item);
                }
            }
        };

        foreach ($values as $value) {
            $walk($value);
        }

        return array_keys($images);
    }

    private function getTitleImageFile(array $files, int $columnIndex, string $type): ?UploadedFile
    {
        return $this->uploadedFileFromParts(
            $files['name'][$columnIndex][$type] ?? null,
            $files['type'][$columnIndex][$type] ?? '',
            $files['tmp_name'][$columnIndex][$type] ?? null,
            $files['error'][$columnIndex][$type] ?? UPLOAD_ERR_NO_FILE,
            $files['size'][$columnIndex][$type] ?? 0
        );
    }

    private function getNestedFile(array $files, int $columnIndex, int $imageIndex, string $type): ?UploadedFile
    {
        return $this->uploadedFileFromParts(
            $files['name'][$columnIndex][$imageIndex][$type] ?? null,
            $files['type'][$columnIndex][$imageIndex][$type] ?? '',
            $files['tmp_name'][$columnIndex][$imageIndex][$type] ?? null,
            $files['error'][$columnIndex][$imageIndex][$type] ?? UPLOAD_ERR_NO_FILE,
            $files['size'][$columnIndex][$imageIndex][$type] ?? 0
        );
    }

    private function getStackContentTitleImageFile(
        array $files,
        int $columnIndex,
        int $contentIndex,
        string $type
    ): ?UploadedFile {
        return $this->uploadedFileFromParts(
            $files['name'][$columnIndex][$contentIndex][$type] ?? null,
            $files['type'][$columnIndex][$contentIndex][$type] ?? '',
            $files['tmp_name'][$columnIndex][$contentIndex][$type] ?? null,
            $files['error'][$columnIndex][$contentIndex][$type] ?? UPLOAD_ERR_NO_FILE,
            $files['size'][$columnIndex][$contentIndex][$type] ?? 0
        );
    }

    private function getStackContentFile(
        array $files,
        int $columnIndex,
        int $contentIndex,
        int $imageIndex,
        string $type
    ): ?UploadedFile {
        return $this->uploadedFileFromParts(
            $files['name'][$columnIndex][$contentIndex][$imageIndex][$type] ?? null,
            $files['type'][$columnIndex][$contentIndex][$imageIndex][$type] ?? '',
            $files['tmp_name'][$columnIndex][$contentIndex][$imageIndex][$type] ?? null,
            $files['error'][$columnIndex][$contentIndex][$imageIndex][$type] ?? UPLOAD_ERR_NO_FILE,
            $files['size'][$columnIndex][$contentIndex][$imageIndex][$type] ?? 0
        );
    }

    protected function uploadedFileFromParts(
        mixed $name,
        mixed $type,
        mixed $tmpName,
        mixed $error,
        mixed $size
    ): ?UploadedFile {
        if ($error === UPLOAD_ERR_NO_FILE || !$tmpName) {
            return null;
        }

        return new UploadedFile([
            'name' => $name,
            'type' => $type,
            'tmp_name' => $tmpName,
            'error' => $error,
            'size' => $size,
        ]);
    }

    private function invalidUploadMessage(UploadedFile $file): string
    {
        $message = trim($file->getErrorMessage());
        return $message !== '' ? $message : '업로드 파일이 유효하지 않습니다.';
    }

    private function deleteBlockImage(string $imageUrl): void
    {
        if (empty($imageUrl) || $imageUrl === '__pending__' || str_contains($imageUrl, '..')) {
            return;
        }

        if (!str_starts_with($imageUrl, '/storage/')) {
            return;
        }

        $fullPath = $this->publicPath . $imageUrl;
        $realPath = realpath($fullPath);
        if ($realPath === false) {
            return;
        }

        $storageBase = realpath($this->storagePath);
        if ($storageBase === false || !str_starts_with($realPath, $storageBase)) {
            return;
        }

        @unlink($realPath);
    }
}
