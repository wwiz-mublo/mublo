<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/**
 * 확장이 권한 분기에 사용할 수 있는 안정적인 회원 레벨 타입 코드.
 *
 * 레벨 Entity의 저장 구조를 노출하지 않고 DB에 저장되는 고정 어휘만 제공한다.
 */
final class MemberLevelType
{
    public const SUPER = 'SUPER';
    public const STAFF = 'STAFF';
    public const PARTNER = 'PARTNER';
    public const SITE_MASTER = 'SITE_MASTER';
    public const SUPPLIER = 'SUPPLIER';
    public const BASIC = 'BASIC';

    private function __construct()
    {
    }
}
