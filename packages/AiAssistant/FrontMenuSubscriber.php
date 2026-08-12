<?php
declare(strict_types=1);

namespace Mublo\Packages\AiAssistant;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Menu\MenuItemsFilterEvent;

/**
 * AI 비서 전용 공개·회원 동선을 DB 메뉴 설정과 충돌 없이 보완한다.
 * 운영자가 동일 URL을 직접 배치한 경우에는 중복으로 추가하지 않는다.
 */
final class FrontMenuSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [MenuItemsFilterEvent::class => 'onMenuItemsFilter'];
    }

    public function onMenuItemsFilter(MenuItemsFilterEvent $event): void
    {
        $items = $event->getItems();
        if ($event->getScope() === MenuItemsFilterEvent::SCOPE_TREE) {
            foreach ([
                ['ai_features', '주요 기능', '/#features', 100],
                ['ai_how_it_works', '이용 방법', '/#how-it-works', 110],
                ['ai_security', '보안', '/#security', 120],
                ['ai_workspace', '워크스페이스', '/workspace', 130, 'member'],
            ] as $definition) {
                [$code, $label, $url, $order] = $definition;
                if ($this->containsUrl($items, $url)) {
                    continue;
                }
                $items[] = [
                    'node_id' => 0,
                    'domain_id' => $event->getDomainId(),
                    'menu_code' => $code,
                    'parent_code' => null,
                    'path_code' => $code,
                    'path_name' => $label,
                    'depth' => 1,
                    'sort_order' => $order,
                    'label' => $label,
                    'url' => $url,
                    'icon' => null,
                    'target' => '_self',
                    'visibility' => $definition[4] ?? 'all',
                    'min_level' => null,
                    'required_permission' => null,
                    'show_on_pc' => 1,
                    'show_on_mobile' => 1,
                    'is_active' => 1,
                    'provider_type' => 'package',
                    'provider_name' => 'AiAssistant',
                    'pair_code' => null,
                ];
            }
            $event->setItems($items);
            return;
        }

        if ($event->getScope() === MenuItemsFilterEvent::SCOPE_UTILITY
            && !$this->containsUrl($items, '/workspace')
        ) {
            $items[] = [
                'item_id' => 0,
                'domain_id' => $event->getDomainId(),
                'menu_code' => 'ai_workspace_utility',
                'label' => '워크스페이스',
                'url' => '/workspace',
                'target' => '_self',
                'visibility' => 'member',
                'utility_order' => 0,
                'is_system' => 1,
                'provider_type' => 'package',
                'provider_name' => 'AiAssistant',
            ];
            $event->setItems($items);
        }
    }

    /** @param array<int,array<string,mixed>> $items */
    private function containsUrl(array $items, string $url): bool
    {
        foreach ($items as $item) {
            if (($item['url'] ?? null) === $url) {
                return true;
            }
        }
        return false;
    }
}
