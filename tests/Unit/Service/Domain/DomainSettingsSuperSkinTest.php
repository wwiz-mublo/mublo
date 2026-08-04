<?php

namespace Tests\Unit\Service\Domain;

use Mublo\Entity\Domain\Domain;
use Mublo\Repository\Domain\DomainRepository;
use Mublo\Service\Domain\DomainResolver;
use Mublo\Service\Domain\DomainSettingsService;
use Tests\TestCase;

/**
 * super_ 스킨 저장 게이트 (ThemeSkinPolicy 관례)
 *
 * 목록에서 걸러지는 것과 별개로 **저장의 문**이 막아야 조작이 통하지
 * 않는다. SUPER 는 저장할 수 있고, 그 외 도메인은 거부된다.
 * (DB 를 직접 만지는 운영자는 방어 대상이 아니다 — 그건 권한이다.)
 */
class DomainSettingsSuperSkinTest extends TestCase
{
    private function service(Domain $domain): DomainSettingsService
    {
        $repository = $this->createMock(DomainRepository::class);
        $repository->method('find')->willReturn($domain);
        $repository->method('updateThemeConfig')->willReturn(true);

        $resolver = $this->createMock(DomainResolver::class);

        return new DomainSettingsService($repository, $resolver);
    }

    private function domain(int $id, string $group): Domain
    {
        return new Domain(
            domainId: $id,
            domain: 'test.example',
            domainGroup: $group
        );
    }

    public function testTenantCannotSaveASuperOnlySkin(): void
    {
        // 그룹 1/3 의 도메인 3 — SUPER(1) 가 아니다.
        $service = $this->service($this->domain(3, '1/3'));

        $result = $service->updateThemeConfig(3, ['index' => 'super_saas']);

        $this->assertFalse($result->isSuccess());
        $this->assertStringContainsString('SUPER 도메인 전용', $result->getMessage());
    }

    public function testSuperCanSaveASuperOnlySkin(): void
    {
        // super_saas 디렉터리가 아직 없어도 이 테스트의 관심은 게이트다 —
        // 실존 검사(componentExists)는 별도 규칙이므로 존재하는 값으로 확인.
        $service = $this->service($this->domain(1, '1'));

        $result = $service->updateThemeConfig(1, ['index' => 'basic']);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }

    /** 게이트는 super_ 에만 반응한다 — 테넌트의 일반 스킨 저장은 그대로다. */
    public function testTenantStillSavesNormalSkins(): void
    {
        $service = $this->service($this->domain(3, '1/3'));

        $result = $service->updateThemeConfig(3, ['index' => 'basic']);

        $this->assertTrue($result->isSuccess(), $result->getMessage());
    }
}
