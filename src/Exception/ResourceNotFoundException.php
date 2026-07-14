<?php
namespace Mublo\Exception;

/**
 * ResourceNotFoundException
 *
 * 리소스를 찾을 수 없음
 */
class ResourceNotFoundException extends ApplicationException
{
    public function __construct(
        string $resourceType,
        mixed $resourceId,
        array $context = []
    ) {
        $message = "{$resourceType} (ID: {$resourceId}) not found";
        parent::__construct(
            $message,
            'ERR_NOT_FOUND',
            404,
            array_merge(['resource_type' => $resourceType, 'resource_id' => $resourceId], $context)
        );
    }
}
