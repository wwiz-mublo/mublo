<?php
declare(strict_types=1);

namespace Mublo\Controller\Admin;

use Mublo\Core\Context\Context;
use Mublo\Core\Report\Engine\ReportManager;
use Mublo\Core\Response\FileResponse;
use Mublo\Core\Response\JsonResponse;

class ReportController
{
    private ReportManager $reportManager;

    public function __construct(ReportManager $reportManager)
    {
        $this->reportManager = $reportManager;
    }

    public function download(array $params, Context $context): FileResponse|JsonResponse
    {
        $request = $context->getRequest();
        $input = $request->json() ?? $request->all();
        $reportName = (string) ($params['reportName'] ?? '');
        $domainId = $context->getDomainId();

        $menuCode = (string) ($input['menuCode'] ?? '');
        $format = (string) ($input['format'] ?? 'csv');
        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];

        $result = $this->reportManager->generateDownload(
            $reportName,
            $filters,
            $format,
            $domainId,
            $menuCode
        );

        if ($result->isFailure()) {
            return JsonResponse::error($result->getMessage());
        }

        return new FileResponse(
            $result->get('filePath'),
            200,
            [
                'Content-Type' => $result->get('mimeType'),
                'Content-Disposition' => 'attachment; filename="' . $result->get('fileName') . '"',
            ],
            deleteAfterSend: true
        );
    }

    public function chunks(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $input = $request->json() ?? $request->all();
        $reportName = (string) ($params['reportName'] ?? '');
        $domainId = $context->getDomainId();

        $menuCode = (string) ($input['menuCode'] ?? '');
        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $cursor = isset($input['cursor']) ? (string) $input['cursor'] : null;
        $limit = (int) ($input['limit'] ?? 1000);

        $result = $this->reportManager->generateChunk(
            $reportName,
            $filters,
            $cursor,
            $limit,
            $domainId,
            $menuCode
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function merge(array $params, Context $context): JsonResponse
    {
        $request = $context->getRequest();
        $input = $request->json() ?? $request->all();
        $reportName = (string) ($params['reportName'] ?? '');
        $domainId = $context->getDomainId();

        $menuCode = (string) ($input['menuCode'] ?? '');
        $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];
        $chunkRefs = is_array($input['chunkRefs'] ?? null) ? $input['chunkRefs'] : [];
        $format = (string) ($input['format'] ?? 'csv');
        $filename = (string) ($input['filename'] ?? ($reportName . '_' . date('Ymd_His')));

        $result = $this->reportManager->mergeChunks(
            $reportName,
            $filters,
            $chunkRefs,
            $format,
            $filename,
            $domainId,
            $menuCode
        );

        return $result->isSuccess()
            ? JsonResponse::success($result->getData(), $result->getMessage())
            : JsonResponse::error($result->getMessage());
    }

    public function file(array $params, Context $context): FileResponse|JsonResponse
    {
        $fileId = (string) ($params['fileId'] ?? '');
        if ($fileId === '') {
            return JsonResponse::notFound('파일을 찾을 수 없습니다.');
        }

        $result = $this->reportManager->resolveMergedFile(
            $fileId,
            $context->getDomainId()
        );

        if ($result->isFailure()) {
            return JsonResponse::notFound($result->getMessage());
        }

        return new FileResponse(
            $result->get('file_path'),
            200,
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $result->get('filename') . '"',
            ]
        );
    }
}
