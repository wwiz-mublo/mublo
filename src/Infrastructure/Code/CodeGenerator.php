<?php

namespace Mublo\Infrastructure\Code;

use Mublo\Helper\String\StringHelper;
use Mublo\Infrastructure\Database\Database;
use Mublo\Core\Context\Context;

/**
 * CodeGenerator
 *
 * 통합 유니크 코드 생성기
 * - unique_codes 테이블에서 중앙 관리
 * - 도메인별 자동 분리 (Context에서 domain_id 획득)
 * - 플러그인/패키지에서 code_type만 지정하면 사용 가능
 *
 * 사용:
 * $code = $codeGenerator->generate('menu');           // 랜덤 8자리
 * $code = $codeGenerator->generate('product', 10);   // 랜덤 10자리
 * $orderNo = $codeGenerator->orderNumber('order');   // 20240115A3B4C5
 * $coupon = $codeGenerator->couponCode('coupon');    // 대문자+숫자 10자리
 * $invite = $codeGenerator->inviteCode('invite');    // 대문자 6자리
 * $seq = $codeGenerator->sequential('invoice', 'INV-'); // INV-000001
 */
class CodeGenerator
{
    private Database $db;
    private Context $context;
    private string $table;

    private const MAX_RETRIES = 10;

    public function __construct(Database $db, Context $context)
    {
        $this->db = $db;
        $this->context = $context;
        $this->table = 'unique_codes';  // prefix는 table() 메서드에서 자동 추가
    }

    /**
     * 유니크 랜덤 코드 생성
     *
     * @param string $codeType 코드 타입 (menu, product, order 등)
     * @param int $length 코드 길이 (기본: 8)
     * @param string|null $prefix 접두어 (예: 'M_')
     * @param string|null $chars 사용할 문자셋
     * @return string
     * @throws \RuntimeException 최대 재시도 횟수 초과 시
     */
    public function generate(
        string $codeType,
        int $length = 8,
        ?string $prefix = null,
        ?string $chars = null
    ): string {
        $domainId = $this->context->getDomainId();
        $retries = 0;

        while ($retries < self::MAX_RETRIES) {
            $code = ($prefix ?? '') . StringHelper::random($length, $chars);

            if (!$this->exists($codeType, $code, $domainId)) {
                $this->register($codeType, $code, $domainId);
                return $code;
            }

            $retries++;
        }

        throw new \RuntimeException(
            "Failed to generate unique code for type '{$codeType}' after " . self::MAX_RETRIES . ' attempts'
        );
    }

    /**
     * 주문번호 형식 코드 생성
     * 형식: YYYYMMDD + 랜덤 6자리 (예: 20240115A3B4C5)
     *
     * @param string $codeType 코드 타입
     * @param int $randomLength 랜덤 부분 길이 (기본: 6)
     * @return string
     */
    public function orderNumber(string $codeType, int $randomLength = 6): string
    {
        $prefix = date('Ymd');
        return $this->generate($codeType, $randomLength, $prefix);
    }

    /**
     * 시퀀스 기반 코드 생성
     * 형식: PREFIX + 시퀀스번호 (예: INV-000001)
     *
     * @param string $codeType 코드 타입
     * @param string $prefix 접두어
     * @param int $padding 숫자 자릿수 (기본: 6)
     * @return string
     */
    public function sequential(string $codeType, string $prefix, int $padding = 6): string
    {
        $domainId = $this->context->getDomainId();

        // 현재 최대 시퀀스 조회
        $fullTable = $this->db->getTablePrefix() . $this->table;
        $sql = "SELECT MAX(CAST(SUBSTRING(code, ?) AS UNSIGNED)) as max_seq
                FROM {$fullTable}
                WHERE domain_id = ? AND code_type = ? AND code LIKE ?";

        $prefixLength = strlen($prefix) + 1;
        $result = $this->db->selectOne($sql, [$prefixLength, $domainId, $codeType, $prefix . '%']);

        $nextSeq = ($result['max_seq'] ?? 0) + 1;
        $code = $prefix . str_pad($nextSeq, $padding, '0', STR_PAD_LEFT);

        $this->register($codeType, $code, $domainId);

        return $code;
    }

