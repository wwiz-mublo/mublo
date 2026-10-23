<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Member;

use Mublo\Entity\Domain\Domain;
use Mublo\Entity\Member\MemberLevel;
use Mublo\Service\Member\JoinLevelResolver;
use Mublo\Service\Member\MemberFieldService;
use Mublo\Service\Member\MemberLevelService;
use PHPUnit\Framework\TestCase;

/**
 * 가입 때 고른 유형에 매핑된 등급을 주되, 관리자 등급·없는 등급은 주지 않는다.
 */
final class JoinLevelResolverTest extends TestCase
{
    private function domain(int $fieldId, array $map): Domain
    {
        return Domain::fromArray([
            'domain_id' => 1,
            'domain' => 'example.test',
            'site_config' => ['join_level_field_id' => $fieldId, 'join_level_map' => $map],
        ]);
    }

    private function resolver(?array $field = null): JoinLevelResolver
    {
        $levels = [
            3 => MemberLevel::fromArray(['level_value' => 3, 'level_name' => '병사']),
            5 => MemberLevel::fromArray(['level_value' => 5, 'level_name' => '간부']),
            9 => MemberLevel::fromArray(['level_value' => 9, 'level_name' => '관리자', 'is_admin' => 1]),
        ];
        $service = $this->createMock(MemberLevelService::class);
        $service->method('findByValue')->willReturnCallback(fn(int $v) => $levels[$v] ?? null);

        $fieldService = $this->createMock(MemberFieldService::class);
        $fieldService->method('getField')->willReturn($field ?? [
            'field_id' => 12,
            'domain_id' => 1,
            'field_type' => 'select',
            'field_options' => json_encode(['병사', '간부', '관리', '없음']),
            'is_visible_signup' => 1,
            'is_admin_only' => 0,
        ]);

        return new JoinLevelResolver($service, $fieldService);
    }

    public function testMappedOptionGivesItsLevel(): void
    {
        $domain = $this->domain(12, ['병사' => 3, '간부' => 5]);

        $this->assertSame(5, $this->resolver()->resolve($domain, [12 => '간부'], 1));
        $this->assertSame(3, $this->resolver()->resolve($domain, ['12' => '병사'], 1));
    }

    public function testUnmappedOrMissingValueKeepsDefault(): void
    {
        $domain = $this->domain(12, ['병사' => 3]);

        $this->assertSame(1, $this->resolver()->resolve($domain, [12 => '민간인'], 1));
        $this->assertSame(1, $this->resolver()->resolve($domain, [], 1));
        $this->assertSame(1, $this->resolver()->resolve($domain, [12 => ['병사']], 1));
        $this->assertSame(1, $this->resolver()->resolve($this->domain(0, ['병사' => 3]), [12 => '병사'], 1));
        $this->assertSame(1, $this->resolver()->resolve(null, [12 => '병사'], 1));
    }

    public function testAdminOrUnknownLevelIsNeverGiven(): void
    {
        $domain = $this->domain(12, ['관리' => 9, '없음' => 7]);

        $this->assertSame(1, $this->resolver()->resolve($domain, [12 => '관리'], 1));
        $this->assertSame(1, $this->resolver()->resolve($domain, [12 => '없음'], 1));
    }

    public function testChangedOrIneligibleFieldKeepsDefault(): void
    {
        $domain = $this->domain(12, ['간부' => 5]);

        $this->assertSame(1, $this->resolver([
            'field_type' => 'select',
            'field_options' => json_encode(['병사']),
            'is_visible_signup' => 1,
            'is_admin_only' => 0,
        ])->resolve($domain, [12 => '간부'], 1));
        $this->assertSame(1, $this->resolver([
            'field_type' => 'select',
            'field_options' => json_encode(['간부']),
            'is_visible_signup' => 0,
            'is_admin_only' => 0,
        ])->resolve($domain, [12 => '간부'], 1));
        $this->assertSame(1, $this->resolver([
            'field_type' => 'radio',
            'field_options' => [['value' => '간부', 'label' => '간부']],
            'is_visible_signup' => 1,
            'is_admin_only' => 1,
        ])->resolve($domain, [12 => '간부'], 1));
        $this->assertSame(1, $this->resolver([])->resolve($domain, [12 => '간부'], 1));
    }
}
