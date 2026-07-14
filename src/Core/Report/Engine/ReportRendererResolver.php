<?php

namespace Mublo\Core\Report\Engine;

use Mublo\Core\Registry\ContractRegistry;
use Mublo\Core\Report\Contract\ReportRendererInterface;
use Mublo\Core\Report\Exception\UnsupportedFormatException;

class ReportRendererResolver
{
    private ContractRegistry $contracts;

    public function __construct(ContractRegistry $contracts)
    {
        $this->contracts = $contracts;
    }

    public function resolve(string $format): ReportRendererInterface
    {
        $format = strtolower(trim($format));

        if (!$this->contracts->hasKey(ReportRendererInterface::class, $format)) {
            throw new UnsupportedFormatException("지원하지 않는 포맷입니다: {$format}");
        }

        $renderer = $this->contracts->get(ReportRendererInterface::class, $format);
        if (!$renderer instanceof ReportRendererInterface) {
            throw new UnsupportedFormatException("Renderer 계약이 올바르지 않습니다: {$format}");
        }

        return $renderer;
    }
}

