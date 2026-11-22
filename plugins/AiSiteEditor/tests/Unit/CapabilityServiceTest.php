<?php
declare(strict_types=1);

namespace Tests\AiSiteEditor\Unit;

use Mublo\Plugin\AiSiteEditor\Service\CapabilityService;
use Mublo\Plugin\AiSiteEditor\Service\ProtocolCatalog;
use PHPUnit\Framework\TestCase;

final class CapabilityServiceTest extends TestCase
{
    public function testItPublishesVersionedCapabilitiesWithoutClaimingMissingContracts(): void
    {
        $catalog = new ProtocolCatalog(__DIR__ . '/../../resources/protocol');
        $capabilities = (new CapabilityService($catalog))->describe();

        self::assertSame('1.0.0', $capabilities['protocol_version']);
        self::assertSame('0.2.0', $capabilities['plugin_version']);
        self::assertContains('change_sets', $capabilities['features']);
        self::assertContains('restore_guards', $capabilities['features']);
        self::assertIsArray($capabilities['tools']);
        self::assertContains('RESTORE_RESOURCE_CHANGED', $capabilities['errors']);
        self::assertSame(50, $capabilities['limits']['operations_per_plan']);
    }
}
