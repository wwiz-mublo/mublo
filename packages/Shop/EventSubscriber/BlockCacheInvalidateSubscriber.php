<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Packages\Shop\Event\ProductChangedEvent;
use Mublo\Repository\Block\BlockColumnRepository;
use Mublo\Service\Block\BlockRenderService;

/**
 * 상품 변경 → 블록 캐시 무효화
 *
 * 블록은 상품 HTML(가격·이미지·상품명)을 통째로 캐싱하므로, 상품이 바뀌어도
 * 블록 캐시를 깨지 않으면 stale 데이터가 노출된다. 상품 CRUD 시 해당 상품이
 * 진열된 블록 행 캐시를 무효화한다.
 *
 * - 수동 진열(content_type='product'): content_items에 박힌 상품 ID로 역참조
 * - 자동 진열(content_type='product_auto'): ID 매핑이 없어, 멤버십이 바뀌는
 *   등록/삭제 시에만 도메인 단위로 일괄 무효화 (수정은 멤버십 불변이라 제외)
 */
class BlockCacheInvalidateSubscriber implements EventSubscriberInterface
{
    private DependencyContainer $container;

    public function __construct(DependencyContainer $container)
    {
        $this->container = $container;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ProductChangedEvent::class => 'onProductChanged',
        ];
    }

    public function onProductChanged(ProductChangedEvent $event): void
    {
        $domainId = $event->getDomainId();
        if ($domainId <= 0) {
            return;
        }

        /** @var BlockColumnRepository $columns */
        $columns = $this->container->get(BlockColumnRepository::class);
        /** @var BlockRenderService $render */
        $render = $this->container->get(BlockRenderService::class);

        $rowIds = [];

        // 1) 수동 진열 — 변경된 상품 ID가 박힌 칸의 행
        foreach ($event->getGoodsIds() as $goodsId) {
            foreach ($columns->findByContentItem($domainId, 'product', (int) $goodsId) as $col) {
                $rowIds[$col->getRowId()] = true;
            }
        }

        // 2) 자동 진열 — 등록/삭제로 목록 구성이 바뀔 때만 도메인 단위 일괄
        if ($event->affectsAutoBlocks()) {
            foreach ($columns->findByContentType($domainId, 'product_auto') as $col) {
                $rowIds[$col->getRowId()] = true;
            }
        }

        foreach (array_keys($rowIds) as $rowId) {
            $render->invalidateRowCache((int) $rowId);
        }
    }
}
