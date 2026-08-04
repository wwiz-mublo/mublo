<?php
declare(strict_types=1);

namespace Mublo\Plugin\Manual\Block;

use Mublo\Core\Block\Renderer\SkinRendererTrait;
use Mublo\Plugin\Manual\Service\ManualService;

abstract class AbstractManualRenderer
{
    use SkinRendererTrait;

    public function __construct(protected readonly ManualService $manualService)
    {
    }

    protected function getSkinBasePath(): string
    {
        return MUBLO_PLUGIN_PATH . '/Manual/views/Block/';
    }

    /** @return list<string> */
    protected function selectedReferences(?array $items): array
    {
        $references = [];
        foreach ($items ?? [] as $item) {
            $value = is_array($item) ? ($item['id'] ?? '') : $item;
            $value = trim((string) $value);
            if ($value !== '') {
                $references[] = $value;
            }
        }

        return array_values(array_unique($references));
    }

    /** @return array{0:int,1:int} */
    protected function responsiveCounts(array $config, int $default = 4): array
    {
        $pc = max(1, min(100, (int) ($config['pc_count'] ?? $default)));
        $mo = max(1, min(100, (int) ($config['mo_count'] ?? $pc)));
        return [$pc, $mo];
    }

    protected function excerpt(?string $html, int $length): string
    {
        $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $length = max(40, min(1000, $length));

        return mb_strlen($text) > $length
            ? rtrim(mb_substr($text, 0, $length)) . '…'
            : $text;
    }
}
