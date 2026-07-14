<?php
namespace Mublo\Service\AI\Provider;

abstract class AbstractJsonHtmlProvider implements AiProviderInterface
{
    final public function generate(string $apiKey, string $model, string $systemPrompt, string $userPrompt, array $attachments = []): array
    {
        return $this->normalizeHtmlResult($this->generateStructured(
            $apiKey,
            $model,
            $systemPrompt,
            $userPrompt,
            $this->schema(),
            $attachments,
        ));
    }

    protected function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'html' => ['type' => 'string'],
                'css' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'behavior' => [
                    'type' => 'object',
                    'properties' => [
                        'types' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'enum' => ['slider', 'tabs', 'accordion']],
                            'description' => 'Trusted interactive behaviors required by the block. Use an empty array for static content.',
                        ],
                        'autoplay_seconds' => [
                            'type' => 'integer',
                            'description' => 'For sliders use 5 by default, 0 only for explicit manual/no-autoplay requests, or the user-requested interval from 1 to 30.',
                        ],
                        'slider_preset' => [
                            'type' => 'string',
                            'enum' => ['none', 'hero', 'cards', 'gallery'],
                            'description' => 'Slide layout preset, used only when types contains "slider" (otherwise use "none"). hero = one large banner per view, cards = feature/review/product card carousel, gallery = image-dense carousel. Mublo decides slides-per-view, breakpoints, loop and pagination from the preset.',
                        ],
                    ],
                    'required' => ['types', 'autoplay_seconds', 'slider_preset'],
                    'additionalProperties' => false,
                ],
            ],
            'required' => ['html', 'css', 'notes', 'behavior'],
            'additionalProperties' => false,
        ];
    }

    protected function decodeJson(string $text): array
    {
        $text = trim($text);
        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text) ?? $text;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new \RuntimeException('AI 결과 형식을 확인할 수 없습니다.');
        }
        return $data;
    }

    private function normalizeHtmlResult(array $data): array
    {
        if (!isset($data['html']) || !is_string($data['html'])) {
            throw new \RuntimeException('AI 결과 형식을 확인할 수 없습니다.');
        }
        return [
            'html' => $data['html'],
            'css' => is_string($data['css'] ?? null) ? $data['css'] : '',
            'notes' => is_string($data['notes'] ?? null) ? mb_strimwidth($data['notes'], 0, 500, '…') : '',
            'behavior' => $this->normalizeBehavior($data['behavior'] ?? null),
        ];
    }

    private function normalizeBehavior(mixed $behavior): array
    {
        if (!is_array($behavior)) {
            return ['types' => [], 'autoplay_seconds' => 0, 'slider_preset' => 'none'];
        }

        $types = is_array($behavior['types'] ?? null) ? $behavior['types'] : [];
        // 생성 이력이나 테스트 adapter가 잠시 이전 단일 type 형식을 반환해도 안전하게 흡수한다.
        if (!$types && is_string($behavior['type'] ?? null) && $behavior['type'] !== 'none') {
            $types = [$behavior['type']];
        }
        $types = array_values(array_unique(array_filter(
            $types,
            static fn (mixed $type): bool => is_string($type)
                && in_array($type, ['slider', 'tabs', 'accordion'], true)
        )));

        // 프리셋 정규화 (개선 계획 §6.4): slider가 없으면 none, slider가 있는데
        // 값이 없거나 잘못되면 hero — 자유 형식 옵션은 어떤 경우에도 통과하지 않는다.
        $preset = $behavior['slider_preset'] ?? '';
        if (!in_array('slider', $types, true)) {
            $preset = 'none';
        } elseif (!is_string($preset) || !in_array($preset, \Mublo\Service\AI\HtmlBlockAiPolicy::SLIDER_PRESETS, true)) {
            $preset = 'hero';
        }

        return [
            'types' => $types,
            'autoplay_seconds' => max(0, min(30, (int) ($behavior['autoplay_seconds'] ?? 0))),
            'slider_preset' => $preset,
        ];
    }
}
