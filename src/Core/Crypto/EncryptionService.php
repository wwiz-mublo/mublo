<?php

namespace Mublo\Core\Crypto;

/**
 * EncryptionService
 *
 * 도메인 무관 대칭키 암호화 헬퍼.
 *
 * AES-256-GCM (인증된 암호화). config/security.php의 encryption.key 사용.
 * 결제 PG 시크릿/외부 API 토큰처럼 평문이 DB에 남으면 안 되는 값에 사용.
 *
 * 회원 필드처럼 검색 인덱스(blind index)가 함께 필요한 경우는
 * Service\Member\FieldEncryptionService 사용 (이 클래스를 내부 위임).
 */
class EncryptionService
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const NONCE_LENGTH = 12; // GCM 권장

    private string $encryptionKey;

    public function __construct()
    {
        $config = require MUBLO_CONFIG_PATH . '/security.php';

        $this->encryptionKey = hex2bin($config['encryption']['key'] ?? '');

        if (strlen($this->encryptionKey) !== 32) {
            throw new \RuntimeException('Invalid encryption key length. Expected 32 bytes.');
        }
    }

    /**
     * 평문 → 암호문 (base64: nonce || tag || ciphertext)
     */
    public function encrypt(string $plainText): string
    {
        $nonce = random_bytes(self::NONCE_LENGTH);
        $tag = '';

        $cipherText = openssl_encrypt(
            $plainText,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            self::TAG_LENGTH
        );

        if ($cipherText === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        return base64_encode($nonce . $tag . $cipherText);
    }

    /**
     * 암호문 → 평문 (실패 시 null)
     */
    public function decrypt(string $encrypted): ?string
    {
        $decoded = base64_decode($encrypted, true);

        if ($decoded === false) {
            return null;
        }

        $minLen = self::NONCE_LENGTH + self::TAG_LENGTH + 1;
        if (strlen($decoded) < $minLen) {
            return null;
        }

        $nonce = substr($decoded, 0, self::NONCE_LENGTH);
        $tag = substr($decoded, self::NONCE_LENGTH, self::TAG_LENGTH);
        $cipherText = substr($decoded, self::NONCE_LENGTH + self::TAG_LENGTH);

        $plainText = openssl_decrypt(
            $cipherText,
            self::CIPHER,
            $this->encryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag
        );

        return $plainText !== false ? $plainText : null;
    }
}
