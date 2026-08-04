<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Helper;

use Mublo\Infrastructure\Database\Database;

/**
 * 공개 상품 판정
 *
 * "이 상품을 로그인하지 않은 외부에 선언해도 되는가"의 단일 기준이다.
 * 사이트맵과 가격비교 피드가 같은 답을 써야 하므로 조건을 한 곳에 둔다.
 * 갈라지면 사이트맵에는 없는 상품이 가격비교로 나가거나 그 반대가 되는데,
 * 어느 쪽이 맞는지 나중에 판단할 근거가 남지 않는다.
 *
 * 판정 내용:
 *  - 비활성 상품 제외
 *  - 접근 제한(allow_member_level>0)·성인 인증(is_adult=1) 카테고리 소속 제외
 *    (주/보조 카테고리 양쪽을 본다)
 *
 * 재고는 보지 않는다. 품절 상품도 상세 페이지 자체는 공개 콘텐츠라 사이트맵에는
 * 실려야 한다. "지금 팔 수 있는 것만" 골라야 하는 쪽이 자기 조건을 더 붙인다.
 */
final class PublicProductCriteria
{
    private ?bool $categoryTableExists = null;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * WHERE 절에 AND 로 이어붙일 조건식을 돌려준다.
     *
     * 바인딩 파라미터를 만들지 않으므로 호출부의 파라미터 순서에 영향이 없다.
     *
     * @param string $alias shop_products 테이블 별칭
     */
    public function predicate(string $alias = 'g'): string
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        if ($a === '' || $a === null) {
            $a = 'g';
        }

        $sql = "{$a}.is_active = 1";

        // 카테고리 테이블이 없으면 제한 판정 자체가 불가능하다.
        // 이때 쿼리를 터뜨리는 대신 활성 여부만 보고 통과시킨다.
        if ($this->categoryTableExists()) {
            $sql .= "
                AND NOT EXISTS (
                    SELECT 1
                      FROM shop_category_items ci
                     WHERE ci.domain_id = {$a}.domain_id
                       AND ci.category_code IN ({$a}.category_code, {$a}.category_code_extra)
                       AND (COALESCE(ci.allow_member_level, 0) > 0
                            OR COALESCE(ci.is_adult, 0) = 1)
                )";
        }

        return $sql;
    }

    private function categoryTableExists(): bool
    {
        if ($this->categoryTableExists !== null) {
            return $this->categoryTableExists;
        }

        try {
            $row = $this->db->selectOne(
                "SELECT 1 AS ok
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'shop_category_items'
                  LIMIT 1"
            );
            $exists = $row !== null;
        } catch (\Throwable $e) {
            error_log('[Shop] 공개 상품 판정 — 카테고리 테이블 확인 실패: ' . $e->getMessage());
            $exists = false;
        }

        return $this->categoryTableExists = $exists;
    }
}
