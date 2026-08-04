<?php
declare(strict_types=1);

namespace Mublo\Contract\Fcm;

use Mublo\Core\Result\Result;

/**
 * FCM 기반 Push 전송을 위한 Core 공개 인프라 계약.
 *
 * Installation은 Web Browser, Android App, iOS App 등 하나의 활성 Push
 * 수신 지점을 뜻한다. Package/Plugin은 Firebase SDK, 토큰 저장 방식과 실제
 * topic 네임스페이스를 알지 않고 이 계약만 사용한다.
 *
 * 댓글·쪽지처럼 알림함 기록이 필요한 개인 알림은 먼저
 * MemberNotificationPublisherInterface로 발행해야 한다. 이 계약은 그 알림을
 * 외부 Push로 전달하거나 FCM Topic 브로드캐스트가 필요할 때 사용한다.
 */
interface FcmMessageServiceInterface
{
    /**
     * 단일 Installation에 전송한다.
     *
     * 구현체는 installationId가 domainId에 속한 활성 FCM Installation인지
     * 검증해야 한다.
     */
    public function dispatchToInstallation(
        int $domainId,
        int $installationId,
        string $action,
        array $payload
    ): Result;

    /**
     * 회원의 활성 Installation에 전송한다.
     *
     * installationType이 null이면 Web·Android·iOS 전체를 대상으로 한다.
     *
     * @return Result data: ['sent' => int, 'failed' => int, 'installations' => int]
     */
    public function dispatchToMember(
        int $domainId,
        int $memberId,
        ?string $installationType,
        string $action,
        array $payload
    ): Result;

    /**
     * 도메인에 속한 FCM Topic 구독자 전체에 전송한다.
     *
     * 구현체는 같은 논리 topic이 도메인 사이에서 충돌하지 않도록 실제 FCM
     * topic 이름을 안전하게 네임스페이스 처리해야 한다.
     */
    public function dispatchToTopic(
        int $domainId,
        string $topic,
        string $action,
        array $payload
    ): Result;
}
