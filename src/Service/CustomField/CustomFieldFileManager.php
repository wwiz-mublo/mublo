<?php
declare(strict_types=1);

namespace Mublo\Service\CustomField;

use Mublo\Contract\CustomField\CustomFieldFileManagerInterface;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\UploadResult;

final class CustomFieldFileManager implements CustomFieldFileManagerInterface
{
    public function __construct(private CustomFieldFileHandler $handler)
    {
    }

    public function uploadTemp(UploadedFile $file, int $domainId, array|string $fieldConfig): UploadResult
    {
        return $this->handler->uploadTemp($file, $domainId, $fieldConfig);
    }

    public function buildTempResponse(UploadResult $result): array
    {
        return $this->handler->buildTempResponse($result);
    }

    public function processFileValue(
        mixed $value,
        int $domainId,
        string $category,
        string $entityId,
        bool $public = false
    ): Result {
        return $this->handler->processFileValue($value, $domainId, $category, $entityId, $public);
    }

    public function deleteFileByMeta(?string $fieldValue): Result
    {
        return $this->handler->deleteFileByMeta($fieldValue);
    }

    public function parseFileMeta(?string $fieldValue): ?array
    {
        return CustomFieldFileHandler::parseFileMeta($fieldValue);
    }

    public function parseFileMetaWithUrl(?string $fieldValue): ?array
    {
        return $this->handler->parseFileMetaWithUrl($fieldValue);
    }
}
