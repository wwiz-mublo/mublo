<?php
namespace Tests\SnsLogin\Unit;

use Mublo\Plugin\SnsLogin\Repository\SnsLoginConfigRepository;
use Mublo\Plugin\SnsLogin\Service\SnsLoginConfigService;
use Mublo\Contract\Security\SensitiveValueCodecInterface;
use PHPUnit\Framework\TestCase;

class SnsLoginConfigServiceTest extends TestCase
{
    public function testConfigIsLoadedOncePerDomainDuringRequest(): void
    {
        $repository = $this->createMock(SnsLoginConfigRepository::class);
        $repository->expects($this->once())
            ->method('findByDomain')
            ->with(7)
            ->willReturn(null);
        $encryption = $this->createMock(SensitiveValueCodecInterface::class);
        $service = new SnsLoginConfigService($repository, $encryption);

        $service->getEnabledMap(7);
        $service->getProviderConfig(7, 'naver');
        $service->getProviderConfig(7, 'kakao');
        $service->getProviderConfig(7, 'google');
    }

    public function testSavesEncryptedKakaoLoginSecretAndAdminKey(): void
    {
        $saved = null;
        $repository = $this->createMock(SnsLoginConfigRepository::class);
        $repository->method('findByDomain')->willReturn(null);
        $repository->expects($this->once())->method('save')->willReturnCallback(
            function (int $domainId, array $config) use (&$saved): void {
                $this->assertSame(7, $domainId);
                $saved = $config;
            },
        );
        $encryption = $this->createMock(SensitiveValueCodecInterface::class);
        $encryption->method('encrypt')->willReturnCallback(fn(string $value): string => 'encrypted:' . $value);

        $service = new SnsLoginConfigService($repository, $encryption);
        $result = $service->save(7, [
            'kakao' => [
                'enabled' => '1',
                'client_id' => 'rest-key',
                'client_secret' => 'login-secret',
                'admin_key' => 'admin-key',
                'javascript_key' => '',
            ],
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('encrypted:login-secret', $saved['kakao']['client_secret']);
        $this->assertSame('encrypted:admin-key', $saved['kakao']['admin_key']);
        $this->assertSame('login-secret', $service->getProviderConfig(7, 'kakao')['client_secret']);
        $this->assertSame('admin-key', $service->getProviderConfig(7, 'kakao')['admin_key']);
    }

    public function testKakaoLoginSecretRemainsOptionalButAdminKeyIsRequired(): void
    {
        $repository = $this->createMock(SnsLoginConfigRepository::class);
        $repository->method('findByDomain')->willReturn(null);
        $encryption = $this->createMock(SensitiveValueCodecInterface::class);
        $encryption->method('encrypt')->willReturnArgument(0);
        $service = new SnsLoginConfigService($repository, $encryption);

        $withoutSecret = $service->save(7, [
            'kakao' => ['enabled' => '1', 'client_id' => 'rest-key', 'admin_key' => 'admin-key'],
        ]);
        $withoutAdmin = $service->save(7, [
            'kakao' => ['enabled' => '1', 'client_id' => 'rest-key', 'client_secret' => 'secret'],
        ]);

        $this->assertTrue($withoutSecret->isSuccess());
        $this->assertTrue($withoutAdmin->isFailure());
    }
}
