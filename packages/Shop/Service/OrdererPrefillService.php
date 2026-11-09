<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Service;

use Mublo\Contract\Member\MemberCustomFieldQueryInterface;

/**
 * 주문서 주문인 기본값 (로그인 회원)
 *
 * 회원 추가필드의 이름(name)·연락처(phone)·이메일(email)로 주문인 칸을 미리 채운다.
 * 이름 필드가 비어 있으면 닉네임을 쓴다. 값은 화면의 초기값일 뿐이라 주문인이 고칠 수 있다.
 * 추가필드를 읽지 못해도 주문서는 열려야 하므로 실패하면 빈 값으로 둔다.
 */
class OrdererPrefillService
{
    /** 회원 추가필드 이름 (기본 가입 양식의 필드명) */
    public const FIELD_NAME = 'name';
    public const FIELD_PHONE = 'phone';
    public const FIELD_EMAIL = 'email';

    public function __construct(private ?MemberCustomFieldQueryInterface $fields = null)
    {
    }

    /**
     * @return array{name: string, phone: string, email: string}
     */
    public function forMember(int $memberId, int $domainId, ?string $nickname): array
    {
        $prefill = [
            'name' => $this->read($memberId, $domainId, self::FIELD_NAME),
            'phone' => $this->read($memberId, $domainId, self::FIELD_PHONE),
            'email' => $this->read($memberId, $domainId, self::FIELD_EMAIL),
        ];

        if ($prefill['name'] === '') {
            $prefill['name'] = trim((string) $nickname);
        }
        if ($prefill['email'] !== '' && filter_var($prefill['email'], FILTER_VALIDATE_EMAIL) === false) {
            $prefill['email'] = '';
        }

        return $prefill;
    }

    private function read(int $memberId, int $domainId, string $fieldName): string
    {
        if ($this->fields === null || $memberId <= 0) {
            return '';
        }

        try {
            $value = $this->fields->findValue($memberId, $domainId, $fieldName);
        } catch (\Throwable) {
            return '';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }
}
