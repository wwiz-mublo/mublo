<?php
namespace Tests\Unit\Service\AI;

use Mublo\Core\Crypto\EncryptionService;
use Mublo\Repository\AI\DomainAiConfigRepository;
use Mublo\Service\AI\DomainAiConfigService;
use PHPUnit\Framework\TestCase;

class DomainAiConfigServiceTest extends TestCase
{
    public function testNewKeyIsEncryptedAndNeverReturned(): void
    {
        $repository = $this->createMock(DomainAiConfigRepository::class);
        $encryption = $this->createMock(EncryptionService::class);

        $repository->expects($this->exactly(2))->method('findByDomainId')->with(7)
            ->willReturnOnConsecutiveCalls(null, [
                'provider' => 'openai', 'model' => 'gpt-5.6-terra',
                'encrypted_api_key' => 'ciphertext', 'is_enabled' => 1,
                'daily_request_limit' => 50,
            ]);
        $encryption->expects($this->once())->method('encrypt')->with('secret-key')->willReturn('ciphertext');
        $repository->expects($this->once())->method('save')->with(7, $this->callback(
            fn (array $data): bool => $data['encrypted_api_key'] === 'ciphertext' && $data['is_enabled'] === true
        ));

        $result = (new DomainAiConfigService($repository, $encryption))->save(7, [
            'provider' => 'openai', 'model' => 'gpt-5.6-terra',
            'api_key' => 'secret-key', 'is_enabled' => '1', 'daily_request_limit' => 50,
        ]);

        $this->assertTrue($result['api_key_configured']);
        $this->assertArrayNotHasKey('api_key', $result);
        $this->assertArrayNotHasKey('encrypted_api_key', $result);
    }

    public function testBlankKeyPreservesStoredCiphertext(): void
    {
        $stored = [
            'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
            'encrypted_api_key' => 'existing-ciphertext', 'is_enabled' => 1,
            'daily_request_limit' => 20,
        ];
        $repository = $this->createMock(DomainAiConfigRepository::class);
        $encryption = $this->createMock(EncryptionService::class);
        $repository->method('findByDomainId')->willReturn($stored);
        $encryption->expects($this->never())->method('encrypt');
        $repository->expects($this->once())->method('save')->with(3, $this->callback(
            fn (array $data): bool => $data['encrypted_api_key'] === 'existing-ciphertext'
        ));

        (new DomainAiConfigService($repository, $encryption))->save(3, [
            'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
            'api_key' => '', 'is_enabled' => true, 'daily_request_limit' => 20,
        ]);
    }

    public function testArbitraryModelIsRejected(): void
    {
        $service = new DomainAiConfigService(
            $this->createMock(DomainAiConfigRepository::class),
            $this->createMock(EncryptionService::class)
        );

        $this->expectException(\InvalidArgumentException::class);
        $service->save(1, [
            'provider' => 'openai', 'model' => 'attacker-controlled-model',
            'api_key' => 'key', 'is_enabled' => true, 'daily_request_limit' => 50,
        ]);
    }

    public function testSupportedPreviousGenerationModelCanBeSelected(): void
    {
        $stored = [
            'provider' => 'openai', 'model' => 'gpt-4.1-mini',
            'encrypted_api_key' => 'ciphertext', 'is_enabled' => 1,
            'daily_request_limit' => 50,
        ];
        $repository = $this->createMock(DomainAiConfigRepository::class);
        $repository->method('findByDomainId')->willReturn($stored);
        $repository->expects($this->once())->method('save')->with(1, $this->callback(
            fn (array $data): bool => $data['model'] === 'gpt-4.1-mini'
        ));

        (new DomainAiConfigService($repository, $this->createMock(EncryptionService::class)))->save(1, [
            'provider' => 'openai', 'model' => 'gpt-4.1-mini',
            'api_key' => '', 'is_enabled' => true, 'daily_request_limit' => 50,
        ]);
    }

    public function testChangingProviderRequiresNewKey(): void
    {
        $repository = $this->createMock(DomainAiConfigRepository::class);
        $repository->method('findByDomainId')->willReturn([
            'provider' => 'openai', 'model' => 'gpt-5.6-terra',
            'encrypted_api_key' => 'ciphertext', 'is_enabled' => 1, 'daily_request_limit' => 50,
        ]);
        $service = new DomainAiConfigService($repository, $this->createMock(EncryptionService::class));

        $this->expectException(\InvalidArgumentException::class);
        $service->save(1, [
            'provider' => 'anthropic', 'model' => 'claude-sonnet-5',
            'api_key' => '', 'is_enabled' => true, 'daily_request_limit' => 50,
        ]);
    }
}
