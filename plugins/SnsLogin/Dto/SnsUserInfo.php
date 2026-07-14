<?php
namespace Mublo\Plugin\SnsLogin\Dto;

/**
 * SNS 사용자 정보 DTO (불변 객체)
 */
class SnsUserInfo
{
    public function __construct(
        public readonly string  $provider,
        public readonly string  $uid,
        public readonly ?string $email,
        public readonly ?string $nickname,
        public readonly ?string $profileImage,
    ) {}
}
