<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\CategoryRepository;
use Mublo\Packages\Shop\Service\CategoryService;
use PHPUnit\Framework\TestCase;

/**
 * 분류별 목록 안내 이미지
 *
 * - 현재 분류부터 조상으로 올라가며 PC 이미지가 있는 가장 가까운 분류를 쓴다
 * - 모바일 이미지가 없으면 PC 이미지
 * - 입력은 내부 경로(/...)·http(s) 만 받고, 넘기지 않은 칼럼은 건드리지 않는다
 */
class CategoryServiceListBannerTest extends TestCase
{
    /** @param array<string,array<string,mixed>> $rows */
    private function serviceWith(array $rows, ?array &$askedCodes = null): CategoryService
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findListBannersByCodes')->willReturnCallback(
            function (int $domainId, array $codes) use ($rows, &$askedCodes) {
                $askedCodes = $codes;
                return array_intersect_key($rows, array_flip($codes));
            }
        );
        return new CategoryService($repo);
    }

    public function testUsesOwnBannerFirst(): void
    {
        $service = $this->serviceWith([
            '20'     => ['list_banner_image' => '/img/samsung.png'],
            '2010'   => ['list_banner_image' => '/img/samsung-tv.png', 'list_banner_mobile_image' => '/img/samsung-tv-m.png', 'list_banner_link' => '/shop/exhibitions/3'],
        ]);

        $banner = $service->resolveListBanner(1, ['20', '2010']);

        $this->assertSame([
            'category_code' => '2010',
            'pc'            => '/img/samsung-tv.png',
            'mobile'        => '/img/samsung-tv-m.png',
            'link'          => '/shop/exhibitions/3',
        ], $banner);
    }

    public function testInheritsFromNearestAncestor(): void
    {
        $service = $this->serviceWith([
            '20'     => ['list_banner_image' => '/img/samsung.png'],
            '2010'   => ['list_banner_image' => '/img/samsung-tv.png'],
            '201010' => ['list_banner_image' => null, 'list_banner_mobile_image' => null],
        ], $asked);

        $banner = $service->resolveListBanner(1, ['20', '2010', '201010']);

        $this->assertSame(['20', '2010', '201010'], $asked, '경로 전체를 한 번에 조회한다');
        $this->assertSame('2010', $banner['category_code'], '조상 중 가장 가까운 분류');
        $this->assertSame('/img/samsung-tv.png', $banner['pc']);
    }

    public function testFallsBackToRootAndMobileFallsBackToPc(): void
    {
        $service = $this->serviceWith([
            'a0' => ['list_banner_image' => '/img/fullpay.png', 'list_banner_mobile_image' => ''],
        ]);

        $banner = $service->resolveListBanner(1, ['a0', 'a010']);

        $this->assertSame('a0', $banner['category_code']);
        $this->assertSame('/img/fullpay.png', $banner['mobile']);
        $this->assertSame('', $banner['link']);
    }

    public function testMobileOnlyIsIgnored(): void
    {
        $service = $this->serviceWith([
            '70' => ['list_banner_image' => '', 'list_banner_mobile_image' => '/img/lg-m.png'],
        ]);

        $this->assertNull($service->resolveListBanner(1, ['70']));
    }

    public function testNoBannerAnywhereReturnsNull(): void
    {
        $service = $this->serviceWith([]);

        $this->assertNull($service->resolveListBanner(1, ['30', '3010']));
        $this->assertNull($service->resolveListBanner(1, []));
    }

    public function testUpdateStoresBannerFields(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findItemInDomain')->willReturn(['category_id' => 5, 'category_code' => '20', 'name' => '삼성전자']);
        $saved = null;
        $repo->method('updateItemInDomain')->willReturnCallback(function (int $d, int $id, array $data) use (&$saved) {
            $saved = $data;
            return true;
        });

        $result = (new CategoryService($repo))->updateItem(1, 5, [
            'list_banner_image'        => '/storage/1/shop/category/a.png',
            'list_banner_mobile_image' => '',
            'list_banner_link'         => 'https://example.com/event',
        ]);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
        $this->assertSame('/storage/1/shop/category/a.png', $saved['list_banner_image']);
        $this->assertNull($saved['list_banner_mobile_image']);
        $this->assertSame('https://example.com/event', $saved['list_banner_link']);
    }

    public function testUpdateWithoutBannerKeysLeavesColumnsAlone(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findItemInDomain')->willReturn(['category_id' => 5, 'category_code' => '20', 'name' => '삼성전자']);
        $saved = null;
        $repo->method('updateItemInDomain')->willReturnCallback(function (int $d, int $id, array $data) use (&$saved) {
            $saved = $data;
            return true;
        });

        (new CategoryService($repo))->updateItem(1, 5, ['description' => '설명']);

        $this->assertSame(['description' => '설명'], $saved);
    }

    /** @return array<string,array{0:array<string,string>}> */
    public static function invalidInputs(): array
    {
        return [
            'javascript 링크' => [['list_banner_link' => 'javascript:alert(1)']],
            '프로토콜 상대'    => [['list_banner_image' => '//evil.example/a.png']],
            '따옴표'          => [['list_banner_image' => '/a.png" onerror="x']],
            '모바일만'        => [['list_banner_image' => '', 'list_banner_mobile_image' => '/m.png']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testRejectsInvalidInput(array $input): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findItemInDomain')->willReturn(['category_id' => 5, 'category_code' => '20', 'name' => '삼성전자']);
        $repo->expects($this->never())->method('updateItemInDomain');

        $result = (new CategoryService($repo))->updateItem(1, 5, $input);

        $this->assertTrue($result->isFailure());
    }
}
