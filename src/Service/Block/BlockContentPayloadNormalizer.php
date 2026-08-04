<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use Mublo\Core\Block\BlockRegistry;
use Mublo\Enum\Block\BlockContentKind;
use Mublo\Enum\Block\BlockContentType;

/**
 * 콘텐츠 단위 정규화기 (계획 6.4).
 *
 * BlockColumnPayloadNormalizer 의 단일 콘텐츠 검증을 재사용 가능한 단위로
 * 추출한 것이다. single 칸의 레거시 콘텐츠 필드와 stack 칸의 하위 콘텐츠
 * (contents[j])가 같은 규칙으로 검증된다:
 *
 * - JSON 파싱 (title_config / content_config / content_items)
 * - 타입 형식·등록 여부 (미설치 확장은 WriteContext.allowUnresolvedExtension)
 * - kind 일치, 스킨 실재, maxItems 절단
 * - Include 실행 권한, HTML 정화·raw JS 권한
 * - is_active 정규화, content_id passthrough (소유권 검증은 저장 계층)
 *
 * 진단 메시지의 주어($subject)와 field 접두사($fieldPrefix)를 호출자가
 * 정하므로, 레거시 경로("0번 칸의 ...")와 스택 경로("0번 칸 2번 콘텐츠의
 * ...") 모두 위치를 정확히 지목한다 (계획 8.4).
 */
final class BlockContentPayloadNormalizer
{
    public const ALLOWED_FIELDS = [
        'title_config', 'content_type', 'content_kind', 'content_skin',
        'content_config', 'content_items', 'is_active',
    ];

    private const JSON_FIELDS = ['title_config', 'content_config', 'content_items'];

    private const STRING_LIMITS = [
        'content_type' => 50,
        'content_kind' => 20,
        'content_skin' => 50,
    ];

    public function __construct(
        private BlockContentSanitizer $contentSanitizer,
        private BlockSkinService $skinService
    ) {
    }

    /**
     * 콘텐츠 한 개 정규화.
     *
     * @param array<string, mixed> $payload 콘텐츠 필드 payload
     * @param int $columnIndex 진단용 칸 위치
     * @param int|null $contentIndex 진단용 콘텐츠 위치 (레거시 단일 경로는 null)
     * @param string $subject 진단 메시지 주어 (예: "0번 칸", "0번 칸 2번 콘텐츠")
     * @param string $fieldPrefix 진단 field 접두사 ('' 또는 'contents.2.')
     * @return array{array<string, mixed>, array<int, array<string, mixed>>, array<int, array<string, mixed>>}
     *         [정규화된 콘텐츠, 오류, 경고]
     */
    public function normalizeContent(
        array $payload,
        int $columnIndex,
        ?int $contentIndex,
        string $subject,
        string $fieldPrefix,
        BlockColumnWriteContext $context
    ): array {
        $content = array_intersect_key($payload, array_flip(self::ALLOWED_FIELDS));
        $errors = [];
        $warnings = [];

        // 기존 콘텐츠 식별자 passthrough — 소유권 검증은 저장 계층(syncForColumn) 몫
        if (array_key_exists('content_id', $payload)) {
            $content['content_id'] = (int) $payload['content_id'];
        }

        foreach (self::JSON_FIELDS as $field) {
            if (!array_key_exists($field, $content)) {
                continue;
            }

            $value = $content[$field];
            if ($value === null || $value === '') {
                $content[$field] = null;
                continue;
            }

            if (is_array($value)) {
                continue;
            }

            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                    $content[$field] = $decoded;
                    continue;
                }
            }

