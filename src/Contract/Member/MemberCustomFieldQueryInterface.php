<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 회원 추가필드의 내부 ID·저장·암호화 방식을 숨기는 읽기 계약. */
interface MemberCustomFieldQueryInterface
{
    public function findValue(int $memberId, int $domainId, string $fieldName): mixed;
}
