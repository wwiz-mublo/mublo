<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

/**
 * BlockRow HTTP 입력을 서비스 계층의 행/칸 payload로 변환한다.
 */
class BlockRowPayloadMapper
{
    public function __construct(private BlockImageProcessor $imageProcessor)
    {
    }

    public function mapBackground(
        array $formData,
        int $domainId,
        ?array $backgroundFile,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = []
    ): array {
        try {
            return $this->imageProcessor->processBackgroundConfig(
                $formData,
                $domainId,
                $backgroundFile,
                $mutation,
                $uploadErrors
            );
        } catch (\Throwable $e) {
            $this->imageProcessor->rollback($mutation);
            throw $e;
        }
    }

    public function mapColumns(
        array $rawColumns,
        int $domainId,
        ?array $columnImageFiles,
        ?array $titleImageFiles,
        BlockImageMutationPlan $mutation,
        array &$uploadErrors = [],
        ?array $contentImageFiles = null,
        ?array $contentTitleImageFiles = null
    ): array {
        $columns = [];

        try {
            foreach ($rawColumns as $index => $columnData) {
                $column = [
                    'column_index' => (int) $index,
                    'width' => $columnData['width'] ?? null,
                    'pc_padding' => $columnData['pc_padding'] ?? null,
                    'mobile_padding' => $columnData['mobile_padding'] ?? null,
                    'content_type' => $columnData['content_type'] ?? null,
                    'content_kind' => $columnData['content_kind'] ?? 'CORE',
                    'content_skin' => $columnData['content_skin'] ?? null,
                    'is_active' => (int) ($columnData['is_active'] ?? 1),
                ];

                // 콘텐츠 스택 필드 passthrough — 검증·소유권은 normalizer/저장 계층 몫 (계획 6.3)
                if (array_key_exists('column_id', $columnData)) {
                    $column['column_id'] = (int) $columnData['column_id'];
                }
                if (array_key_exists('content_mode', $columnData) && $columnData['content_mode'] !== '') {
                    $column['content_mode'] = (string) $columnData['content_mode'];
                }
                foreach (['pc_content_gap', 'mobile_content_gap'] as $gapField) {
                    if (array_key_exists($gapField, $columnData) && $columnData[$gapField] !== '') {
                        $column[$gapField] = (int) $columnData[$gapField];
                    }
                }
                if (isset($columnData['contents']) && is_array($columnData['contents'])) {
                    $column['contents'] = array_values($columnData['contents']);
                }

                foreach (['background_config', 'border_config', 'title_config', 'content_config', 'content_items'] as $field) {
                    if (array_key_exists($field, $columnData) && $columnData[$field] !== '') {
                        $column[$field] = $columnData[$field];
                    }
                }

                $isStack = ($column['content_mode'] ?? '') === 'stack';

                // 스택 칸의 scalar 콘텐츠 필드는 미러(서버 생성)라 이미지 처리 대상이
                // 아니다 — 실데이터는 contents[j] 이므로 콘텐츠 단위로 처리한다.
                if (!$isStack && ($column['content_type'] ?? '') === 'image') {
                    $contentItems = $this->decodeJsonArray($column['content_items'] ?? []);
                    if ($contentItems !== null) {
                        $column['content_items'] = $this->imageProcessor->processColumnImages(
                            (int) $index,
                            $contentItems,
                            $domainId,
                            $columnImageFiles,
                            $mutation,
                            $uploadErrors
                        );
                    }
                }

                if ($isStack && isset($column['contents']) && is_array($column['contents'])) {
                    foreach ($column['contents'] as $contentIndex => $contentData) {
                        if (!is_array($contentData)) {
                            continue;
                        }

                        if (($contentData['content_type'] ?? '') === 'image') {
                            $items = $this->decodeJsonArray($contentData['content_items'] ?? []);
                            if ($items !== null) {
                                $column['contents'][$contentIndex]['content_items'] = $this->imageProcessor->processStackContentImages(
                                    (int) $index,
                                    (int) $contentIndex,
                                    $items,
                                    $domainId,
                                    $contentImageFiles,
                                    $mutation,
                                    $uploadErrors
                                );
                            }
                        }

                        if (!empty($contentData['title_config'])) {
                            $titleConfig = $this->decodeJsonArray($contentData['title_config']);
                            if ($titleConfig !== null) {
                                $column['contents'][$contentIndex]['title_config'] = $this->imageProcessor->processStackContentTitleImages(
                                    (int) $index,
                                    (int) $contentIndex,
                                    $titleConfig,
                                    $domainId,
                                    $contentTitleImageFiles,
                                    $mutation,
                                    $uploadErrors
                                );
                            }
                        }
                    }
                }

                // 스택 칸의 scalar title_config 도 미러 — 콘텐츠 단위에서 처리한다
                if (!$isStack && !empty($column['title_config'])) {
                    $titleConfig = $this->decodeJsonArray($column['title_config']);
                    if ($titleConfig !== null) {
                        $column['title_config'] = $this->imageProcessor->processTitleImages(
                            (int) $index,
                            $titleConfig,
                            $domainId,
                            $titleImageFiles,
                            $mutation,
                            $uploadErrors
                        );
                    }
                }

                $columns[] = $column;
            }
        } catch (\Throwable $e) {
            $this->imageProcessor->rollback($mutation);
            throw $e;
        }

        return $columns;
    }

    public function commitImages(BlockImageMutationPlan $mutation): void
    {
        $this->imageProcessor->commit($mutation);
    }

    public function rollbackImages(BlockImageMutationPlan $mutation): void
    {
        $this->imageProcessor->rollback($mutation);
    }

    private function decodeJsonArray(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : null;
    }
}
