<?php
namespace Mublo\Service\System;

/**
 * InstallIdProvider
 *
 * 설치 고유 식별자를 공급한다.
 *
 * 블록 킷의 clone 모드가 "다른 설치에 적용하려는가"를 판별하는 데 쓴다.
 * 도메인명처럼 유추 가능한 값을 쓰지 않는 이유는, 배포 파일에 출처 정보가
 * 새기 때문이다. 최초 요청 시 랜덤 UUID를 만들어 저장하고, 밖으로는
 * 그 해시만 노출한다.
 *
 * 저장 위치는 storage/install-id — installed.lock 과 같은 계층의 설치 전역
 * 마커이므로 도메인 종속이 아니고, 마이그레이션도 필요 없다.
 */
class InstallIdProvider
{
    private const FILE_NAME = 'install-id';

    private ?string $cachedHash = null;

    /**
     * @param string|null $storagePath 저장 디렉토리. null이면 MUBLO_STORAGE_PATH
     */
    public function __construct(
        private ?string $storagePath = null
    ) {
    }

    /**
     * 설치 식별자 해시. 파일이 없으면 최초 생성한다.
     *
     * @return string|null 저장소에 쓸 수 없으면 null (블록 킷은 source_install 없이 진행)
     */
    public function getHash(): ?string
    {
        if ($this->cachedHash !== null) {
            return $this->cachedHash;
        }

        $uuid = $this->readOrCreateUuid();
        if ($uuid === null) {
            return null;
        }

        return $this->cachedHash = hash('sha256', $uuid);
    }

    /**
     * 블록 킷의 source_install 값이 현재 설치의 것인지 확인한다.
     */
    public function matches(?string $sourceInstall): bool
    {
        if ($sourceInstall === null || $sourceInstall === '') {
            return false;
        }

        $current = $this->getHash();

        return $current !== null && hash_equals($current, $sourceInstall);
    }

    private function readOrCreateUuid(): ?string
    {
        $path = $this->filePath();
        if ($path === null) {
            return null;
        }

        if (is_file($path)) {
            $uuid = trim((string) @file_get_contents($path));
            if ($uuid !== '') {
                return $uuid;
            }
        }

        $uuid = $this->generateUuid();

        // 동시 요청이 겹쳐도 먼저 쓴 쪽의 값을 쓴다 (LOCK_EX + 재확인)
        if (@file_put_contents($path, $uuid, LOCK_EX) === false) {
            return null;
        }

        return trim((string) @file_get_contents($path)) ?: $uuid;
    }

    private function filePath(): ?string
    {
        $base = $this->storagePath ?? (defined('MUBLO_STORAGE_PATH') ? MUBLO_STORAGE_PATH : null);

        return $base === null ? null : $base . '/' . self::FILE_NAME;
    }

    private function generateUuid(): string
    {
        $bytes = random_bytes(16);

        // RFC 4122 v4
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
