<?php

namespace Tests\Unit\Service\Member;

use Mublo\Entity\Member\Policy;
use Mublo\Repository\Member\PolicyRepository;
use Mublo\Repository\Member\PolicyRevisionRepository;
use Mublo\Service\Member\PolicyService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PolicyRevisionServiceTest extends TestCase
{
    private PolicyRepository&MockObject $policies;
    private PolicyRevisionRepository&MockObject $revisions;
    private PolicyService $service;

    protected function setUp(): void
    {
        $this->policies = $this->createMock(PolicyRepository::class);
        $this->revisions = $this->createMock(PolicyRevisionRepository::class);
        $this->service = new PolicyService($this->policies, $this->revisions);
    }

    public function testAgreementSnapshotPinsCurrentImmutableRevision(): void
    {
        $policy = $this->policy(required: true);
        $this->policies->method('getRegisterPolicies')->with(7)->willReturn([$policy]);
        $this->revisions->method('findCurrentForPolicies')->with([10])->willReturn([
            10 => $this->revision(101, '2.0', 'hash-v2'),
        ]);

        $result = $this->service->agreementSnapshot(7, [10]);

        self::assertTrue($result->isSuccess());
        self::assertSame([
            10 => ['revision_id' => 101, 'version' => '2.0', 'content_hash' => 'hash-v2'],
        ], $result->get('agreements'));
    }

    public function testFinalValidationRejectsAgreementWhenRevisionChanged(): void
    {
        $this->policies->method('getRegisterPolicies')->with(7)->willReturn([$this->policy(required: true)]);
        $this->revisions->method('findCurrentForPolicies')->willReturn([
            10 => $this->revision(102, '3.0', 'hash-v3'),
        ]);

        $result = $this->service->validateSignupAgreements(7, [
            10 => ['revision_id' => 101, 'version' => '2.0', 'content_hash' => 'hash-v2'],
        ]);

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('변경', $result->getMessage());
    }

    public function testFinalValidationAcceptsExactCurrentRevision(): void
    {
        $this->policies->method('getRegisterPolicies')->with(7)->willReturn([$this->policy(required: true)]);
        $this->revisions->method('findCurrentForPolicies')->willReturn([
            10 => $this->revision(102, '3.0', 'hash-v3'),
        ]);

        $result = $this->service->validateSignupAgreements(7, [
            10 => ['revision_id' => 102, 'version' => '3.0', 'content_hash' => 'hash-v3'],
        ]);

        self::assertTrue($result->isSuccess());
        self::assertSame(102, $result->get('agreements')[10]['revision_id']);
    }

    public function testContentCannotChangeWithoutVersionIncrease(): void
    {
        $this->policies->method('findById')->with(10)->willReturn($this->policy(required: true));

        $result = $this->service->update(10, [
            'title' => '이용약관',
            'content' => '변경된 본문',
            'version' => '1.0',
            'policy_type' => 'terms',
        ]);

        self::assertTrue($result->isFailure());
        self::assertStringContainsString('버전', $result->getMessage());
    }

    public function testFindDocumentReturnsCurrentRevisionWithinDomain(): void
    {
        $this->policies->method('findById')->with(10)->willReturn($this->policy(required: true));
        $this->revisions->method('findCurrentForPolicies')->with([10])->willReturn([
            10 => $this->revision(103, '4.0', 'hash-v4'),
        ]);

        $document = $this->service->findDocument(7, 10);

        self::assertSame(103, $document?->revisionId);
        self::assertSame('hash-v4', $document?->contentHash);
        self::assertSame('terms', $document?->type);
        self::assertSame('이용약관', $document?->typeLabel);
    }

    public function testFindDocumentRejectsPolicyFromAnotherDomain(): void
    {
        $this->policies->method('findById')->with(10)->willReturn($this->policy(required: true));
        $this->revisions->expects(self::never())->method('findCurrentForPolicies');

        self::assertNull($this->service->findDocument(99, 10));
    }

    public function testDeleteArchivesPolicyInsteadOfRemovingEvidence(): void
    {
        $policy = $this->policy(required: false, active: false);
        $this->policies->method('findById')->with(10)->willReturn($policy);
        $this->policies->expects(self::once())
            ->method('update')
            ->with(10, ['is_active' => 0, 'show_in_register' => 0]);
        $this->policies->expects(self::never())->method('delete');

        $result = $this->service->delete(10);

        self::assertTrue($result->isSuccess());
        self::assertStringContainsString('보관', $result->getMessage());
    }

    private function policy(bool $required, bool $active = true): Policy
    {
        return Policy::fromArray([
            'policy_id' => 10,
            'domain_id' => 7,
            'slug' => 'terms',
            'policy_type' => 'terms',
            'title' => '이용약관',
            'content' => '기존 본문',
            'version' => '1.0',
            'is_required' => $required ? 1 : 0,
            'is_active' => $active ? 1 : 0,
            'show_in_register' => 1,
        ]);
    }

    private function revision(int $revisionId, string $version, string $hash): array
    {
        return [
            'revision_id' => $revisionId,
            'policy_id' => 10,
            'domain_id' => 7,
            'version' => $version,
            'title' => '이용약관',
            'content' => '본문',
            'content_hash' => $hash,
            'published_at' => '2026-07-25 12:00:00',
        ];
    }
}
