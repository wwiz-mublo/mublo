<?php

namespace Mublo\Plugin\VisitorStats\Service;

/**
 * GeoIpService
 *
 * MaxMind GeoLite2-City DB로 IP → 국가/도시를 조회한다.
 *
 * 선택 기능이다. 아래 두 가지가 모두 갖춰져야 동작하며,
 * 하나라도 없으면 빈 값을 반환해 방문 수집은 그대로 진행된다.
 *  1. 라이브러리: composer require geoip2/geoip2
 *  2. DB 파일:   storage/geoip/GeoLite2-City.mmdb
 *     (MaxMind 무료 계정 가입 후 다운로드. GeoLite2 EULA상 재배포 불가 — 저장소/배포본에 포함하지 않는다.
 *      월 1회 이상 갱신 권장: MaxMind geoipupdate 도구)
 */
class GeoIpService
{
    private const READER_CLASS = 'GeoIp2\\Database\\Reader';
    private const EMPTY = ['country_code' => '', 'city' => ''];

    /** @var object|null GeoIp2\Database\Reader */
    private ?object $reader = null;
    private bool $initialized = false;

    public function __construct(private string $dbPath) {}

    /**
     * IP → ['country_code' => 'KR', 'city' => 'Seoul']
     *
     * 지명은 영문이다 (GeoLite2에는 한국어 지명 데이터가 없음).
     *
     * @return array{country_code: string, city: string}
     */
    public function lookup(string $ip): array
    {
        if ($ip === '') {
            return self::EMPTY;
        }

        $reader = $this->getReader();
        if ($reader === null) {
            return self::EMPTY;
        }

        try {
            $record = $reader->city($ip);

            $region = (string) ($record->mostSpecificSubdivision->name ?? '');
            $city = (string) ($record->city->name ?? '');

            // 시/도 + 구/시 결합 (같으면 하나만)
            if ($region !== '' && $city !== '' && $region !== $city) {
                $displayCity = $region . ' ' . $city;
            } else {
                $displayCity = $city !== '' ? $city : $region;
            }

            return [
                'country_code' => (string) ($record->country->isoCode ?? ''),
                'city'         => mb_substr($displayCity, 0, 100),
            ];
        } catch (\Throwable) {
            // 사설 IP·미등록 IP 등 조회 실패는 정상 흐름
            return self::EMPTY;
        }
    }

    /**
     * 라이브러리와 DB 파일이 모두 준비되었는지
     */
    public function isAvailable(): bool
    {
        return class_exists(self::READER_CLASS) && is_file($this->dbPath);
    }

    private function getReader(): ?object
    {
        if ($this->initialized) {
            return $this->reader;
        }
        $this->initialized = true;

        if (!$this->isAvailable()) {
            return null;
        }

        try {
            $class = self::READER_CLASS;
            $this->reader = new $class($this->dbPath);
        } catch (\Throwable) {
            $this->reader = null;
        }

        return $this->reader;
    }
}
