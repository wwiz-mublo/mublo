<?php

namespace Mublo\Contract\Notification;

/**
 * 외부 채널 알림 발송 계약 인터페이스
 *
 * ContractRegistry에 등록되어 1:N 메시지 발송을 지원합니다.
 * 각 발송 플러그인(이메일, SMS, 알림톡 등)은 이 인터페이스를 구현합니다.
 * 사이트 내부의 종 아이콘·읽음 상태는 MemberNotificationPublisherInterface가 담당합니다.
 *
 * ── 구현 규약 (시그니처 밖의 약속) ─────────────────────────────────────
 *
 * 1. 등록: ContractRegistry::register() (1:N) 로 등록하고, meta 에
 *    ['channels' => ['alimtalk'], 'label' => '표시명'] 을 넣는다.
 *    호출자는 meta['channels'] 로 채널을 지원하는 게이트웨이를 검색한다.
 *
 * 2. 채널 키 표준: 'alimtalk'(카카오 알림톡), 'sms', 'email'.
 *    meta['channels'], getSupportedChannels() 의 키, send() 의 $channel,
 *    getChannelTree() 의 최상위 키는 모두 이 동일한 키를 쓴다.
 *    새 채널 타입을 도입할 때도 세 곳의 키를 일치시켜야 한다.
 *
 * 3. 변수 치환은 게이트웨이 책임: 호출자(패키지·시나리오 플러그인)는
 *    fieldValues 만 넘기고 본문을 치환하지 않는다. 게이트웨이가 템플릿
 *    본문의 #{키} 를 fieldValues[키] 로 치환한다 (strtr 일괄 치환 의미).
 *    fieldValues 에는 변수 이벤트(CollectNotificationVariablesEvent)로
 *    광고된 영문 키 외에 사업자/사이트 한글 키(#{사이트명}, #{고객센터번호} 등,
 *    NotificationTemplateUiHelper::getCompanySampleValues 참조)가 섞여 올 수 있다.
 *
 * 4. 미치환 변수: 본문에 #{키} 가 남으면 발송하지 말고 실패를 반환할 것을
 *    권장한다 (변수명이 그대로 노출된 오발송 방지 — SendonTalk 선례).
 *
 * 5. getChannelTree(): 관리자 UI(템플릿 선택 드롭다운 등)용.
 *    templates[].code 가 send() 의 $templateCode 로 그대로 전달되는 식별자이고,
 *    templates[].name 은 표시용이라 승인상태 뱃지 등 장식을 붙여도 된다.
 *    비활성 채널·템플릿은 제외하고 반환한다.
 * ───────────────────────────────────────────────────────────────────────
 */
interface NotificationGatewayInterface
{
    /**
     * 메시지 발송
     *
     * @param string $channel      채널 타입 ('alimtalk', 'sms', 'email' 등)
     * @param string $templateCode 템플릿 코드 (getChannelTree 의 templates[].code)
     * @param string $recipient    수신자 (전화번호 또는 이메일)
     * @param array<string, scalar|null> $fieldValues 치환 변수 ['orderer_name' => '홍길동', ...]
     *        — 본문 #{키} 치환은 게이트웨이가 수행 (클래스 docblock 규약 3·4 참조)
     */
    public function send(
        string $channel,
        string $templateCode,
        string $recipient,
        array $fieldValues
    ): NotificationSendResult;

    /**
     * 지원하는 채널 목록
     *
     * @return array<string, string> ['alimtalk' => '카카오 알림톡', 'sms' => 'SMS']
     */
    public function getSupportedChannels(): array;

    /**
     * 채널·템플릿 트리 조회 (관리자 UI 용 — 클래스 docblock 규약 5 참조)
     *
     * @param int $domainId
     * @return array<string, array{
     *   label: string,
     *   channels: list<array{
     *     id: int|string,
     *     name: string,
     *     templates: list<array{code: string, name: string}>
     *   }>
     * }>
     */
    public function getChannelTree(int $domainId): array;
}