    /**
     * 쿠폰 코드 생성 (대문자 + 숫자, 혼동 문자 제외)
     *
     * @param string $codeType 코드 타입
     * @param int $length 코드 길이 (기본: 10)
     * @return string
     */
    public function couponCode(string $codeType, int $length = 10): string
    {
        // 혼동 문자 제외: 0, O, 1, I, L
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        return $this->generate($codeType, $length, null, $chars);
    }

    /**
     * 초대 코드 생성 (짧고 기억하기 쉬운 대문자)
     *
     * @param string $codeType 코드 타입
     * @param int $length 코드 길이 (기본: 6)
     * @return string
     */
    public function inviteCode(string $codeType, int $length = 6): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTUVWXYZ';
        return $this->generate($codeType, $length, null, $chars);
    }

    /**
     * 코드 존재 여부 확인
     *
     * @param string $codeType 코드 타입
     * @param string $code 코드
     * @param int $domainId 도메인 ID
     * @return bool
     */
    public function exists(string $codeType, string $code, int $domainId): bool
    {
        $fullTable = $this->db->getTablePrefix() . $this->table;
        $sql = "SELECT 1 FROM {$fullTable}
                WHERE domain_id = ? AND code_type = ? AND code = ? LIMIT 1";
        $result = $this->db->selectOne($sql, [$domainId, $codeType, $code]);

        return $result !== null;
    }

    /**
     * 코드 등록
     *
     * @param string $codeType 코드 타입
     * @param string $code 코드
     * @param int $domainId 도메인 ID
     * @param int|null $referenceId 연결할 레코드 ID
     * @param string|null $referenceTable 연결할 테이블명
     * @return int 생성된 레코드 ID
     */
    public function register(
        string $codeType,
        string $code,
        int $domainId,
        ?int $referenceId = null,
        ?string $referenceTable = null
    ): int {
        return $this->db->table($this->table)->insert([
            'domain_id' => $domainId,
            'code_type' => $codeType,
            'code' => $code,
            'reference_id' => $referenceId,
            'reference_table' => $referenceTable,
        ]);
    }

    /**
     * 코드에 참조 정보 연결 (생성 후 실제 레코드와 연결할 때)
     *
     * @param string $codeType 코드 타입
     * @param string $code 코드
     * @param int $referenceId 연결할 레코드 ID
     * @param string $referenceTable 연결할 테이블명
     * @return int 영향받은 행 수
     */
    public function linkReference(
        string $codeType,
        string $code,
        int $referenceId,
        string $referenceTable
    ): int {
        $domainId = $this->context->getDomainId();

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('code_type', '=', $codeType)
            ->where('code', '=', $code)
            ->update([
                'reference_id' => $referenceId,
                'reference_table' => $referenceTable,
            ]);
    }

    /**
     * 코드 삭제 (레코드 삭제 시 함께 호출)
     *
     * @param string $codeType 코드 타입
     * @param string $code 코드
     * @return int 삭제된 행 수
     */
    public function delete(string $codeType, string $code): int
    {
        $domainId = $this->context->getDomainId();

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('code_type', '=', $codeType)
            ->where('code', '=', $code)
            ->delete();
    }

    /**
     * 참조로 코드 삭제 (레코드 ID로 연결된 코드 삭제)
     *
     * @param string $referenceTable 테이블명
     * @param int $referenceId 레코드 ID
     * @return int 삭제된 행 수
     */
    public function deleteByReference(string $referenceTable, int $referenceId): int
    {
        $domainId = $this->context->getDomainId();

        return $this->db->table($this->table)
            ->where('domain_id', '=', $domainId)
            ->where('reference_table', '=', $referenceTable)
            ->where('reference_id', '=', $referenceId)
            ->delete();
    }
}
