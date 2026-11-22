<?php
declare(strict_types=1);

namespace Tests\AiSiteEditor\Unit;

use Mublo\Plugin\AiSiteEditor\Service\ProtocolCatalog;
use Mublo\Plugin\AiSiteEditor\Service\ProtocolValidationException;
use Mublo\Plugin\AiSiteEditor\Service\ProtocolValidator;
use PHPUnit\Framework\TestCase;

final class ProtocolValidatorTest extends TestCase
{
    public function testItRejectsAForwardOperationReference(): void
    {
        $protocol = __DIR__ . '/../../resources/protocol';
        $catalog = new ProtocolCatalog($protocol);
        $validator = new ProtocolValidator($catalog->tools());
        $plan = json_decode((string) file_get_contents($protocol . '/fixtures/invalid-forward-reference.json'), true, 512, JSON_THROW_ON_ERROR);

        try {
            $validator->validatePlan($plan);
            self::fail('Expected reference validation failure.');
        } catch (ProtocolValidationException $error) {
            self::assertSame('PLAN_REFERENCE_INVALID', $error->errorCode);
        }
    }
}
