<?php
declare(strict_types=1);

namespace Mublo\Service\Block;


final class BlockColumnPayloadNormalizer
{
    private const MAX_COLUMNS = 4;

    /** 스택 칸의 하위 콘텐츠 개수 상한 (계획 6.3) */
    public const MAX_CONTENTS = 20;

    /** 스택 콘텐츠 간격(px) 상한 (계획 4.1) */
    private const MAX_CONTENT_GAP = 200;

    private const ALLOWED_FIELDS = [
        'column_id', 'column_index', 'sort_order', 'width', 'pc_padding', 'mobile_padding',
        'background_config', 'border_config', 'title_config',
        'content_type', 'content_kind', 'content_skin', 'content_config', 'content_items',
        'content_mode', 'pc_content_gap', 'mobile_content_gap', 'contents',
        'is_active',
    ];

    /** 칸 레이아웃 소유 JSON 필드 — 콘텐츠 JSON 은 BlockContentPayloadNormalizer 소관 */
    private const JSON_FIELDS = [
        'background_config', 'border_config', 'title_config', 'content_config', 'content_items',
    ];

    /** 콘텐츠 단위 정규화기로 위임되는 레거시 콘텐츠 필드 */
    private const CONTENT_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin', 'content_config', 'content_items',
    ];

    /**
     * 값이 그대로 인라인 CSS(style 속성/스타일 블록)로 조립되는 config 필드.
     * 정화기(HTMLPurifier)는 이 필드 값을 검사하지 않으므로 여기서 값 단위로 방어한다.
     */
    private const STYLE_CONFIG_FIELDS = ['background_config', 'border_config'];

    /** 칸 레이아웃 문자열 한계 — 콘텐츠 문자열(type/kind/skin)은 콘텐츠 정규화기 소관 */
    private const STRING_LIMITS = [
        'width' => 50,
        'pc_padding' => 100,
        'mobile_padding' => 100,
    ];

    private BlockContentPayloadNormalizer $contentNormalizer;

    public function __construct(
        private BlockContentSanitizer $contentSanitizer,
        private BlockSkinService $skinService
    ) {
        $this->contentNormalizer = new BlockContentPayloadNormalizer($contentSanitizer, $skinService);
    }

    public function normalize(array $payload, BlockColumnWriteContext $context): BlockColumnNormalizationResult
    {
        return $this->normalizeMany([$payload], $context);
    }

    public function normalizeMany(array $payloads, BlockColumnWriteContext $context): BlockColumnNormalizationResult
    {
        $normalizedColumns = [];
        $errors = [];
        $warnings = [];

        if (count($payloads) > self::MAX_COLUMNS) {
            $errors[] = $this->diagnostic(
                self::MAX_COLUMNS,
                'columns',
                'too_many_columns',
                '한 행에 최대 4개의 칸만 저장할 수 있습니다.'
            );
        }

        foreach (array_values($payloads) as $columnIndex => $payload) {
            if (!is_array($payload)) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    'column',
                    'invalid_column',
                    "{$columnIndex}번 칸 데이터가 객체가 아닙니다."
                );
                continue;
            }

            [$column, $columnErrors, $columnWarnings] = $this->normalizeColumn(
                $payload,
                $columnIndex,
                $context
            );

            $normalizedColumns[] = $column;
            array_push($errors, ...$columnErrors);
            array_push($warnings, ...$columnWarnings);
        }

        // 같은 payload 안의 중복 column_id 는 저장 전체 거부 대상 (계획 6.3)
        $seenColumnIds = [];
        foreach ($normalizedColumns as $index => $column) {
            $columnId = (int) ($column['column_id'] ?? 0);
            if ($columnId <= 0) {
                continue;
            }
            if (isset($seenColumnIds[$columnId])) {
                $errors[] = $this->diagnostic(
                    $index,
                    'column_id',
                    'duplicate_column_id',
                    "column_id {$columnId} 가 두 번 제출되었습니다."
                );
            } else {
                $seenColumnIds[$columnId] = true;
            }
        }

        return new BlockColumnNormalizationResult($normalizedColumns, $errors, $warnings);
    }

    /**
     * @return array{array<string, mixed>, array<int, array<string, mixed>>, array<int, array<string, mixed>>}
     */
    private function normalizeColumn(
        array $payload,
        int $columnIndex,
        BlockColumnWriteContext $context
    ): array {
        $column = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        $errors = [];
        $warnings = [];

        // 기존 칸 식별자 passthrough — row·domain 소유권 검증은 저장 계층 몫 (계획 6.3)
        if (array_key_exists('column_id', $column)) {
            $column['column_id'] = max(0, (int) $column['column_id']);
        }

        // content_mode — 알 수 없는 값은 저장 시 거부 (계획 4.1)
        $mode = 'single';
        if (array_key_exists('content_mode', $column)) {
            $submittedMode = (string) $column['content_mode'];
            if (!in_array($submittedMode, ['single', 'stack'], true)) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    'content_mode',
                    'invalid_content_mode',
                    "{$columnIndex}번 칸의 content_mode 값이 올바르지 않습니다: {$submittedMode}"
                );
                unset($column['content_mode']);
            } else {
                $mode = $submittedMode;
            }
        }

        // 스택 콘텐츠 간격 — px 정수, 0~200 클램프 (계획 4.1)
        foreach (['pc_content_gap', 'mobile_content_gap'] as $field) {
            if (!array_key_exists($field, $column)) {
                continue;
            }
            $gap = (int) $column[$field];
            $clamped = max(0, min(self::MAX_CONTENT_GAP, $gap));
            if ($clamped !== $gap) {
                $warnings[] = $this->diagnostic(
                    $columnIndex,
                    $field,
                    'gap_clamped',
                    "{$columnIndex}번 칸의 {$field} 값을 0~" . self::MAX_CONTENT_GAP . " 범위로 조정했습니다."
                );
            }
            $column[$field] = $clamped;
        }

        // 레이아웃 JSON 필드 — 콘텐츠 JSON(title/config/items)은 콘텐츠 정규화기가 처리
        foreach (self::STYLE_CONFIG_FIELDS as $field) {
            if (!array_key_exists($field, $column)) {
                continue;
            }

            $value = $column[$field];
            if ($value === null || $value === '') {
                $column[$field] = null;
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $column[$field] = $decoded;
                    continue;
                }
            }

            $column[$field] = null;
            $errors[] = $this->diagnostic(
                $columnIndex,
                $field,
                'invalid_json',
                "{$columnIndex}번 칸의 {$field} JSON 형식이 올바르지 않습니다."
            );
        }

        // 배경/테두리 config 값 심층 방어: 인라인 CSS 로 조립되기 전에
        // 선언 탈출·마크업 탈출·CSS 실행/외부참조 토큰을 거부한다.
        // (출력단 htmlspecialchars 이스케이프에 더한 2차 방어. 정상 색상/그라데이션/
        //  길이/경로 값에는 아래 문자가 존재하지 않는다.)
        foreach (self::STYLE_CONFIG_FIELDS as $field) {
            if (empty($column[$field]) || !is_array($column[$field])) {
                continue;
            }

            if ($this->hasUnsafeStyleValue($column[$field])) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $field,
                    'unsafe_style_value',
                    "{$columnIndex}번 칸의 {$field} 값에 허용되지 않는 문자가 있습니다."
                );
                $column[$field] = null;
            }
        }

        foreach (self::STRING_LIMITS as $field => $maxLength) {
            if (!array_key_exists($field, $column)) {
                continue;
            }

            if ($column[$field] !== null && !is_scalar($column[$field])) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $field,
                    'invalid_string',
                    "{$columnIndex}번 칸의 {$field} 값이 문자열이 아닙니다."
                );
                $column[$field] = null;
                continue;
            }

            $value = trim((string) ($column[$field] ?? ''));
            $column[$field] = $value === '' ? null : $value;

            if ($column[$field] !== null && mb_strlen($column[$field]) > $maxLength) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $field,
                    'string_too_long',
                    "{$columnIndex}번 칸의 {$field} 필드는 {$maxLength}자를 초과할 수 없습니다."
                );
            }
        }

        foreach (['column_index', 'sort_order'] as $field) {
            if (array_key_exists($field, $column)) {
                $column[$field] = (int) $column[$field];
            }
        }

        if (array_key_exists('is_active', $column)) {
            // 빈 값은 '미지정'이다. normalizeBool 에 그대로 넣으면 0(비활성)으로 굳어져
            // 칸이 렌더에서 통째로 빠지므로, 다른 계층 기본값(뷰 ?? 1, 엔티티 ?? true)과
            // 동일하게 활성으로 맞춘다. 명시적 '0' 은 그대로 비활성이다.
            $isActive = $column['is_active'];
            $column['is_active'] = ($isActive === null || $isActive === '')
                ? 1
                : $this->normalizeBool($isActive);
        }

        foreach (['width', 'pc_padding', 'mobile_padding'] as $field) {
            if (!empty($column[$field]) && !$this->isValidCssValue($column[$field])) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $field,
                    'invalid_css_value',
                    "{$columnIndex}번 칸의 {$field} 필드 형식이 올바르지 않습니다."
                );
            }
        }

        if ($mode === 'stack') {
            [$column, $stackErrors, $stackWarnings] = $this->normalizeStack($column, $columnIndex, $context);
            array_push($errors, ...$stackErrors);
            array_push($warnings, ...$stackWarnings);

            return [$column, $errors, $warnings];
        }

        // single/레거시 — 콘텐츠 필드를 콘텐츠 단위 정규화기에 위임
        if (array_key_exists('contents', $column)) {
            $errors[] = $this->diagnostic(
                $columnIndex,
                'contents',
                'contents_without_stack_mode',
                "{$columnIndex}번 칸은 content_mode=stack 이 아니므로 contents 를 받을 수 없습니다."
            );
            unset($column['contents']);
        }

        $contentSubset = array_intersect_key($column, array_flip(self::CONTENT_FIELDS));
        [$normalizedContent, $contentErrors, $contentWarnings] = $this->contentNormalizer->normalizeContent(
            $contentSubset,
            $columnIndex,
            null,
            "{$columnIndex}번 칸",
            '',
            $context
        );
        // 칸의 is_active·column_id 는 칸 소유 — 콘텐츠 정규화 결과가 덮지 않는다
        unset($normalizedContent['content_id'], $normalizedContent['is_active']);
        $column = array_merge($column, $normalizedContent);
        array_push($errors, ...$contentErrors);
        array_push($warnings, ...$contentWarnings);

        return [$column, $errors, $warnings];
    }

    /**
     * 스택 칸 정규화 — contents[] 각각을 콘텐츠 단위 정규화기로 검증한다.
     *
     * 스택 칸의 레거시 콘텐츠 필드는 제출값을 쓰지 않는다: 미러는 저장
     * 서비스가 정규화 완료된 첫 활성 콘텐츠에서 생성한다 (계획 5.4 —
     * normalizer 재실행 금지 규칙과도 일치).
     *
     * @return array{array<string, mixed>, array<int, array<string, mixed>>, array<int, array<string, mixed>>}
     */
    private function normalizeStack(array $column, int $columnIndex, BlockColumnWriteContext $context): array
    {
        $errors = [];
        $warnings = [];

        foreach (self::CONTENT_FIELDS as $field) {
            unset($column[$field]);
        }

        $contentsPayload = $column['contents'] ?? null;
        unset($column['contents']);

        if (!is_array($contentsPayload) || !array_is_list($contentsPayload) || $contentsPayload === []) {
            // stack 은 하위 콘텐츠 1개 이상이 불변 조건 (계획 6.2.3)
            $errors[] = $this->diagnostic(
                $columnIndex,
                'contents',
                'stack_requires_contents',
                "{$columnIndex}번 칸의 stack 모드는 1개 이상의 콘텐츠가 필요합니다."
            );

            return [$column, $errors, $warnings];
        }

        if (count($contentsPayload) > self::MAX_CONTENTS) {
            $errors[] = $this->diagnostic(
                $columnIndex,
                'contents',
                'too_many_contents',
                "{$columnIndex}번 칸에는 콘텐츠를 최대 " . self::MAX_CONTENTS . "개까지만 배치할 수 있습니다."
            );

            return [$column, $errors, $warnings];
        }

        $normalizedContents = [];
        $seenContentIds = [];

        foreach (array_values($contentsPayload) as $contentIndex => $contentPayload) {
            if (!is_array($contentPayload)) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    "contents.{$contentIndex}",
                    'invalid_content',
                    "{$columnIndex}번 칸 {$contentIndex}번 콘텐츠 데이터가 객체가 아닙니다."
                );
                continue;
            }

            [$content, $contentErrors, $contentWarnings] = $this->contentNormalizer->normalizeContent(
                $contentPayload,
                $columnIndex,
                $contentIndex,
                "{$columnIndex}번 칸 {$contentIndex}번 콘텐츠",
                "contents.{$contentIndex}.",
                $context
            );

            // 같은 payload 안의 중복 content_id 는 저장 전체 거부 대상 (계획 6.3)
            $contentId = (int) ($content['content_id'] ?? 0);
            if ($contentId > 0) {
                if (isset($seenContentIds[$contentId])) {
                    $errors[] = $this->diagnostic(
                        $columnIndex,
                        "contents.{$contentIndex}.content_id",
                        'duplicate_content_id',
                        "{$columnIndex}번 칸에 content_id {$contentId} 가 두 번 제출되었습니다."
                    );
                } else {
                    $seenContentIds[$contentId] = true;
                }
            }

            $normalizedContents[] = $content;
            array_push($errors, ...$contentErrors);
            array_push($warnings, ...$contentWarnings);
        }

        $column['contents'] = $normalizedContents;

        return [$column, $errors, $warnings];
    }

    private function normalizeBool(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'y', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * background_config / border_config 의 문자열 값에 인라인 CSS 조립 시
     * 위험한 토큰이 있는지 재귀 검사한다.
     *
     * 거부 대상:
     * - 따옴표/꺾쇠/역슬래시/백틱: 속성·url()·마크업 탈출 수단
     * - 세미콜론: 새 CSS 선언 주입
     * - javascript: / expression( / @import: CSS 실행·외부 리소스 로드
     *
     * 정상 값(#rgb/#rrggbb, rgb()/rgba()/hsl(), linear-gradient(...),
     * 10px/33.33%/center center, /storage/... 경로)에는 위 토큰이 없다.
     */
    private function hasUnsafeStyleValue(array $config): bool
    {
        foreach ($config as $value) {
            if (is_array($value)) {
                if ($this->hasUnsafeStyleValue($value)) {
                    return true;
                }
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $s = (string) $value;

            if (preg_match('/["\'<>\\\\;`]/', $s) === 1) {
                return true;
            }

            if (preg_match('/javascript:|expression\s*\(|@import/i', $s) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isValidCssValue(string $value): bool
    {
        $value = trim($value);
        if ($value === '' || in_array(strtolower($value), ['auto', 'inherit', 'initial'], true)) {
            return true;
        }

        return preg_match(
            '/^\d+(?:\.\d+)?(?:px|%|em|rem|vh|vw)?(?:\s+\d+(?:\.\d+)?(?:px|%|em|rem|vh|vw)?)*$/i',
            $value
        ) === 1;
    }

    /** @return array{column_index: int, field: string, code: string, message: string} */
    private function diagnostic(int $columnIndex, string $field, string $code, string $message): array
    {
        return [
            'column_index' => $columnIndex,
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];
    }
}
