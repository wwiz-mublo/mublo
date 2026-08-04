<?php
declare(strict_types=1);

namespace Mublo\Contract\CustomField;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\UploadResult;

interface CustomFieldFileManagerInterface
{
    public function uploadTemp(UploadedFile $file, int $domainId, array|string $fieldConfig): UploadResult;

    public function buildTempResponse(UploadResult $result): array;

    public function processFileValue(
        mixed $value,
        int $domainId,
        string $category,
        string $entityId,
        bool $public = false
    ): Result;

    public function deleteFileByMeta(?string $fieldValue): Result;

    public function parseFileMeta(?string $fieldValue): ?array;

    public function parseFileMetaWithUrl(?string $fieldValue): ?array;
}
