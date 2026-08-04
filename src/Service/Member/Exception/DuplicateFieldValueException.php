<?php
declare(strict_types=1);

namespace Mublo\Service\Member\Exception;

/**
 * 추가필드 유일성(is_unique) 위반 예외
 *
 * 저장 시점의 DB 유니크 제약(uk_field_unique) 충돌을 사용자에게 보여줄 수 있는
 * 메시지로 변환하기 위한 마커 예외. 검증(checkDuplicate)을 통과한 동시 요청이
 * INSERT 에서 경합했을 때(TOCTOU) 발생한다.
 */
class DuplicateFieldValueException extends \RuntimeException
{
}
