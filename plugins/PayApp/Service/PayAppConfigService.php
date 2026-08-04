<?php
declare(strict_types=1);

namespace Mublo\Plugin\PayApp\Service;

use Mublo\Plugin\PayApp\Repository\PayAppConfigRepository;
use Mublo\Core\Crypto\EncryptionService;

class PayAppConfigService
{
    /** API 인증에 사용되는 민감 필드 — 저장 시 AES-256-GCM 암호화 */
    private const SENSITIVE_FIELDS = ['linkkey', 'linkval'];

    /** 암호문임을 표시하는 prefix. 없으면 legacy 평문으로 간주한다. */
    private const ENC_PREFIX = 'enc:';

    private PayAppConfigRepository $repository;
    private EncryptionService $crypto;

    public function __construct(PayAppConfigRepository $repository, EncryptionService $crypto)
    {
        $this->repository = $repository;
        $this->crypto = $crypto;
    }

    public function getConfig(int $domainId): array
    {
        // 도메인별 자체 키 모델 — 각 사이트가 자기 PG 계약의 키를 등록한다.
        // 다른 도메인(SUPER 포함) 설정으로의 fallback은 두지 않는다.
        // 키 미등록 도메인은 isConfigured()=false로 결제가 비활성이어야 한다.
        $row = $this->repository->find($domainId);

        if (!$row) {
            return $this->defaults();
        }

        $config = json_decode($row['config_data'] ?? '{}', true) ?: [];

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($config[$field]) && is_string($config[$field])) {
                $config[$field] = $this->decryptValue($config[$field]);
            }
        }

        return array_merge($this->defaults(), $config);
    }

    public function saveConfig(int $domainId, array $config): void
    {
        $allowed = ['userid', 'linkkey', 'linkval', 'mode', 'feedbackurl_path', 'feedback_ip_allowlist'];
        $filtered = array_intersect_key($config, array_flip($allowed));

        foreach (self::SENSITIVE_FIELDS as $field) {
            if (isset($filtered[$field]) && $filtered[$field] !== '') {
                $filtered[$field] = self::ENC_PREFIX . $this->crypto->encrypt((string) $filtered[$field]);
            }
        }

        $this->repository->save($domainId, $filtered);
    }

    public function isConfigured(int $domainId): bool
    {
        $config = $this->getConfig($domainId);
        return !empty($config['userid']) && !empty($config['linkkey']) && !empty($config['linkval']);
    }

    private function defaults(): array
    {
        return [
            'userid' => '',
            'linkkey' => '',
            'linkval' => '',
            'mode' => 'live',
            'feedbackurl_path' => '/pay-app/callback/feedback',
            'feedback_ip_allowlist' => '',
        ];
    }

    /**
     * 암호문이면 복호화, 평문(legacy)이면 그대로 반환.
     * 복호화 실패 시 빈 문자열 반환(키 손상/마이그레이션 오류 노출 방지).
     */
    private function decryptValue(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }

        $cipher = substr($stored, strlen(self::ENC_PREFIX));
        $plain = $this->crypto->decrypt($cipher);
        return $plain ?? '';
    }
}
