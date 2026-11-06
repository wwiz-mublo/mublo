<?php
declare(strict_types=1);

namespace Mublo\Plugin\Innopay\Service;

use Mublo\Core\Crypto\EncryptionService;
use Mublo\Plugin\Innopay\Repository\InnopayConfigRepository;

class InnopayConfigService
{
    /** 저장 시 암호화하는 비밀값 */
    private const SENSITIVE_FIELDS = ['merchant_key', 'cancel_pwd'];

    private const ENC_PREFIX = 'enc:';

    /**
     * 결제창 결제수단. 목록에 없는 값(빈 값)을 보내면 이노페이가 결제수단 선택창을 띄운다.
     * 가상계좌는 입금통보(Noti) 연동 전이라 제외한다.
     */
    public const PAY_METHODS = [
        ''     => '결제수단 선택창 (구매자가 선택)',
        'CARD' => '신용카드',
        'EPAY' => '간편결제',
        'BANK' => '계좌이체',
    ];

    public function __construct(
        private InnopayConfigRepository $repository,
        private EncryptionService $crypto,
    ) {
    }

    public function getConfig(int $domainId): array
    {
        // 도메인별 자체 키 모델 — 다른 도메인 설정으로의 fallback 은 두지 않는다.
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

    /**
     * 비밀값 칸을 비워 저장하면 기존 값을 유지한다 — 화면에 비밀값을 다시 내보내지 않기 위해서다.
     */
    public function saveConfig(int $domainId, array $config): void
    {
        $allowed = array_keys($this->defaults());
        $filtered = array_intersect_key($config, array_flip($allowed));

        $current = $this->repository->find($domainId);
        $stored = $current ? (json_decode($current['config_data'] ?? '{}', true) ?: []) : [];

        foreach (self::SENSITIVE_FIELDS as $field) {
            $value = (string) ($filtered[$field] ?? '');
            if ($value === '') {
                $filtered[$field] = (string) ($stored[$field] ?? '');
                continue;
            }
            $filtered[$field] = self::ENC_PREFIX . $this->crypto->encrypt($value);
        }

        if (!array_key_exists((string) ($filtered['pay_method'] ?? ''), self::PAY_METHODS)) {
            $filtered['pay_method'] = 'CARD';
        }
        $filtered['auto_cancel'] = !empty($filtered['auto_cancel']) ? 1 : 0;

        $this->repository->save($domainId, $filtered);
    }

    public function isConfigured(int $domainId): bool
    {
        $config = $this->getConfig($domainId);
        return $config['mid'] !== '' && $config['merchant_key'] !== '';
    }

    public function defaults(): array
    {
        return [
            'mid' => '',
            'merchant_key' => '',
            'cancel_pwd' => '',
            'pay_method' => 'CARD',
            'default_buyer_email' => '',
            'logo_url' => '',
            // 승인은 됐는데 주문 반영이 실패하면 결제를 자동으로 취소한다
            'auto_cancel' => 1,
        ];
    }

    private function decryptValue(string $stored): string
    {
        if ($stored === '' || !str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }

        return $this->crypto->decrypt(substr($stored, strlen(self::ENC_PREFIX))) ?? '';
    }
}
