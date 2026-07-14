<?php

namespace Mublo\Contract\Notification;

/**
 * 회원 알림 센터에 저장할 불변 메시지.
 *
 * title/body는 렌더링 가능한 HTML이 아니라 일반 문자열이다. targetUrl도 현재
 * 사이트의 상대 경로만 허용해 Package/Plugin이 외부 리다이렉트를 만들 수 없게 한다.
 */
final readonly class MemberNotification
{
    public function __construct(
        public int $domainId,
        public int $memberId,
        public string $type,
        public string $title,
        public string $body = '',
        public ?string $targetUrl = null,
        public string $source = 'core',
        public ?string $sourceId = null,
        public ?int $actorMemberId = null,
        public ?string $deduplicationKey = null,
        public array $metadata = [],
        public ?\DateTimeImmutable $expiresAt = null,
    ) {
        if ($domainId < 1 || $memberId < 1) {
            throw new \InvalidArgumentException('알림의 도메인과 수신 회원이 필요합니다.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/', $type)) {
            throw new \InvalidArgumentException('알림 타입 형식이 올바르지 않습니다.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9._:-]{0,99}$/i', $source)) {
            throw new \InvalidArgumentException('알림 소스 형식이 올바르지 않습니다.');
        }
        if ($title === '' || mb_strlen($title) > 200 || mb_strlen($body) > 1000) {
            throw new \InvalidArgumentException('알림 제목 또는 내용 길이가 허용 범위를 벗어났습니다.');
        }
        if ($targetUrl !== null && (!$this->isSafeTargetUrl($targetUrl) || mb_strlen($targetUrl) > 500)) {
            throw new \InvalidArgumentException('알림 이동 주소는 사이트 내부 상대 경로만 사용할 수 있습니다.');
        }
        if ($sourceId !== null && mb_strlen($sourceId) > 191) {
            throw new \InvalidArgumentException('알림 소스 ID가 너무 깁니다.');
        }
        if ($actorMemberId !== null && $actorMemberId < 1) {
            throw new \InvalidArgumentException('알림 행위자 회원 ID가 올바르지 않습니다.');
        }
        if ($deduplicationKey !== null && ($deduplicationKey === '' || mb_strlen($deduplicationKey) > 191)) {
            throw new \InvalidArgumentException('알림 중복 방지 키가 올바르지 않습니다.');
        }

        json_encode($metadata, JSON_THROW_ON_ERROR);
    }

    private function isSafeTargetUrl(string $url): bool
    {
        return str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !str_contains($url, '\\')
            && preg_match('/[\x00-\x1F\x7F]/', $url) !== 1;
    }
}
