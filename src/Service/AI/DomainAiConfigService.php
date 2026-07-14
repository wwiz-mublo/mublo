<?php
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use Mublo\Core\Crypto\EncryptionService;
use Mublo\Repository\AI\DomainAiConfigRepository;

class DomainAiConfigService
{
    private array $config;

    public function __construct(
        private DomainAiConfigRepository $repository,
        private EncryptionService $encryption
    ) {
        $this->config = AiConfig::load();
    }

    public function publicConfig(int $domainId): array
    {
        $stored = $this->repository->findByDomainId($domainId);

        return [
            'provider' => $stored['provider'] ?? 'openai',
            'model' => $stored['model'] ?? $this->config['providers']['openai']['default_model'],
            'is_enabled' => (bool) ($stored['is_enabled'] ?? false),
            'daily_request_limit' => (int) ($stored['daily_request_limit'] ?? $this->config['default_daily_request_limit']),
            'api_key_configured' => !empty($stored['encrypted_api_key']),
            'providers' => $this->providerOptions(),
        ];
    }

    public function save(int $domainId, array $input): array
    {
        $provider = strtolower(trim((string) ($input['provider'] ?? '')));
        if (!isset($this->config['providers'][$provider])) {
            throw new \InvalidArgumentException('지원하지 않는 AI 공급자입니다.');
        }

        $model = trim((string) ($input['model'] ?? ''));
        if (!in_array($model, $this->config['providers'][$provider]['models'], true)) {
            throw new \InvalidArgumentException('선택할 수 없는 AI 모델입니다.');
        }

        $limit = (int) ($input['daily_request_limit'] ?? $this->config['default_daily_request_limit']);
        if ($limit < 1 || $limit > (int) $this->config['max_daily_request_limit']) {
            throw new \InvalidArgumentException('일일 요청 한도가 허용 범위를 벗어났습니다.');
        }

        $stored = $this->repository->findByDomainId($domainId);
        $encryptedKey = $stored['encrypted_api_key'] ?? null;
        $newKey = trim((string) ($input['api_key'] ?? ''));
        if ($stored && ($stored['provider'] ?? '') !== $provider && $newKey === '') {
            throw new \InvalidArgumentException('AI 공급자를 변경할 때는 해당 공급자의 API 키를 새로 입력해주세요.');
        }
        if ($newKey !== '') {
            $encryptedKey = $this->encryption->encrypt($newKey);
        }

        $enabled = filter_var($input['is_enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if ($enabled && empty($encryptedKey)) {
            throw new \InvalidArgumentException('AI 기능을 켜려면 API 키가 필요합니다.');
        }

        $this->repository->save($domainId, [
            'provider' => $provider,
            'model' => $model,
            'encrypted_api_key' => $encryptedKey,
            'is_enabled' => $enabled,
            'daily_request_limit' => $limit,
        ]);

        return $this->publicConfig($domainId);
    }

    public function removeKey(int $domainId): array
    {
        $this->repository->removeKey($domainId);
        return $this->publicConfig($domainId);
    }

    /** @return array{provider:string,model:string,api_key:string,daily_request_limit:int} */
    public function runtimeConfig(int $domainId): array
    {
        $stored = $this->repository->findByDomainId($domainId);
        if (!$stored || !$stored['is_enabled'] || empty($stored['encrypted_api_key'])) {
            throw new \RuntimeException('이 도메인에 AI 기능이 설정되지 않았습니다.');
        }
        if (!isset($this->config['providers'][$stored['provider']])
            || !in_array($stored['model'], $this->config['providers'][$stored['provider']]['models'], true)) {
            throw new \RuntimeException('저장된 AI 공급자 또는 모델 설정이 더 이상 유효하지 않습니다.');
        }

        $apiKey = $this->encryption->decrypt($stored['encrypted_api_key']);
        if ($apiKey === null || $apiKey === '') {
            throw new \RuntimeException('저장된 API 키를 복호화할 수 없습니다.');
        }

        return [
            'provider' => $stored['provider'],
            'model' => $stored['model'],
            'api_key' => $apiKey,
            'daily_request_limit' => (int) $stored['daily_request_limit'],
        ];
    }

    private function providerOptions(): array
    {
        $result = [];
        foreach ($this->config['providers'] as $id => $provider) {
            $result[$id] = [
                'label' => $provider['label'],
                'default_model' => $provider['default_model'],
                'models' => $provider['models'],
            ];
        }
        return $result;
    }
}
