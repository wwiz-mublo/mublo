<?php
declare(strict_types=1);

namespace Mublo\Infrastructure\Crypto;

/**
 * CryptoManager
 *
 * 암호화/복호화 전담 인프라 클래스
 * - AES-256-GCM 인증 암호화 (신규 암호문)
 * - AES-256-CBC 복호화 (v2 이전 암호문 하위호환)
 * - 키 생성
 * - 해시 생성
 *
 * 사용처:
 * - 데이터베이스 비밀번호 암호화
 * - 쿠키 암호화
 * - 토큰 생성
 */
class CryptoManager
{
    /** 신규 암호화 알고리즘 (인증 태그 포함) */
    private const CIPHER_GCM = 'aes-256-gcm';
    private const GCM_IV_LENGTH = 12;
    private const GCM_TAG_LENGTH = 16;

    /** v2 암호문 식별 접두사 */
    private const V2_PREFIX = 'v2:';

    /** 레거시 알고리즘 (하위호환 복호화 전용) */
    private const LEGACY_CIPHER = 'AES-256-CBC';
    private const LEGACY_IV_LENGTH = 16;

    /**
     * 데이터 암호화 (AES-256-GCM, 인증 태그 포함)
     *
     * @param string $data 암호화할 데이터
     * @param string $key 암호화 키
     * @return string "v2:" + base64(IV + Tag + 암호문)
     */
    public function encrypt(string $data, string $key): string
    {
        $key = $this->normalizeKey($key);
        $iv = random_bytes(self::GCM_IV_LENGTH);
        $tag = '';

        $ciphertext = openssl_encrypt(
            $data,
            self::CIPHER_GCM,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::GCM_TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new \RuntimeException('암호화에 실패했습니다.');
        }

        return self::V2_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * 데이터 복호화
     *
     * v2 접두사면 GCM, 아니면 레거시 CBC 경로로 복호화한다.
     *
     * @param string $encrypted 암호문
     * @param string $key 복호화 키
     * @return string 복호화된 데이터 (실패/변조 시 빈 문자열)
     */
    public function decrypt(string $encrypted, string $key): string
    {
        if (str_starts_with($encrypted, self::V2_PREFIX)) {
            return $this->decryptGcm(substr($encrypted, strlen(self::V2_PREFIX)), $key);
        }

        return $this->decryptLegacyCbc($encrypted, $key);
    }

    /**
     * GCM 복호화 (인증 태그 검증 포함)
     */
    private function decryptGcm(string $payload, string $key): string
    {
        $key = $this->normalizeKey($key);
        $decoded = base64_decode($payload, true);

        if ($decoded === false || strlen($decoded) < self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, self::GCM_IV_LENGTH);
        $tag = substr($decoded, self::GCM_IV_LENGTH, self::GCM_TAG_LENGTH);
        $ciphertext = substr($decoded, self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH);

        $decrypted = openssl_decrypt($ciphertext, self::CIPHER_GCM, $key, OPENSSL_RAW_DATA, $iv, $tag);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * 레거시 CBC 복호화 (v2 이전 암호문 하위호환 전용)
     *
     * 키는 원본 문자열을 그대로 사용해 기존 암호문과의 호환을 유지한다.
     */
    private function decryptLegacyCbc(string $encrypted, string $key): string
    {
        $decoded = base64_decode($encrypted);

        if ($decoded === false || strlen($decoded) < self::LEGACY_IV_LENGTH) {
            return '';
        }

        $iv = substr($decoded, 0, self::LEGACY_IV_LENGTH);
        $ciphertext = substr($decoded, self::LEGACY_IV_LENGTH);

        $decrypted = openssl_decrypt($ciphertext, self::LEGACY_CIPHER, $key, 0, $iv);

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * 키 정규화: 임의 길이의 키(hex/문자열)를 32바이트 원시 키로 변환.
     * AES-256의 전체 256비트 엔트로피를 사용하기 위함.
     */
    private function normalizeKey(string $key): string
    {
        return hash('sha256', $key, true);
    }

    /**
     * 암호화 키 생성
     *
     * @param int $length 키 길이 (바이트, 기본 32)
     * @return string hex 인코딩된 키
     */
    public function generateKey(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * 짧은 키 생성 (16바이트)
     *
     * @return string hex 인코딩된 키
     */
    public function generateShortKey(): string
    {
        return $this->generateKey(16);
    }

    /**
     * 안전한 랜덤 토큰 생성
     *
     * @param int $length 토큰 길이 (바이트)
     * @return string hex 인코딩된 토큰
     */
    public function generateToken(int $length = 32): string
    {
        return bin2hex(random_bytes($length));
    }

    /**
     * HMAC 해시 생성
     *
     * @param string $data 해시할 데이터
     * @param string $key 해시 키
     * @param string $algo 알고리즘 (기본 sha256)
     * @return string 해시값
     */
    public function hmac(string $data, string $key, string $algo = 'sha256'): string
    {
        return hash_hmac($algo, $data, $key);
    }

    /**
     * 타이밍 공격 방지 문자열 비교
     *
     * @param string $known 알려진 값
     * @param string $user 사용자 입력값
     * @return bool 일치 여부
     */
    public function secureCompare(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }
}
