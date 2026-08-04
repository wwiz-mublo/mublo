<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\SnsLogin\Repository\SnsLoginConfigRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

/**
 * SNS 로그인 플러그인 설정 서비스
 */
class SnsLoginConfigService
{
    private const PROVIDERS = ['naver', 'kakao', 'google'];

    private const DEFAULTS = [
        'naver'  => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
        'kakao'  => [
            'enabled' => false,
            'client_id' => '',
            'client_secret' => '',
            'admin_key' => '',
            'javascript_key' => '',
        ],
        'google' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
        'auto_register' => true,
        'register_level' => 1,
    ];

    private const ENCRYPTED_FIELDS = ['client_secret', 'admin_key'];

    /** @var array<int, array<string, mixed>> 요청 중 복호화된 도메인별 설정 */
    private array $configCache = [];

    public function __construct(
        private SnsLoginConfigRepository $repo,
        private SensitiveValueCodecInterface $encryption,
    ) {}

    public function getConfig(int $domainId): array
    {
        if (array_key_exists($domainId, $this->configCache)) {
            return $this->configCache[$domainId];
        }

        $saved = $this->repo->findByDomain($domainId);
        $config = array_replace_recursive(self::DEFAULTS, $saved ?? []);

        // 비밀 필드 복호화
        foreach (self::PROVIDERS as $name) {
            if (!isset($config[$name]) || !is_array($config[$name])) {
                continue;
            }
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if (!empty($config[$name][$field])) {
                    $decrypted = $this->encryption->decrypt($config[$name][$field]);
                    if ($decrypted !== null) {
                        $config[$name][$field] = $decrypted;
                    }
                }
            }
        }

        return $this->configCache[$domainId] = $config;
    }

    public function getProviderConfig(int $domainId, string $provider): array
    {
        $config = $this->getConfig($domainId);
        return $config[$provider] ?? [];
    }

    public function getEnabledMap(int $domainId): array
    {
        $config = $this->getConfig($domainId);
        $map    = [];
        foreach (self::PROVIDERS as $name) {
            $p       = $config[$name] ?? [];
            $enabled = !empty($p['enabled']) && !empty($p['client_id']);
            // 네이버/구글은 client_secret도 필수
            if ($name !== 'kakao') {
                $enabled = $enabled && !empty($p['client_secret']);
            }
            $map[$name] = $enabled;
        }
        return $map;
    }

    public function save(int $domainId, array $formData): Result
    {
        $config = $this->getConfig($domainId);

        foreach (self::PROVIDERS as $name) {
            if (!isset($formData[$name])) {
                continue;
            }
            $p       = $formData[$name];
            $enabled = !empty($p['enabled']);

            if ($enabled) {
                if (empty(trim($p['client_id'] ?? ''))) {
                    return Result::failure("{$name} 활성화 시 Client ID가 필요합니다.");
                }
                if ($name === 'kakao' && empty(trim($p['admin_key'] ?? ''))) {
                    return Result::failure('카카오 활성화 시 Admin Key가 필요합니다.');
                }
                if ($name !== 'kakao' && empty(trim($p['client_secret'] ?? ''))) {
                    return Result::failure("{$name} 활성화 시 Client Secret이 필요합니다.");
                }
            }

            $config[$name]['enabled']   = $enabled;
            $config[$name]['client_id'] = trim($p['client_id'] ?? '');

            if ($name === 'kakao') {
                $config[$name]['client_secret']  = trim($p['client_secret'] ?? '');
                $config[$name]['admin_key']      = trim($p['admin_key'] ?? '');
                $config[$name]['javascript_key'] = trim($p['javascript_key'] ?? '');
            } else {
                $config[$name]['client_secret'] = trim($p['client_secret'] ?? '');
            }
        }

        $config['auto_register']  = !empty($formData['auto_register']);
        $config['register_level'] = (int) ($formData['register_level'] ?? 1);

        // 비밀 필드 암호화 후 저장
        $toSave = $config;
        foreach (self::PROVIDERS as $name) {
            if (!isset($toSave[$name]) || !is_array($toSave[$name])) {
                continue;
            }
            foreach (self::ENCRYPTED_FIELDS as $field) {
                if (!empty($toSave[$name][$field])) {
                    $toSave[$name][$field] = $this->encryption->encrypt($toSave[$name][$field]);
                }
            }
        }

        $this->repo->save($domainId, $toSave);
        $this->configCache[$domainId] = $config;

        return Result::success('설정이 저장되었습니다.');
    }
}
