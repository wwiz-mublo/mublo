<?php

namespace Tests\Shop\Unit\Controller;

use Mublo\Contract\Member\PolicyDocument;
use Mublo\Packages\Shop\Controller\Front\CartController;
use Mublo\Service\Member\PolicyService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class CartControllerPolicySnapshotTest extends TestCase
{
    public function testCheckoutPolicyPinsRevisionAndContentHash(): void
    {
        $document = new PolicyDocument(
            policyId: 10,
            revisionId: 101,
            domainId: 7,
            version: '2.0',
            title: '구매 약관',
            content: '원문',
            contentHash: 'hash-v2',
            required: true,
            active: true,
            createdAt: '2026-07-25 12:00:00',
        );
        $policies = $this->createMock(PolicyService::class);
        $policies->method('activeDocuments')->with(7)->willReturn([$document]);
        $policies->method('renderDocument')->with($document, ['site' => 'config'])->willReturn('치환된 원문');

        $reflection = new ReflectionClass(CartController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('policyService')->setValue($controller, $policies);

        $result = $reflection->getMethod('loadCheckoutPolicies')->invoke(
            $controller,
            7,
            ['checkout_policies' => '[10]'],
            ['site' => 'config']
        );

        self::assertSame(101, $result[0]['revision_id']);
        self::assertSame('2.0', $result[0]['version']);
        self::assertSame('hash-v2', $result[0]['content_hash']);
        self::assertSame('치환된 원문', $result[0]['content']);
    }
}
