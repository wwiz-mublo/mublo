<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Engine;

use Mublo\Core\Report\Audit\ReportAuditLogger;
use Mublo\Core\Report\Contract\PermissionGateInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Exception\ReportOutputException;
use Mublo\Core\Report\Exception\ReportPermissionDeniedException;
use Mublo\Core\Report\Exception\ReportValidationException;
use Mublo\Core\Report\Exception\UnsupportedFormatException;
use Mublo\Core\Report\Security\ReportCellValueSanitizer;
use Mublo\Core\Report\Store\ReportFileStore;
use Mublo\Core\Result\Result;

class ReportManager
{
    private const MAX_CHUNK_REFS = 1000;
    private const MAX_CURSOR_LENGTH = 10000;

    private ReportDefinitionRegistry $definitions;
    private ReportRendererResolver $resolver;
    private PermissionGateInterface $permissionGate;
    private ReportFileStore $store;
    private ReportAuditLogger $audit;

    public function __construct(
        ReportDefinitionRegistry $definitions,
        ReportRendererResolver $resolver,
        PermissionGateInterface $permissionGate,
        ReportFileStore $store,
        ReportAuditLogger $audit
    ) {
        $this->definitions = $definitions;
        $this->resolver = $resolver;
        $this->permissionGate = $permissionGate;
        $this->store = $store;
        $this->audit = $audit;
    }

    public function generateDownload(
        string $reportName,
        array $filters,
        string $format,
        int $domainId,
        string $menuCode
    ): Result {
        try {
            $this->permissionGate->assertDownloadAllowed($domainId, $menuCode);
        } catch (ReportPermissionDeniedException $e) {
            return Result::failure($e->getMessage());
        }

        try {
            $filters['domain_id'] = $domainId;
            $definition = $this->definitions->get($reportName);
            $document = $definition->build($filters);
            $renderer = $this->resolver->resolve($format);
        } catch (UnsupportedFormatException $e) {
            return Result::failure($e->getMessage());
        } catch (\RuntimeException $e) {
            return Result::failure($e->getMessage());
        }

        $filePath = null;
        try {
            $filePath = $this->store->createExportPath($renderer->extension());
            $renderer->renderToFile($document, $filePath);
        } catch (\Throwable $e) {
            if (is_string($filePath)) {
                $this->store->discardExport($filePath);
            }
            return Result::failure('리포트 생성에 실패했습니다: ' . $e->getMessage());
        }

        $fileName = (string) $document->metadata()->get('filename', $reportName . '_' . date('Ymd_His'));
        $fileName = $this->sanitizeFilename($fileName) . '.' . $renderer->extension();

        $this->audit->log('report.generated', [
            'report_name' => $reportName,
            'format' => $format,
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'action' => 'download',
        ]);

        return Result::success('리포트가 생성되었습니다.', [
            'filePath' => $filePath,
            'fileName' => $fileName,
            'mimeType' => $renderer->mimeType(),
        ]);
    }

    public function generateChunk(
        string $reportName,
        array $filters,
        ?string $cursor,
        int $limit,
        int $domainId,
        string $menuCode
    ): Result {
        try {
            $this->permissionGate->assertDownloadAllowed($domainId, $menuCode);
        } catch (ReportPermissionDeniedException $e) {
            return Result::failure($e->getMessage());
        }

        if ($limit < 1 || $limit > 5000) {
            return Result::failure('limit은 1~5000 범위여야 합니다.');
        }

        if ($cursor !== null && strlen($cursor) > self::MAX_CURSOR_LENGTH) {
            return Result::failure('잘못된 커서입니다.');
        }

        try {
            $state = $this->decodeCursor($cursor);
        } catch (ReportValidationException $e) {
            return Result::failure($e->getMessage());
        }

        $offset = max(0, (int) ($state['offset'] ?? 0));

        try {
            $filters['domain_id'] = $domainId;
            $definition = $this->definitions->get($reportName);
            $document = $definition->build($filters);
            $table = $this->firstTableSection($document);

            $rows = $table->rowProvider()->getChunk($offset, $limit);
            $count = count($rows);
            $total = $table->rowProvider()->totalCount();
        } catch (\RuntimeException $e) {
            return Result::failure($e->getMessage());
        }

        if ($total !== null) {
            $hasMore = ($offset + $count) < $total;
        } else {
            $hasMore = $count === $limit;
        }

        $nextOffset = $offset + $count;
        $nextCursor = $hasMore ? $this->encodeCursor(['offset' => $nextOffset]) : null;

        try {
            $chunkRef = $this->store->saveChunk($rows, $domainId);
        } catch (ReportOutputException $e) {
            return Result::failure($e->getMessage());
        }

        $this->audit->log('report.chunk.generated', [
            'report_name' => $reportName,
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'offset' => $offset,
            'limit' => $limit,
            'rows' => $count,
        ]);

        return Result::success('청크가 생성되었습니다.', [
            'columns' => $this->serializeColumns($table->columns()),
            'rows' => $rows,
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
            'chunkIndex' => (int) floor($offset / $limit) + 1,
            'limit' => $limit,
            'chunkRef' => $chunkRef,
        ]);
    }

