<?php

namespace Mublo\Core\Dashboard\Widget;

use Mublo\Core\Context\Context;
use Mublo\Core\Dashboard\AbstractDashboardWidget;

class SystemInfoWidget extends AbstractDashboardWidget
{
    public function canView(Context $context): bool
    {
        return ($context->getDomainId() ?? 1) === 1;
    }

    public function id(): string
    {
        return 'core.system_info';
    }

    public function title(): string
    {
        return '시스템 정보';
    }

    public function defaultSlot(): int
    {
        return 2;
    }

    public function render(): string
    {
        $phpVersion = PHP_VERSION;
        $serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
        $dbVersion = $this->getDatabaseVersion();
        $memoryUsage = $this->formatBytes(memory_get_usage(false));
        $memoryPeak = $this->formatBytes(memory_get_peak_usage(true));
        $memoryLimit = $this->formatBytes($this->parseIniSize(ini_get('memory_limit') ?: '-1'));
        $diskFree = $this->formatBytes((int) disk_free_space(dirname(__DIR__, 4)));
        $uploadMax = ini_get('upload_max_filesize');
        $postMax = ini_get('post_max_size');

        $items = [
            ['label' => 'PHP 버전', 'value' => $phpVersion, 'icon' => 'bi-filetype-php', 'pastel' => 'pastel-icon-purple'],
            ['label' => '웹 서버', 'value' => $serverSoftware, 'icon' => 'bi-hdd-rack', 'pastel' => 'pastel-icon-blue'],
            ['label' => 'DB 버전', 'value' => $dbVersion, 'icon' => 'bi-database', 'pastel' => 'pastel-icon-green'],
            ['label' => '메모리', 'value' => "{$memoryUsage} / {$memoryLimit}", 'icon' => 'bi-memory', 'pastel' => 'pastel-icon-orange'],
            ['label' => '디스크 여유', 'value' => $diskFree, 'icon' => 'bi-hdd', 'pastel' => 'pastel-icon-sky'],
            ['label' => '업로드 제한', 'value' => $uploadMax . ' / POST ' . $postMax, 'icon' => 'bi-cloud-arrow-up', 'pastel' => 'pastel-icon-red'],
        ];

        $html = '<div class="d-flex flex-column gap-2">';
        foreach ($items as $item) {
            $label = htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8');
            $value = htmlspecialchars($item['value'], ENT_QUOTES, 'UTF-8');
            $html .= '<div class="d-flex align-items-center gap-3 rounded-3 px-3 py-2 border border-light-subtle">';
            $html .= '<div class="' . $item['pastel'] . ' rounded-circle d-flex align-items-center justify-content-center flex-shrink-0" style="width:32px;height:32px"><i class="bi ' . $item['icon'] . '" style="font-size:14px"></i></div>';
            $html .= '<div class="flex-grow-1">';
            $html .= '<div class="text-muted" style="font-size:11px">' . $label . '</div>';
            $html .= '<div class="fw-semibold" style="font-size:0.85rem;line-height:1.2">' . $value . '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    private function getDatabaseVersion(): string
    {
        try {
            $db = \Mublo\Infrastructure\Database\DatabaseManager::getInstance()->connect();
            $result = $db->selectOne('SELECT VERSION() as v');
            return $result['v'] ?? 'N/A';
        } catch (\Throwable $e) {
            return 'N/A';
        }
    }

    private function parseIniSize(string $size): int
    {
        $size = trim($size);
        if ($size === '-1') return 0;
        $unit = strtoupper(substr($size, -1));
        $value = (int) $size;
        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = (int) floor(log($bytes, 1024));
        $i = min($i, count($units) - 1);
        return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
    }
}
