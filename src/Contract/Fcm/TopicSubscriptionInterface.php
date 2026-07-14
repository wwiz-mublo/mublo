<?php

namespace Mublo\Contract\Fcm;

/**
 * 도메인별 FCM Topic 구독 관리 공개 계약.
 *
 * Installation은 Web Browser, Android App, iOS App을 모두 포함한다. 구현체는
 * Installation의 도메인 소유권과 활성 상태를 검증하고 논리 topic을 실제 FCM
 * topic 이름으로 안전하게 변환한다.
 */
interface TopicSubscriptionInterface
{
    public function subscribe(int $domainId, string $topic, int $installationId): void;

    public function unsubscribe(int $domainId, string $topic, int $installationId): void;

    /**
     * 회원이 소유한 활성 Installation을 구독 처리한다.
     * installationType이 null이면 Web·Android·iOS 전체를 대상으로 한다.
     *
     * @return int 처리된 Installation 수
     */
    public function subscribeMember(
        int $domainId,
        string $topic,
        int $memberId,
        ?string $installationType = null
    ): int;

    /**
     * 회원이 소유한 활성 Installation의 구독을 해제한다.
     * installationType이 null이면 Web·Android·iOS 전체를 대상으로 한다.
     *
     * @return int 처리된 Installation 수
     */
    public function unsubscribeMember(
        int $domainId,
        string $topic,
        int $memberId,
        ?string $installationType = null
    ): int;

    /**
     * 도메인의 논리 Topic에 구독된 Installation ID 목록을 반환한다.
     *
     * @return list<int>
     */
    public function subscribersOf(int $domainId, string $topic): array;
}
