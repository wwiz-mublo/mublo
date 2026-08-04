<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Repository;

use Mublo\Repository\BaseRepository;

/**
 * 가격비교 채널 설정 Repository
 *
 * shop_price_compare_channels 테이블 접근 담당. 행이 없으면 꺼진 것으로 본다.
 *
 * 금지:
 * - 비즈니스 로직 (Service 담당)
 */
class PriceCompareChannelRepository extends BaseRepository
{
    protected string $table = 'shop_price_compare_channels';
    protected string $entityClass = '';

    /**
     * 도메인의 채널 설정 전부.
     *
     * @return array<string, array{is_active: bool, settings: string|null}> channel_code 기준
     */
    public function findByDomain(int $domainId): array
    {
        $rows = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $code = (string) ($row['channel_code'] ?? '');
            if ($code === '') {
                continue;
            }

            $map[$code] = [
                'is_active' => (bool) ($row['is_active'] ?? 0),
                'settings'  => $row['settings'] ?? null,
            ];
        }

        return $map;
    }

    /**
     * 채널 설정 저장 (없으면 생성).
     *
     * $settingsPatch 는 기존 settings 에 병합한다. 화면이 다루지 않는 항목을 조용히
     * 지우면 운영자는 무엇이 사라졌는지 알 수 없다. 값을 null 로 주면 그 항목만 지운다.
     *
     * @param array<string, mixed> $settingsPatch
     */
    public function save(int $domainId, string $channelCode, bool $isActive, array $settingsPatch = []): void
    {
        $existing = $this->getDb()->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('channel_code', '=', $channelCode)
            ->first();

        $settings = [];
        if ($existing && !empty($existing['settings'])) {
            $decoded = json_decode((string) $existing['settings'], true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        }

        foreach ($settingsPatch as $key => $value) {
            if ($value === null) {
                unset($settings[$key]);
                continue;
            }
            $settings[$key] = $value;
        }

        $encoded = $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE);

        if ($existing) {
            $this->getDb()->table($this->table)
                ->where('channel_id', '=', $existing['channel_id'])
                ->update([
                    'is_active'  => $isActive ? 1 : 0,
                    'settings'   => $encoded,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            return;
        }

        $this->getDb()->table($this->table)->insert([
            'domain_id'    => $domainId,
            'channel_code' => $channelCode,
            'is_active'    => $isActive ? 1 : 0,
            'settings'     => $encoded,
            'created_at'   => date('Y-m-d H:i:s'),
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }
}
