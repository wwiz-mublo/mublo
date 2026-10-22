<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Contract\Auth\AuthContextInterface;
use Mublo\Core\Context\Context;
use Mublo\Core\Response\RedirectResponse;
use Mublo\Core\Response\ViewResponse;
use Mublo\Core\Result\Result;
use Mublo\Packages\Shop\Controller\Front\AddressController;
use Mublo\Packages\Shop\Service\MemberAddressService;
use Mublo\Packages\Shop\Service\ShopConfigService;
use PHPUnit\Framework\TestCase;

/**
 * 배송지 관리 화면은 로그인한 회원의 현재 도메인 배송지만 그린다.
 */
class AddressControllerIndexTest extends TestCase
{
    public function testGuestIsSentToLogin(): void
    {
        $service = $this->createMock(MemberAddressService::class);
        $service->expects($this->never())->method('getList');

        $response = $this->controller($service, null)->index([], $this->context());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/login', $response->getLocation());
    }

    public function testMemberSeesOwnAddressesOfCurrentDomain(): void
    {
        $addresses = [['address_id' => 3, 'recipient_name' => '홍길동', 'is_default' => 1]];
        $service = $this->createMock(MemberAddressService::class);
        $service->expects($this->once())->method('getList')->with(7, 10)
            ->willReturn(Result::success('', ['addresses' => $addresses]));

        $response = $this->controller($service, 7)->index([], $this->context());

        $this->assertInstanceOf(ViewResponse::class, $response);
        $this->assertSame($addresses, $response->getViewData()['addresses']);
        $this->assertSame(MemberAddressService::MAX_ADDRESSES, $response->getViewData()['maxAddresses']);
    }

    private function controller(MemberAddressService $service, ?int $memberId): AddressController
    {
        $auth = $this->createMock(AuthContextInterface::class);
        $auth->method('id')->willReturn($memberId);

        $config = $this->createMock(ShopConfigService::class);
        $config->method('frontView')->with(10, 'Address/Index')->willReturn('/views/Front/Address/basic/Index');

        return new AddressController($service, $auth, $config);
    }

    private function context(): Context
    {
        $context = $this->createMock(Context::class);
        $context->method('getDomainId')->willReturn(10);
        return $context;
    }
}