            $content[$field] = null;
            $errors[] = $this->diagnostic(
                $columnIndex,
                $contentIndex,
                $fieldPrefix . $field,
                'invalid_json',
                "{$subject}의 {$field} JSON 형식이 올바르지 않습니다."
            );
        }

        foreach (self::STRING_LIMITS as $field => $maxLength) {
            if (!array_key_exists($field, $content)) {
                continue;
            }

            if ($content[$field] !== null && !is_scalar($content[$field])) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . $field,
                    'invalid_string',
                    "{$subject}의 {$field} 값이 문자열이 아닙니다."
                );
                $content[$field] = null;
                continue;
            }

            $value = trim((string) ($content[$field] ?? ''));
            $content[$field] = $value === '' ? null : $value;

            if ($content[$field] !== null && mb_strlen($content[$field]) > $maxLength) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . $field,
                    'string_too_long',
                    "{$subject}의 {$field} 필드는 {$maxLength}자를 초과할 수 없습니다."
                );
            }
        }

        if (array_key_exists('is_active', $content)) {
            $content['is_active'] = $this->normalizeBool($content['is_active']);
        }

        $contentType = $content['content_type'] ?? null;
        $definition = null;

        if ($contentType !== null) {
            // 하이픈도 허용한다. BlockRegistry 는 타입 문자열 형식을 강제하지 않아
            // 확장이 'mshop-live-orders' 처럼 등록해 쓰고 있는데, 여기서만 더 좁게
            // 잡아 블록킷 적용이 막혔다. 경로 주입 위험이 있는 '.'·'/' 는 계속 막는다.
            if (preg_match('/^[a-z][a-z0-9_-]{0,49}$/', $contentType) !== 1) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . 'content_type',
                    'invalid_content_type',
                    "{$subject}의 콘텐츠 타입 형식이 올바르지 않습니다."
                );
            } else {
                $definition = BlockRegistry::getContentType($contentType);
                if ($definition === null) {
                    // content_id 를 가진 기존 콘텐츠는 확장이 제거된 상태여도 원형
                    // 보존을 허용한다 — 아니면 미설치 타입이 낀 칸은 간격·순서 등
                    // 무엇을 바꿔도 행 저장 전체가 실패한다. 소유권(해당 칸/도메인
                    // 실재 여부)은 저장 계층 syncForColumn 이 예외로 거부한다.
                    $hasExistingId = (int) ($content['content_id'] ?? 0) > 0;
                    if ($context->allowUnresolvedExtension || $hasExistingId) {
                        $warnings[] = $this->diagnostic(
                            $columnIndex,
                            $contentIndex,
                            $fieldPrefix . 'content_type',
                            'unresolved_extension',
                            "설치되지 않은 블록 타입을 보존했습니다: {$contentType}"
                        );
                    } else {
                        $errors[] = $this->diagnostic(
                            $columnIndex,
                            $contentIndex,
                            $fieldPrefix . 'content_type',
                            'unregistered_content_type',
                            "등록되지 않은 콘텐츠 타입입니다: {$contentType}"
                        );
                    }
                }
            }
        }

        $submittedKind = $content['content_kind'] ?? null;
        if ($definition !== null) {
            $expectedKind = (string) $definition['kind'];
            if ($submittedKind === null) {
                $content['content_kind'] = $expectedKind;
            } elseif ($submittedKind !== $expectedKind) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . 'content_kind',
                    'content_kind_mismatch',
                    "{$subject}의 콘텐츠 종류가 등록 정의와 일치하지 않습니다."
                );
            }
        } else {
            $content['content_kind'] = $submittedKind ?? BlockContentKind::CORE->value;
        }

        if (BlockContentKind::tryFrom((string) ($content['content_kind'] ?? '')) === null) {
            $errors[] = $this->diagnostic(
                $columnIndex,
                $contentIndex,
                $fieldPrefix . 'content_kind',
                'invalid_content_kind',
                "유효하지 않은 콘텐츠 종류입니다: " . (string) ($content['content_kind'] ?? '')
            );
        }

        if ($contentType === BlockContentType::INCLUDE->value && !$context->allowInclude) {
            $message = $context->source === BlockColumnWriteContext::SOURCE_KIT_IMPORT
                ? "{$subject}의 include 블록은 블록 킷에서 허용되지 않습니다."
                : 'Include 블록은 최고관리자만 사용할 수 있습니다.';
            $errors[] = $this->diagnostic(
                $columnIndex,
                $contentIndex,
                $fieldPrefix . 'content_type',
                'include_not_allowed',
                $message
            );
        }

        $contentSkin = $content['content_skin'] ?? null;
        if ($contentType !== null && $contentSkin !== null) {
            if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $contentSkin) !== 1) {
                $errors[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . 'content_skin',
                    'invalid_skin_name',
                    "{$subject}의 스킨 이름 형식이 올바르지 않습니다."
                );
            } elseif ($definition !== null && !$this->skinService->isValidSkin($contentType, $contentSkin)) {
                // 블록 킷은 남의 설치본에서 온 파일이라, 이 설치본에 없는 스킨을 지목할 수
                // 있다. 스킨은 순수 표현이고 한 타입의 모든 스킨이 같은 표준 변수를 받으므로
                // (SkinRendererTrait) 기본 스킨으로 대체해도 데이터 계약이 깨지지 않는다.
                // 스킨 하나 때문에 킷 전체 적용을 막는 것보다, 대체 사실을 알리고 통과시킨다.
                //
                // 관리자 폼 저장에는 적용하지 않는다. 그쪽 값은 실재 스킨 드롭다운에서 오므로
                // 없는 스킨이 왔다면 화면 버그이거나 조작된 요청이고, 조용히 고칠 일이 아니다.
                $fallbackSkin = $this->skinService->getDefaultSkinName($contentType);
                $canFallBack = $context->source === BlockColumnWriteContext::SOURCE_KIT_IMPORT
                    && $fallbackSkin !== $contentSkin
                    && $this->skinService->isValidSkin($contentType, $fallbackSkin);

                if ($canFallBack) {
                    $content['content_skin'] = $fallbackSkin;
                    $warnings[] = $this->diagnostic(
                        $columnIndex,
                        $contentIndex,
                        $fieldPrefix . 'content_skin',
                        'skin_fallback',
                        "{$subject}의 스킨 '{$contentSkin}' 이 이 사이트에 없어 '{$fallbackSkin}' 으로 적용합니다."
                    );
                } else {
                    $errors[] = $this->diagnostic(
                        $columnIndex,
                        $contentIndex,
                        $fieldPrefix . 'content_skin',
                        'missing_skin',
                        "존재하지 않는 블록 스킨입니다: {$contentType}/{$contentSkin}"
                    );
                }
            }
        }

        // 콘텐츠 타입이 선언한 최대 아이템 수 강제.
        // 관리자 UI 의 maxItems 는 화면 가드일 뿐이라 요청을 직접 보내면 뚫린다.
        // 렌더러가 어차피 초과분을 무시하므로 오류 대신 절단 + 경고로 처리한다.
        $maxItems = $definition['options']['maxItems'] ?? null;
        if ($maxItems !== null && (int) $maxItems > 0 && is_array($content['content_items'] ?? null)) {
            $items = $content['content_items'];
            if (array_is_list($items) && count($items) > (int) $maxItems) {
                $content['content_items'] = array_slice($items, 0, (int) $maxItems);
                $warnings[] = $this->diagnostic(
                    $columnIndex,
                    $contentIndex,
                    $fieldPrefix . 'content_items',
                    'items_truncated',
                    "{$subject}의 아이템은 {$maxItems}개까지만 저장됩니다. 초과분은 제외했습니다."
                );
            }
        }

        if ($contentType === BlockContentType::HTML->value) {
            $config = $content['content_config'] ?? null;
            if (is_array($config)) {
                if (!$context->allowRawJs && trim((string) ($config['js'] ?? '')) !== '') {
                    $errors[] = $this->diagnostic(
                        $columnIndex,
                        $contentIndex,
                        $fieldPrefix . 'content_config.js',
                        'raw_js_not_allowed',
                        '이 저장 경로에서는 직접 입력 JS를 사용할 수 없습니다.'
                    );
                }

                $content['content_config'] = $this->contentSanitizer->sanitizeHtmlConfig($config);
            }
        }

        return [$content, $errors, $warnings];
    }

    private function normalizeBool(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'y', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * 레거시 단일 경로(content_index=null)는 기존 진단 배열 형태를 그대로
     * 유지하고, 스택 경로에만 content_index 를 추가한다 — 기존 소비자·테스트가
     * 기대하는 배열 모양을 깨지 않는다.
     *
     * @return array<string, mixed>
     */
    private function diagnostic(
        int $columnIndex,
        ?int $contentIndex,
        string $field,
        string $code,
        string $message
    ): array {
        $diagnostic = [
            'column_index' => $columnIndex,
            'field' => $field,
            'code' => $code,
            'message' => $message,
        ];

        if ($contentIndex !== null) {
            $diagnostic['content_index'] = $contentIndex;
        }

        return $diagnostic;
    }
}
