<?php
declare(strict_types=1);

namespace Mublo\Contract\Notification;

/**
 * 이메일 템플릿 공급자 계약.
 *
 * 이메일 채널의 역할 분담:
 *  - 발송(전송로)은 코어 이메일 게이트웨이('core_email')가 유일 통로다.
 *  - 템플릿(내용물)은 이 계약을 구현한 확장이 공급한다 (ContractRegistry 1:N).
 *
 * 코어 게이트웨이는 발송 시 등록된 공급자들에게 순서대로 render 를 위임하고,
 * 채널 트리 조립 시 getTemplates 를 병합해 노출하며, 전송 후 onSent 로
 * 결과를 통지한다.
 */
interface EmailTemplateProviderInterface
{
    /**
     * 활성 템플릿 목록 — 채널 트리(관리자 드롭다운) 노출용.
     *
     * @return array<int, array{code: string, name: string}>
     */
    public function getTemplates(int $domainId): array;

    /**
     * 템플릿 렌더링 — 변수 치환이 끝난 발송 준비물을 반환한다.
     *
     * 모르는 템플릿 코드면 null 을 반환해 다음 공급자에게 넘기고,
     * 자신의 코드지만 발송할 수 없으면(비활성·미치환 변수 등) 예외를 던진다.
     *
     * @return array{subject: string, body: string, from_email?: string, from_name?: string}|null
     * @throws \RuntimeException 렌더 불가 — 메시지가 발송 실패 사유가 된다
     */
    public function render(int $domainId, string $templateCode, array $fieldValues): ?array;

    /**
     * 발송 결과 통지 — 공급자 측 발송 이력 기록용 (no-op 허용).
     */
    public function onSent(
        int $domainId,
        string $templateCode,
        string $recipient,
        string $subject,
        bool $success,
        string $message
    ): void;
}
