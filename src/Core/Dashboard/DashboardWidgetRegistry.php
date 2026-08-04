<?php
declare(strict_types=1);

namespace Mublo\Core\Dashboard;

use Mublo\Core\Extension\ExtensionRegistrationScope;

class DashboardWidgetRegistry
{
    /** @var array<string, array{widget: DashboardWidgetInterface, priority: int, source: string}> */
    private array $widgets = [];

    /** @var array<string, string> */
    private array $widgetOwners = [];

    /**
     * 위젯 등록
     */
    public function register(string $id, DashboardWidgetInterface $widget, int $priority = 50): void
    {
        $previous = $this->widgets[$id] ?? null;
        $previousOwner = $this->widgetOwners[$id] ?? null;
        $owner = ExtensionRegistrationScope::currentOwner();
        $this->widgets[$id] = [
            'widget'   => $widget,
            'priority' => $priority,
            'source'   => $this->detectSource($id),
        ];

        if ($owner !== null) {
            $this->widgetOwners[$id] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($id, $owner): void {
                if (($this->widgetOwners[$id] ?? null) === $owner) {
                    unset($this->widgetOwners[$id]);
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use (
                $id,
                $owner,
                $previous,
                $previousOwner
            ): void {
                if (($this->widgetOwners[$id] ?? null) !== $owner) {
                    return;
                }

                if ($previous === null) {
                    unset($this->widgets[$id], $this->widgetOwners[$id]);
                    return;
                }

                $this->widgets[$id] = $previous;
                if ($previousOwner === null || !ExtensionRegistrationScope::has($previousOwner)) {
                    unset($this->widgetOwners[$id]);
                } else {
                    $this->widgetOwners[$id] = $previousOwner;
                }
            });
        } else {
            unset($this->widgetOwners[$id]);
        }
    }

    /**
     * 전체 위젯 (priority 정렬)
     *
     * @return array<string, array{widget: DashboardWidgetInterface, priority: int, source: string}>
     */
    public function all(): array
    {
        $sorted = $this->widgets;
        uasort($sorted, fn($a, $b) => $a['priority'] <=> $b['priority']);
        return $sorted;
    }

    /**
     * 위젯 조회
     */
    public function get(string $id): ?DashboardWidgetInterface
    {
        return $this->widgets[$id]['widget'] ?? null;
    }

    /**
     * 위젯 존재 여부
     */
    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    /**
     * 위젯 소스 조회 (core, plugin, package)
     */
    public function getSource(string $id): string
    {
        return $this->widgets[$id]['source'] ?? 'unknown';
    }

    /**
     * 등록된 위젯 ID 목록
     *
     * @return string[]
     */
    public function ids(): array
    {
        return array_keys($this->widgets);
    }

    /**
     * ID 패턴으로 소스 판별
     */
    private function detectSource(string $id): string
    {
        if (str_starts_with($id, 'core.')) return 'core';
        if (str_starts_with($id, 'plugin.')) return 'plugin';
        return 'package';
    }
}
