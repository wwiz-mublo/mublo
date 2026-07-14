<?php

namespace Mublo\Packages\Shop\EventSubscriber;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Core\Event\Search\SearchEvent;
use Mublo\Core\Event\Search\SearchSourceCollectEvent;
use Mublo\Packages\Shop\Repository\ProductRepository;

/**
 * 상품 검색 구독자
 *
 * SearchEvent 발행 시 활성 상품을 검색하여 결과를 추가한다.
 * ShopProvider.boot()에서 등록.
 */
class ShopSearchSubscriber implements EventSubscriberInterface
{
    private ProductRepository $productRepository;

    public function __construct(ProductRepository $productRepository)
    {
        $this->productRepository = $productRepository;
    }

    public static function getSubscribedEvents(): array
    {
        return [
            SearchSourceCollectEvent::class => 'onCollect',
            SearchEvent::class              => 'onSearch',
        ];
    }

    public function onCollect(SearchSourceCollectEvent $event): void
    {
        $event->addSource('shop', '쇼핑몰 상품', false);
    }

    public function onSearch(SearchEvent $event): void
    {
        if (!$event->isSourceEnabled('shop')) {
            return;
        }

        $keyword  = $event->getKeyword();
        $domainId = $event->getDomainId();

        $total = $this->productRepository->countByKeyword($domainId, $keyword);
        if ($total === 0) {
            return;
        }

        $items = $this->productRepository->searchByKeyword(
            $domainId,
            $keyword,
            $event->getPerSource()
        );

        $event->addResults('shop', '쇼핑몰 상품', $items, $total, [
            'more_url' => '/shop/products?keyword=' . rawurlencode($keyword),
        ]);
    }
}
