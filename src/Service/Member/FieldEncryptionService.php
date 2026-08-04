<?php
declare(strict_types=1);
namespace Mublo\Service\Member;

use Mublo\Core\ConfigFile;
use Mublo\Core\Crypto\EncryptionService;

/**
 * FieldEncryptionService
 *
 * 회원 필드 암호화/복호화 + 검색 인덱스 생성.
 *
 * 대칭키 암호화는 Core\Crypto\EncryptionService에 위임하고,
 * 이 클래스는 검색 인덱스(blind index) 같은 회원 필드 특화 책임을 가진다.
 *
 * 보안 설계:
 * - encryption.key: 필드 값 암호화용 (DB 털려도 복호화 불가)
 * - search.pepper: 검색 인덱스용 (DB 털려도 rainbow table 무력화)
 */
class FieldEncryptionService
{
    private EncryptionService $crypto;
    private string $searchPepper;

    public function __construct(EncryptionService $crypto)
    {
        $this->crypto = $crypto;

        // 설정이 없으면 pepper 도 없다 — 아래 길이 검사가 그대로 잡는다.
        $config = ConfigFile::load('security');
        $this->searchPepper = (string) hex2bin((string) ($config['search']['pepper'] ?? ''));

        if (strlen($this->searchPepper) !== 32) {
            throw new \RuntimeException('Invalid search pepper length. Expected 32 bytes.');
        }
    }

    public function encrypt(string $plainText): string
    {
        return $this->crypto->encrypt($plainText);
    }

    public function decrypt(string $encrypted): ?string
    {
        return $this->crypto->decrypt($encrypted);
    }

    /**
     * 검색 인덱스 생성 (Blind Index)
     *
     * 암호화된 필드를 검색하기 위한 HMAC 해시 생성
     * pepper가 없으면 rainbow table 공격으로 원문 추론 가능하므로
     * 반드시 pepper와 함께 사용
     *
     * @param string $value 원문 값
     * @return string 64자 hex 해시
     */
    public function createSearchIndex(string $value): string
    {
        // 정규화: 소문자 + 공백 제거 (검색 일관성)
        $normalized = strtolower(trim($value));

        return hash_hmac('sha256', $normalized, $this->searchPepper);
    }

    /**
     * 검색 인덱스 비교 (타이밍 공격 방지)
     *
     * @param string $storedIndex DB에 저장된 인덱스
     * @param string $searchValue 검색할 값
     * @return bool 일치 여부
     */
    public function matchSearchIndex(string $storedIndex, string $searchValue): bool
    {
        $searchIndex = $this->createSearchIndex($searchValue);
        return hash_equals($storedIndex, $searchIndex);
    }

    /**
     * 필드 값 처리 (암호화 + 검색 인덱스)
     *
     * @param string $value 원문 값
     * @param bool $isEncrypted 암호화 여부
     * @param bool $isSearchable 검색 가능 여부
     * @param string|null $searchIndexValue 검색 인덱스 생성에 사용할 값 (null이면 $value 사용)
     *                                       주소 타입 같은 복합 필드에서 우편번호만 인덱싱할 때 사용
     * @return array{field_value: string, search_index: string|null}
     */
    public function processFieldValue(string $value, bool $isEncrypted, bool $isSearchable, ?string $searchIndexValue = null): array
    {
        $result = [
            'field_value' => $value,
            'search_index' => null,
        ];

        // 암호화
        if ($isEncrypted) {
            $result['field_value'] = $this->encrypt($value);
        }

        // 검색 인덱스 (암호화 여부와 무관하게 검색 가능하면 생성)
        if ($isSearchable) {
            $indexValue = $searchIndexValue ?? $value;
            $result['search_index'] = $this->createSearchIndex($indexValue);
        }

        return $result;
    }

    /** 요청당 복호화 실패 로그 상한 — 목록 조회 시 손상 데이터로 인한 로그 폭주 방지 */
    private static int $decryptFailureLogCount = 0;
    private const DECRYPT_FAILURE_LOG_LIMIT = 5;

    /**
     * 필드 값 읽기 (복호화)
     *
     * 암호화 필드의 복호화 실패(키 로테이션·데이터 손상)는 무음 null 로 두면
     * 탐지가 불가능하므로 로그를 남긴다. 원문/암호문 자체는 기록하지 않는다.
     *
     * @param string|null $storedValue DB에 저장된 값
     * @param bool $isEncrypted 암호화 여부
     * @return string|null 원문 값
     */
    public function readFieldValue(?string $storedValue, bool $isEncrypted): ?string
    {
        if ($storedValue === null || $storedValue === '') {
            return null;
        }

        if ($isEncrypted) {
            $plain = $this->decrypt($storedValue);

            if ($plain === null && self::$decryptFailureLogCount < self::DECRYPT_FAILURE_LOG_LIMIT) {
                self::$decryptFailureLogCount++;
                error_log(sprintf(
                    '[FieldEncryption] 암호화 필드 복호화 실패 — 키 불일치 또는 데이터 손상 의심 (len=%d)%s',
                    strlen($storedValue),
                    self::$decryptFailureLogCount === self::DECRYPT_FAILURE_LOG_LIMIT
                        ? ' — 이 요청의 추가 실패 로그는 생략'
                        : ''
                ));
            }

            return $plain;
        }

        return $storedValue;
    }
}
