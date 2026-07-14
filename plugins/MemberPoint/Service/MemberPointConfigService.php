<?php
namespace Mublo\Plugin\MemberPoint\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\MemberPoint\Repository\MemberPointConfigRepository;

class MemberPointConfigService
{
    private const DEFAULTS = [
        'member' => [
            'signup'   => ['enabled' => true,  'point' => 1000],
            'level_up' => ['enabled' => false, 'levels' => []],
        ],
    ];

    private array $domainCache = [];

    public function __construct(private MemberPointConfigRepository $repo) {}

    // =========================================================================
    // 도메인 기본 설정
    // =========================================================================

    /**
     * 도메인 전체 설정 반환
     */
    public function getConfig(int $domainId): array
    {
        if (isset($this->domainCache[$domainId])) {
            return $this->domainCache[$domainId];
        }

        $saved = $this->repo->findByDomain($domainId);

        if ($saved !== null) {
            $saved = $this->migrateOldFormat($saved);
        }

        $config = array_replace_recursive(self::DEFAULTS, $saved ?? []);
        $this->domainCache[$domainId] = $config;

        return $config;
    }

    /**
     * 도메인 기본 설정에서 특정 액션 설정 반환
     */
    public function getActionConfig(int $domainId, string $action): array
    {
        $config = $this->getConfig($domainId);

        if (isset($config['member'][$action])) {
            return $config['member'][$action];
        }

        return ['enabled' => false, 'point' => 0];
    }

    /**
     * 레벨업 설정 반환
     */
    public function getLevelUpConfig(int $domainId): array
    {
        $config = $this->getConfig($domainId);
        return $config['member']['level_up'] ?? ['enabled' => false, 'levels' => []];
    }

    /**
     * 회원 포인트 설정 저장
     */
    public function saveMember(int $domainId, array $formData): Result
    {
        $config = $this->getConfig($domainId);

        $signup = $formData['member']['signup'] ?? [];
        $config['member']['signup'] = [
            'enabled' => !empty($signup['enabled']),
            'point'   => max(0, (int) ($signup['point'] ?? self::DEFAULTS['member']['signup']['point'])),
        ];

        $levelUp = $formData['member']['level_up'] ?? [];
        $levels  = [];
        foreach (($levelUp['levels'] ?? []) as $levelValue => $point) {
            $p = max(0, (int) $point);
            if ($p > 0) {
                $levels[(string) $levelValue] = $p;
            }
        }
        $config['member']['level_up'] = [
            'enabled' => !empty($levelUp['enabled']),
            'levels'  => $levels,
        ];

        $this->repo->save($domainId, $config);
        unset($this->domainCache[$domainId]);

        return Result::success('회원포인트 설정이 저장되었습니다.');
    }

    // =========================================================================
    // 라벨
    // =========================================================================

    public static function getActionLabels(): array
    {
        return [
            'member' => [
                'signup'   => '회원가입',
                'level_up' => '레벨업',
            ],
        ];
    }

    // =========================================================================
    // 캐시
    // =========================================================================

    public function clearCache(): void
    {
        $this->domainCache = [];
    }

    // =========================================================================
    // 구 포맷 마이그레이션
    // =========================================================================

    /**
     * 구 포맷(flat) → 신 포맷(카테고리) 변환
     */
    private function migrateOldFormat(array $saved): array
    {
        if (isset($saved['member'])) {
            return $saved;
        }

        // 구 포맷: {'signup': {...}, ...}
        $new = ['member' => []];

        foreach ($saved as $action => $config) {
            if (in_array($action, ['signup', 'level_up'], true)) {
                $new['member'][$action] = $config;
            }
        }

        return $new;
    }
}
