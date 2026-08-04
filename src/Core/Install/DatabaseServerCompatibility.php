<?php
declare(strict_types=1);

namespace Mublo\Core\Install;

/**
 * 설치 대상 MySQL 계열 서버의 최소 호환 버전을 판정한다.
 *
 * 코어 스키마가 JSON 타입/함수를 사용하므로 MySQL은 JSON이 도입된
 * 5.7.8 이상이 필요하다. MariaDB는 10.3 이상을 호환 하한으로 둔다.
 */
final class DatabaseServerCompatibility
{
    public const MIN_MYSQL = '5.7.8';
    public const MIN_MARIADB = '10.3.0';

    /**
     * @return array{
     *     supported: bool,
     *     engine: 'mysql'|'mariadb'|'unknown',
     *     version: string|null,
     *     minimum: string|null,
     *     message: string
     * }
     */
    public function inspect(string $serverVersion): array
    {
        $serverVersion = trim($serverVersion);
        $isMariaDb = stripos($serverVersion, 'MariaDB') !== false;
        $version = $isMariaDb
            ? $this->lastVersionBeforeMariaDb($serverVersion)
            : $this->firstVersion($serverVersion);

        if ($version === null) {
            return [
                'supported' => false,
                'engine' => 'unknown',
                'version' => null,
                'minimum' => null,
                'message' => "데이터베이스 서버 버전을 판별할 수 없습니다: {$serverVersion}",
            ];
        }

        $engine = $isMariaDb ? 'mariadb' : 'mysql';
        $minimum = $isMariaDb ? self::MIN_MARIADB : self::MIN_MYSQL;
        $supported = version_compare($version, $minimum, '>=');
        $label = $isMariaDb ? 'MariaDB' : 'MySQL';

        return [
            'supported' => $supported,
            'engine' => $engine,
            'version' => $version,
            'minimum' => $minimum,
            'message' => $supported
                ? "{$label} {$version}은(는) 지원되는 버전입니다."
                : "현재 데이터베이스는 {$label} {$version}입니다. Mublo는 MySQL "
                    . self::MIN_MYSQL . ' 이상 또는 MariaDB ' . self::MIN_MARIADB . ' 이상이 필요합니다.',
        ];
    }

    private function firstVersion(string $serverVersion): ?string
    {
        if (preg_match('/\d+\.\d+(?:\.\d+)?/', $serverVersion, $matches) !== 1) {
            return null;
        }

        return $this->normalize($matches[0]);
    }

    /**
     * 5.5.5-10.3.39-MariaDB 같은 호환 접두사가 있으면 실제 MariaDB 버전인
     * 마지막 숫자 버전을 선택한다.
     */
    private function lastVersionBeforeMariaDb(string $serverVersion): ?string
    {
        $mariaDbPosition = stripos($serverVersion, 'MariaDB');
        $prefix = $mariaDbPosition === false
            ? $serverVersion
            : substr($serverVersion, 0, $mariaDbPosition);

        if (preg_match_all('/\d+\.\d+(?:\.\d+)?/', $prefix, $matches) < 1) {
            return null;
        }

        $version = end($matches[0]);
        return is_string($version) ? $this->normalize($version) : null;
    }

    private function normalize(string $version): string
    {
        $parts = explode('.', $version);
        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }
}
