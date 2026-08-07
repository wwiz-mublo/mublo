<?php

namespace Tests\Unit\Service\Menu;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Code\CodeGenerator;
use Mublo\Repository\Menu\MenuItemRepository;
use Mublo\Repository\Menu\MenuTreeRepository;
use Mublo\Service\Menu\MenuService;
use PHPUnit\Framework\TestCase;

/**
 * ensureItem() 이 확장이 준 provider_name 을 그대로 저장하는지 고정한다.
 *
 * 왜 계약인가
 *   provider_name 은 확장의 디렉터리 이름이고, DB 밖에서 그 이름으로 파일 경로를
 *   만들거나(manifest.json) 활성 확장 목록과 문자열 비교를 하는 소비자가 있다.
 *   한 글자라도 바꾸면 그쪽이 조용히 어긋난다.
 *
 * 왜 조용한가
 *   MySQL/MariaDB 의 기본 콜레이션이 대소문자를 구분하지 않아 DB 안의 조회는 전부
 *   통과한다. 예외도 나지 않고 로그도 남지 않는다. 실제로 strtolower() 가 들어가
 *   있던 동안 관리자 폼의 제공자명 select 가 선택되지 않고 마이페이지 사이드바가
 *   폴백 아이콘으로 떨어졌지만, 두 증상이 같은 원인이라는 신호가 어디에도 없었다.
 */
class MenuServiceProvisioningTest extends TestCase
{
    public function testEnsureItemStoresProviderNameExactlyAsGiven(): void
    {
        $captured = null;

        $service = $this->serviceWithCapture($captured);

        $service->ensureItem(7, 'faq', [
            'label' => 'FAQ',
            'url' => '/faq',
            'provider_type' => 'plugin',
            'provider_name' => 'Faq',
        ]);

        $this->assertSame('Faq', $captured['provider_name']);
    }

    /** 중첩 플러그인은 이름에 구분자가 들어간다. 그 형태도 보존해야 한다. */
    public function testEnsureItemPreservesNestedPluginNames(): void
    {
        $captured = null;

        $service = $this->serviceWithCapture($captured);

        $service->ensureItem(7, 'report', [
            'label' => '신고',
            'url' => '/board/report',
            'provider_type' => 'plugin',
            'provider_name' => 'Board/BoardReport',
        ]);

        $this->assertSame('Board/BoardReport', $captured['provider_name']);
    }

    /** 공백은 다듬되 그 외에는 손대지 않는다. */
    public function testEnsureItemTrimsWithoutChangingCase(): void
    {
        $captured = null;

        $service = $this->serviceWithCapture($captured);

        $service->ensureItem(7, 'shop', [
            'label' => '쇼핑',
            'url' => '/shop',
            'provider_type' => 'package',
            'provider_name' => '  Shop  ',
        ]);

        $this->assertSame('Shop', $captured['provider_name']);
    }

    /**
     * createItem 에 전달된 데이터를 $captured 로 빼내는 MenuService.
     *
     * @param array|null $captured
     */
    private function serviceWithCapture(&$captured): MenuService
    {
        $itemRepository = $this->createMock(MenuItemRepository::class);
        $itemRepository->method('findByProvider')->willReturn([]);

        $service = $this->getMockBuilder(MenuService::class)
            ->setConstructorArgs([
                $itemRepository,
                $this->createMock(MenuTreeRepository::class),
                $this->createMock(CodeGenerator::class),
            ])
            ->onlyMethods(['createItem'])
            ->getMock();

        $service->method('createItem')
            ->willReturnCallback(function (int $domainId, array $data) use (&$captured): Result {
                $captured = $data;
                return Result::success('', ['menu_code' => 'MENU0001', 'item_id' => 1]);
            });

        return $service;
    }
}