    /**
     * @param array<int,string> $chunkRefs
     */
    public function mergeChunks(
        string $reportName,
        array $filters,
        array $chunkRefs,
        string $format,
        string $filename,
        int $domainId,
        string $menuCode
    ): Result {
        try {
            $this->permissionGate->assertDownloadAllowed($domainId, $menuCode);
        } catch (ReportPermissionDeniedException $e) {
            return Result::failure($e->getMessage());
        }

        if (strtolower($format) !== 'csv') {
            return Result::failure('merge는 현재 csv만 지원합니다.');
        }

        if (empty($chunkRefs)) {
            return Result::failure('chunkRefs가 비어 있습니다.');
        }

        if (count($chunkRefs) > self::MAX_CHUNK_REFS) {
            return Result::failure('chunkRefs는 최대 ' . self::MAX_CHUNK_REFS . '개까지 허용됩니다.');
        }

        try {
            $filters['domain_id'] = $domainId;
            $definition = $this->definitions->get($reportName);
            $document = $definition->build($filters);
            $table = $this->firstTableSection($document);
            $columns = $table->columns();
        } catch (\RuntimeException $e) {
            return Result::failure($e->getMessage());
        }

        $filePath = $this->store->createExportPath('csv');
        $fp = fopen($filePath, 'wb');
        if ($fp === false) {
            return Result::failure('병합 파일 생성에 실패했습니다.');
        }

        try {
            fwrite($fp, "\xEF\xBB\xBF");
            fputcsv(
                $fp,
                ReportCellValueSanitizer::csvRow(array_map(static fn($col) => $col->label, $columns)),
                ',',
                '"',
                '\\'
            );

            foreach ($chunkRefs as $ref) {
                $rows = $this->store->loadChunk((string) $ref, $domainId);
                foreach ($rows as $row) {
                    $line = [];
                    foreach ($columns as $column) {
                        $value = $row[$column->key] ?? null;
                        if (is_callable($column->formatter)) {
                            $value = ($column->formatter)($value, $row);
                        }
                        $line[] = $value;
                    }
                    fputcsv($fp, ReportCellValueSanitizer::csvRow($line), ',', '"', '\\');
                }
            }
        } catch (\Throwable $e) {
            fclose($fp);
            $this->store->discardExport($filePath);
            return Result::failure('병합 처리 중 오류: ' . $e->getMessage());
        }

        fclose($fp);

        $safeName = $this->sanitizeFilename($filename);
        if ($safeName === '') {
            $safeName = $reportName . '_' . date('Ymd_His');
        }
        $safeName .= '.csv';

        try {
            $fileResult = $this->store->registerMergedFile(
                $filePath,
                $safeName,
                $domainId,
                $menuCode,
                3600
            );
        } catch (ReportOutputException $e) {
            $this->store->discardExport($filePath);
            return Result::failure($e->getMessage());
        }

        $this->audit->log('report.merge.generated', [
            'report_name' => $reportName,
            'domain_id' => $domainId,
            'menu_code' => $menuCode,
            'chunk_count' => count($chunkRefs),
            'file_id' => $fileResult['fileId'],
        ]);

        return Result::success('병합이 완료되었습니다.', $fileResult);
    }

    public function resolveMergedFile(string $fileId, int $domainId): Result
    {
        $resolved = $this->store->resolveMergedFile($fileId);
        if ($resolved === null) {
            return Result::failure('파일이 없거나 만료되었습니다.');
        }

        if (($resolved['domain_id'] ?? 0) !== $domainId) {
            return Result::failure('파일이 없거나 만료되었습니다.');
        }

        $menuCode = (string) ($resolved['menu_code'] ?? '');
        try {
            $this->permissionGate->assertDownloadAllowed($domainId, $menuCode);
        } catch (ReportPermissionDeniedException $e) {
            return Result::failure($e->getMessage());
        }

        return Result::success('', [
            'file_path' => $resolved['file_path'],
            'filename' => $resolved['filename'],
        ]);
    }

    private function firstTableSection($document): TableSection
    {
        foreach ($document->sections() as $section) {
            if ($section instanceof TableSection) {
                return $section;
            }
        }

        throw new ReportValidationException('TableSection이 없는 리포트는 처리할 수 없습니다.');
    }

    /**
     * @param array<int,ColumnDefinition> $columns
     * @return array<int,array<string,mixed>>
     */
    private function serializeColumns(array $columns): array
    {
        $result = [];
        foreach ($columns as $column) {
            $result[] = [
                'key' => $column->key,
                'label' => $column->label,
                'type' => $column->type,
                'align' => $column->align,
            ];
        }
        return $result;
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = trim($filename);
        $filename = str_replace("\0", '', $filename);
        $filename = preg_replace('/[\/\\\\:\*\?\<\>\|"\']/', '_', $filename) ?? $filename;
        $filename = preg_replace('/\.\.+/', '', $filename);
        return trim($filename);
    }

    private function encodeCursor(array $state): string
    {
        return rtrim(strtr(base64_encode(json_encode($state, JSON_UNESCAPED_UNICODE) ?: '{}'), '+/', '-_'), '=');
    }

    private function decodeCursor(?string $cursor): array
    {
        if ($cursor === null || $cursor === '') {
            return ['offset' => 0];
        }

        $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
        if ($raw === false) {
            throw new ReportValidationException('잘못된 커서입니다.');
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new ReportValidationException('잘못된 커서입니다.');
        }

        return $decoded;
    }
}
