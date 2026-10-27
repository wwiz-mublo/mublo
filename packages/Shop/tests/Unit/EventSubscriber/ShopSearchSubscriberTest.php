<?php
declare(strict_types=1);

namespace Tests\Shop\Unit\EventSubscriber;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Contract\Auth\AuthenticatedUser;
use Mublo\Core\Event\Search\SearchEvent;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\EventSubscriber\ShopSearchSubscriber;
use Mublo\Packages\Shop\Helper\PriceVisibility;
use Mublo\Packages\Shop\Repository\ProductRepository;
use Mublo\Packages\Shop\Service\ShopConfigService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShopSearchSubscriberTest extends TestCase
{
    #[DataProvider('visibilityCases')]
    public function testSearchSummaryRespectsViewerPolicy(array $config, ?int $level, string $summary): void
    {
        $repository = $this->createMock(ProductRepository::class);
        $repository->method('countByKeyword')->with(7, '상품')->willReturn(2);
        $items = [
            ['title' => '상품 A', 'url' => '/shop/products/1', 'summary' => '123,456원'],
            ['title' => '상품 B', 'url' => '/shop/products/2', 'summary' => '123,456원'],
        ];
        $repository->method('searchByKeyword')->with(7, '상품', 5)->willReturn($items);
        $settings = $this->createMock(ShopConfigService::class);
        $settings->method('getConfig')->with(7)->willReturn(Result::success('', ['config' => $config]));
        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('currentUser')->willReturn($level === null ? null : new AuthenticatedUser(
            1, 7, 'reviewer', null, $level, false, false, false, null,
        ));

        $event = new SearchEvent('상품', 7, ['shop'], ['shop']);
        (new ShopSearchSubscriber($repository, $settings, $auth))->onSearch($event);
        $group = $event->getResults()['shop'];
        $this->assertSame(2, $group['total']);
        foreach ($items as $index => $item) {
            $item['summary'] = $summary;
            $this->assertSame($item, $group['items'][$index]);
        }
    }

    public static function visibilityCases(): array
    {
        return [
            'default guest' => [[], null, '123,456원'],
            'all guest' => [['price_display' => 'ALL'], null, '123,456원'],
            'member guest' => [['price_display' => 'MEMBER'], null, PriceVisibility::DEFAULT_HIDDEN_TEXT],
            'member logged in' => [['price_display' => 'MEMBER'], 1, '123,456원'],
            'level guest' => [['price_display' => 'LEVEL', 'price_display_level' => 5], null, PriceVisibility::DEFAULT_HIDDEN_TEXT],
            'below level custom text' => [['price_display' => 'LEVEL', 'price_display_level' => 5, 'price_hidden_text' => '인증 회원 전용가'], 4, '인증 회원 전용가'],
            'equal level' => [['price_display' => 'LEVEL', 'price_display_level' => 5], 5, '123,456원'],
            'above level' => [['price_display' => 'LEVEL', 'price_display_level' => 5], 6, '123,456원'],
        ];
    }
}
