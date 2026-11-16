<?php
/**
 * packages/Shop/tests/Unit/Service/ShopConfigServiceLevelSettingsTest.php
 *
 * 쇼핑몰 설정의 등급별 설정(포인트 한도·할인·적립) 저장 회귀 테스트
 *
 * - 세 키가 화이트리스트에서 버려지지 않고 저장소로 넘어가야 한다
 * - 값 형식을 정리한다: 등급 ID 키, 포인트 min/max 는 0 이상 정수,
 *   할인/적립은 PERCENTAGE|FIXED + 0 이상 소수
 */

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\ShopConfigRepository;
use Mublo\Packages\Shop\Service\ShopConfigService;
use Tests\Shop\TestCase;

class ShopConfigServiceLevelSettingsTest extends TestCase
{
    /** 저장소로 넘어간 데이터 */
    private array $saved = [];

    private function service(): ShopConfigService
    {
        $repository = $this->createMock(ShopConfigRepository::class);
        $repository->method('saveConfig')->willReturnCallback(function (int $domainId, array $data) {
            $this->saved = $data;
            return true;
        });

        return new ShopConfigService($repository);
    }

    public function testLevelSettingKeysAreSaved(): void
    {
        $result = $this->service()->saveConfig(1, [
            'point_level_settings' => json_encode([3 => ['min' => '100', 'max' => '5000']]),
            'discount_level_settings' => json_encode([3 => ['type' => 'FIXED', 'value' => '1000']]),
            'reward_level_settings' => json_encode([3 => ['type' => 'PERCENTAGE', 'value' => '2.5']]),
        ]);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayHasKey('point_level_settings', $this->saved);
        $this->assertArrayHasKey('discount_level_settings', $this->saved);
        $this->assertArrayHasKey('reward_level_settings', $this->saved);

        $this->assertSame(
            ['3' => ['min' => 100, 'max' => 5000]],
            json_decode($this->saved['point_level_settings'], true)
        );
        $this->assertSame(
            ['3' => ['type' => 'FIXED', 'value' => 1000]],
            json_decode($this->saved['discount_level_settings'], true)
        );
        $this->assertSame(
            ['3' => ['type' => 'PERCENTAGE', 'value' => 2.5]],
            json_decode($this->saved['reward_level_settings'], true)
        );
    }

    public function testArrayInputIsAcceptedAndEncoded(): void
    {
        $this->service()->saveConfig(1, [
            'point_level_settings' => [5 => ['min' => 0, 'max' => 30000]],
        ]);

        $this->assertIsString($this->saved['point_level_settings']);
        $this->assertSame(
            ['5' => ['min' => 0, 'max' => 30000]],
            json_decode($this->saved['point_level_settings'], true)
        );
    }

    public function testInvalidValuesAreNormalized(): void
    {
        $this->service()->saveConfig(1, [
            'point_level_settings' => json_encode([
                2 => ['min' => '-100', 'max' => 'abc'],
                'x' => ['min' => 1, 'max' => 2],   // 등급 ID 아님
                0 => ['min' => 1, 'max' => 2],     // 등급 ID 아님
                4 => 'not-array',
            ]),
            'discount_level_settings' => json_encode([
                2 => ['type' => 'UNKNOWN', 'value' => '-5'],
            ]),
        ]);

        $this->assertSame(
            ['2' => ['min' => 0, 'max' => 0]],
            json_decode($this->saved['point_level_settings'], true)
        );
        $this->assertSame(
            ['2' => ['type' => 'PERCENTAGE', 'value' => 0]],
            json_decode($this->saved['discount_level_settings'], true)
        );
    }

    public function testBrokenJsonBecomesEmptyObject(): void
    {
        $this->service()->saveConfig(1, [
            'reward_level_settings' => 'not-json',
        ]);

        $this->assertSame('{}', $this->saved['reward_level_settings']);
    }
}
