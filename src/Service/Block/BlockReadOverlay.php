<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Contract\Block\BlockReadOverlayInterface;
use Mublo\Contract\Block\BlockRowSnapshot;
use Mublo\Core\Result\Result;

/** 요청 메모리 안에서만 유지되는 블록 최종 목록 overlay. */
final class BlockReadOverlay implements BlockReadOverlayInterface
{
    /** @var array<string, list<BlockRowSnapshot>> */
    private array $positions = [];

    /** @var array<int, list<BlockRowSnapshot>> */
    private array $pages = [];

    public function __construct(private BlockColumnPayloadNormalizer $normalizer)
    {
    }

    public function replacePositionRows(
        int $domainId,
        string $position,
        ?string $menuCode,
        bool $mainScreen,
        array $rows
    ): Result {
        $normalized = $this->normalizeRows($domainId, $rows, static function (array $row) use (
            $position
        ): array {
            $row['page_id'] = null;
            $row['position'] = $position;
            return $row;
        });
        if ($normalized instanceof Result) {
            return $normalized;
        }
        $this->positions[$this->positionKey($domainId, $position, $menuCode, $mainScreen)] = $normalized;
        return Result::success('블록 위치 미리보기를 등록했습니다.');
    }

    public function replacePageRows(int $domainId, int $pageId, array $rows): Result
    {
        if ($pageId <= 0) {
            return Result::failure('미리보기 페이지 ID가 올바르지 않습니다.');
        }
        $normalized = $this->normalizeRows($domainId, $rows, static function (array $row) use ($pageId): array {
            $row['page_id'] = $pageId;
            $row['position'] = null;
            return $row;
        });
        if ($normalized instanceof Result) {
            return $normalized;
        }
        $this->pages[$pageId] = $normalized;
        return Result::success('블록 페이지 미리보기를 등록했습니다.');
    }

    public function positionRows(
        int $domainId,
        string $position,
        ?string $menuCode,
        bool $mainScreen
    ): ?array {
        return $this->positions[$this->positionKey($domainId, $position, $menuCode, $mainScreen)] ?? null;
    }

    public function pageRows(int $pageId): ?array
    {
        return $this->pages[$pageId] ?? null;
    }

    public function clear(): void
    {
        $this->positions = [];
        $this->pages = [];
    }

    /**
     * @param list<BlockRowSnapshot> $rows
     * @param callable(array<string, mixed>): array<string, mixed> $place
     * @return list<BlockRowSnapshot>|Result
     */
    private function normalizeRows(int $domainId, array $rows, callable $place): array|Result
    {
        if ($domainId <= 0) {
            return Result::failure('미리보기 도메인 ID가 올바르지 않습니다.');
        }
        $normalized = [];
        $seenIds = [];
        foreach (array_values($rows) as $index => $snapshot) {
            if (!$snapshot instanceof BlockRowSnapshot || $snapshot->domainId !== $domainId) {
                return Result::failure('다른 도메인의 블록은 미리보기에 포함할 수 없습니다.');
            }
            $rowId = $snapshot->rowId !== 0 ? $snapshot->rowId : -($index + 1);
            if (isset($seenIds[$rowId])) {
                return Result::failure('미리보기 행 ID가 중복되었습니다.');
            }
            $seenIds[$rowId] = true;
            $columns = $this->normalizer->normalizeMany(
                $snapshot->columns,
                BlockColumnWriteContext::preview($domainId)
            );
            if (!$columns->isOk()) {
                return Result::failure($columns->getFirstErrorMessage(), [
                    'errors' => $columns->getErrors(),
                    'warnings' => $columns->getWarnings(),
                ]);
            }
            $row = $place($snapshot->row);
            $row['domain_id'] = $domainId;
            $row['row_id'] = $rowId;
            $row['sort_order'] = $index;
            $normalized[] = new BlockRowSnapshot(
                $rowId,
                $domainId,
                $snapshot->revision,
                $row,
                $columns->getNormalizedColumns()
            );
        }
        return $normalized;
    }

    private function positionKey(
        int $domainId,
        string $position,
        ?string $menuCode,
        bool $mainScreen
    ): string {
        return implode(':', [
            $domainId,
            rawurlencode($position),
            rawurlencode($menuCode ?? ''),
            $mainScreen ? '1' : '0',
        ]);
    }
}
