<?php
declare(strict_types=1);

namespace Mublo\Packages\Shop\Action;

use Mublo\Contract\Notification\NotificationChannelTreeBuilderInterface;
use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationTemplateContextInterface;
use Mublo\Core\Registry\ContractRegistry;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Packages\Shop\Service\ShipmentService;

/**
 * NotificationActionHandler
 *
 * 알림 발송 액션 (알림톡, SMS, 이메일)
 *
 * ContractRegistry에서 NotificationGatewayInterface 구현체를 조회하여 발송.
 * Plugin이 구현체를 등록하지 않으면 로그만 남기고 건너뜀.
 */
class NotificationActionHandler implements ActionHandlerInterface
{
    public function __construct(
        private ContractRegistry $registry,
        private ?Logger $logger = null,
        private ?ShipmentService $shipmentService = null,
        private ?NotificationTemplateContextInterface $uiHelper = null,
        private ?\Closure $domainIdResolver = null,
        private ?NotificationChannelTreeBuilderInterface $treeBuilder = null
    ) {}

    public function execute(array $config, OrderStatusChangedEvent $event): void
    {
        $channel      = $config['channel'] ?? '';
        $templateCode = $config['template_code'] ?? '';
        $recipient    = $config['recipient'] ?? 'orderer';

        if ($channel === '' || $templateCode === '') {
            $this->logger?->warning('OrderStateAction:notification 설정 누락', [
                'order_no' => $event->getOrderNo(),
                'channel'  => $channel,
                'template' => $templateCode,
            ]);
            throw new \InvalidArgumentException('알림 Action 설정이 누락되었습니다.');
        }

        // 채널을 지원하는 게이트웨이 탐색
        $gateway = $this->findGatewayByChannel($channel);
        if ($gateway === null) {
            $this->logger?->info('OrderStateAction:notification 게이트웨이 미등록', [
                'order_no' => $event->getOrderNo(),
                'channel'  => $channel,
            ]);
            throw new \RuntimeException("'{$channel}' 알림 게이트웨이가 등록되지 않았습니다.");
        }

        // 수신자 결정 (email 채널은 이메일, 그 외는 전화번호)
        $order = $event->getOrder();
        $recipientValue = $this->resolveRecipient($recipient, $channel, $order);

        if ($recipientValue === '') {
            $this->logger?->warning('OrderStateAction:notification 수신자 정보 없음', [
                'order_no'  => $event->getOrderNo(),
                'recipient' => $recipient,
            ]);
            throw new \RuntimeException('알림 수신자 정보가 없습니다.');
        }

        // 치환 변수 생성 + 발송
        $fieldValues = $this->prepareFieldValues($order, $event);
        $result = $gateway->send($channel, $templateCode, $recipientValue, $fieldValues);

        if ($result->success) {
            $this->logger?->info('OrderStateAction:notification 발송 성공', [
                'order_no' => $event->getOrderNo(),
                'channel'  => $channel,
                'template' => $templateCode,
            ]);
        } else {
            $this->logger?->warning('OrderStateAction:notification 발송 실패', [
                'order_no' => $event->getOrderNo(),
                'channel'  => $channel,
                'message'  => $result->message,
            ]);
            throw new \RuntimeException($result->message ?: '알림 발송에 실패했습니다.');
        }
    }

    public function getType(): string
    {
        return 'notification';
    }

    public function getLabel(): string
    {
        return '알림 발송';
    }

    public function getDescription(): string
    {
        return '주문 상태 변경 시 알림톡, SMS, 이메일 등으로 알림을 발송합니다. 채널/수신자별 다중 등록이 가능합니다.';
    }

    public function allowDuplicate(): bool
    {
        return true;
    }

    public function getSchema(): array
    {
        $providers = $this->buildProviders();
        [$templateOptions, $templatesByChannel] = $this->buildTemplateOptions($providers);

        // 템플릿이 하나도 없으면 (게이트웨이 미설치 등) 수기 입력 폴백
        $templateField = $templateOptions === []
            ? [
                'type' => 'text',
                'label' => '템플릿 코드',
                'placeholder' => '예: order_confirmed',
            ]
            : [
                'type' => 'select',
                'label' => '템플릿',
                'options' => $templateOptions,
                'options_by_channel' => $templatesByChannel,
            ];

        return [
            'required' => ['channel', 'template_code', 'recipient'],
            'optional' => ['enabled'],
            'fields' => [
                'channel' => [
                    'type' => 'select',
                    'label' => '발송 채널',
                    'options' => $this->buildChannelOptions($providers, $templatesByChannel),
                ],
                'template_code' => $templateField,
                'recipient' => [
                    'type' => 'select',
                    'label' => '수신자',
                    'options' => [
                        'orderer'   => '주문인',
                        'recipient' => '수령인',
                        'admin'     => '관리자',
                    ],
                ],
            ],
        ];
    }

    /**
     * 코어 NotificationChannelTreeBuilder 경유 공급자 트리 수집.
     * 빌더 미주입/조회 실패 시 빈 배열 — 소비처가 각자 폴백한다.
     */
    private function buildProviders(): array
    {
        if ($this->treeBuilder === null) {
            return [];
        }

        $domainId = $this->resolveDomainId();
        if ($domainId === null) {
            return []; // 도메인 문맥 없이 도메인 1 의 템플릿을 보여주지 않는다
        }

        try {
            return $this->treeBuilder->build($domainId);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 채널 옵션 — 템플릿 드롭다운과 같은 빌더 트리를 소스로 사용해
     * 두 목록이 항상 일치하게 한다. 트리가 비면 게이트웨이 메타 순회 폴백.
     *
     * 이메일은 실제 이메일 템플릿이 있을 때만 노출한다 — Shop 알림 액션은
     * template_code 가 필수라, 템플릿 공급자(EmailNotify 등)가 없으면
     * 선택해도 성공시킬 방법이 없는 채널이 목록에 보이게 된다.
     */
    private function buildChannelOptions(array $providers, array $templatesByChannel): array
    {
        $labels = ['sms' => 'SMS', 'alimtalk' => '카카오 알림톡', 'email' => '이메일'];

        $options = [];
        foreach ($providers as $provider) {
            $type = (string) ($provider['type'] ?? '');
            if ($type === '') {
                continue;
            }
            if ($type === 'email' && empty($templatesByChannel['email'])) {
                continue;
            }
            $options[$type] ??= $labels[$type]
                ?? ((string) ($provider['type_label'] ?? '') ?: (string) ($provider['plugin_label'] ?? $type));
        }

        if ($options !== []) {
            return $options;
        }

        // 빌더 미주입 폴백 — 템플릿 존재를 확인할 수 없으므로 이메일은 제외
        $fallback = $this->getAvailableChannels();
        unset($fallback['email']);
        return $fallback;
    }

    /**
     * 템플릿 선택지 수집 — Mshop FSM 핸들러와 동일 형태.
     *
     * @return array{0: array<string,string>, 1: array<string,array<string,string>>}
     *         [전체 옵션 code=>라벨, 채널타입별 옵션 channel=>[code=>라벨]]
     */
    private function buildTemplateOptions(array $providers): array
    {
        $flat = [];
        $byChannel = [];

        foreach ($providers as $provider) {
            $channelType = (string) ($provider['type'] ?? '');
            $typeLabel = (string) ($provider['type_label'] ?? ($provider['plugin_label'] ?? $channelType));

            foreach ($provider['channels'] ?? [] as $ch) {
                foreach ($ch['templates'] ?? [] as $tpl) {
                    $code = (string) ($tpl['template_code'] ?? '');
                    if ($code === '') {
                        continue;
                    }
                    $label = sprintf(
                        '[%s] %s — %s',
                        $typeLabel,
                        (string) ($ch['channel_name'] ?? ''),
                        (string) ($tpl['template_name'] ?? $code)
                    );
                    // first-wins — 발송(코어 게이트웨이의 공급자 순회)도 먼저 등록된
                    // 공급자 우선이므로 표시와 실발송이 같은 템플릿을 가리킨다
                    $flat[$code] ??= $label;
                    $byChannel[$channelType][$code] ??= $label;
                }
            }
        }

        return [$flat, $byChannel];
    }

    /**
     * 도메인 문맥 해석 — 해석 불가 시 null (도메인 1 추측 폴백 금지).
     */
    private function resolveDomainId(): ?int
    {
        try {
            $id = $this->domainIdResolver !== null ? ($this->domainIdResolver)() : null;
            return $id !== null && (int) $id > 0 ? (int) $id : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * 등록된 게이트웨이에서 해당 채널을 지원하는 것을 찾는다
     */
    private function findGatewayByChannel(string $channel): ?NotificationGatewayInterface
    {
        // 게이트웨이는 register()(1:N)로 등록되므로 allMeta로 조회한다.
        // has()는 bind()(1:1) 전용이라 여기서 쓰면 항상 false가 된다.
        $allMeta = $this->registry->allMeta(NotificationGatewayInterface::class);

        foreach ($allMeta as $key => $meta) {
            $channels = $meta['channels'] ?? [];
            if (in_array($channel, $channels, true)) {
                return $this->registry->get(NotificationGatewayInterface::class, $key);
            }
        }

        return null;
    }

    /**
     * 등록된 모든 게이트웨이의 채널을 병합하여 옵션 목록 생성
     */
    private function getAvailableChannels(): array
    {
        // 채널 키 → 표시 라벨. 게이트웨이가 메타 label을 주면 그것을 우선 사용한다.
        $channelLabels = [
            'sms'      => 'SMS',
            'alimtalk' => '카카오 알림톡',
            'email'    => '이메일',
        ];

        $channels = [];

        // 게이트웨이는 register()(1:N)로 등록되므로 allMeta로 조회한다.
        // has()는 bind()(1:1) 전용이라 여기서 쓰면 항상 false가 된다.
        $allMeta = $this->registry->allMeta(NotificationGatewayInterface::class);
        foreach ($allMeta as $meta) {
            $gatewayLabel = $meta['label'] ?? '';
            foreach ($meta['channels'] ?? [] as $ch) {
                if (!isset($channels[$ch])) {
                    $channels[$ch] = $gatewayLabel !== ''
                        ? $gatewayLabel
                        : ($channelLabels[$ch] ?? $ch);
                }
            }
        }

        // 등록된 게이트웨이가 제공하는 채널만 노출 (기본/폴백 채널 없음)
        return $channels;
    }

    /**
     * 수신자 유형·채널에 따라 수신자 값 결정
     *
     * email 채널은 이메일 주소가 수신자다 — 주문에는 주문인 이메일만 있으므로
     * 그 외 유형(수령인·관리자)은 빈 값을 반환해 상위에서 경고 후 스킵된다.
     */
    private function resolveRecipient(string $type, string $channel, array $order): string
    {
        if ($channel === 'email') {
            return $type === 'orderer' ? ($order['orderer_email'] ?? '') : '';
        }

        return match ($type) {
            'recipient' => $order['recipient_phone'] ?? '',
            'admin'     => $order['admin_phone'] ?? '',
            default     => $order['orderer_phone'] ?? '',
        };
    }

    /**
     * 주문 데이터에서 템플릿 치환 변수 생성
     */
    private function prepareFieldValues(array $order, OrderStatusChangedEvent $event): array
    {
        $claim = is_array($order['_claim'] ?? null) ? $order['_claim'] : [];
        $shipment = $claim !== []
            ? $this->resolveClaimShipment((int) ($claim['return_id'] ?? 0))
            : $this->resolveShipment($event->getOrderNo());

        $domainId = (int) ($order['domain_id'] ?? 0);

        // 사업자/사이트 변수 (#{사이트명}, #{고객센터번호} 등 — 템플릿 팔레트의 한글 키)
        // 도메인 문맥이 없으면 조회를 생략한다 — 도메인 1 의 사업자 정보를 추측하지 않는다
        $companyValues = [];
        if ($this->uiHelper !== null && $domainId > 0) {
            try {
                $companyValues = $this->uiHelper->getCompanySampleValues($domainId);
            } catch (\Throwable) {
                // 사업자정보 미등록 등 무시
            }
        }

        // 게이트웨이 라우팅용 도메인 스코프 — 해석된 경우에만 싣는다
        // (0 을 실으면 이를 우선 사용하는 게이트웨이의 정상 Context 해석을 덮는다)
        $routing = $domainId > 0 ? ['domain_id' => $domainId] : [];

        return $companyValues + $routing + [
            'orderer_name'  => $order['orderer_name'] ?? '',
            'orderer_phone' => $order['orderer_phone'] ?? '',
            'order_no'      => $event->getOrderNo(),
            'order_date'    => $order['created_at'] ?? '',
            'total_amount'  => $order['total_amount'] ?? 0,
            'status_label'  => $event->getNewLabel(),
            'prev_status'   => $event->getPrevLabel(),
            'device_name'   => $order['device_name'] ?? '',
            // 배송 정보: 송장 미등록 주문/서비스 미주입 시 빈 문자열
            'courier'       => $shipment['company_name'] ?? '',
            'invoice_no'    => $shipment['invoice_no'] ?? '',
            'tracking_url'  => $shipment['tracking_url'] ?? '',
            'claim_id'      => $claim['return_id'] ?? '',
            'claim_status'  => $claim['return_status'] ?? '',
            'claim_quantity'=> $claim['exchange_quantity'] ?? $claim['quantity'] ?? '',
            'claim_option'  => $claim['target_option_name'] ?? '',
        ];
    }

    /**
     * 주문의 대표 배송 정보(첫 송장)를 조회한다.
     *
     * ShipmentService::getByOrderNo는 택배사명(company_name)과 완성된 추적URL(tracking_url)을
     * 포함해 반환한다. 부분배송으로 여러 건이면 가장 먼저 등록된 송장을 대표로 쓴다.
     * 서비스 미주입 또는 송장 미등록이면 null.
     */
    private function resolveShipment(string $orderNo): ?array
    {
        if ($this->shipmentService === null) {
            return null;
        }

        $shipments = $this->shipmentService->getByOrderNo($orderNo);
        return $shipments[0] ?? null;
    }

    private function resolveClaimShipment(int $claimId): ?array
    {
        if ($this->shipmentService === null || $claimId <= 0) {
            return null;
        }
        $shipments = $this->shipmentService->getByClaimId($claimId);
        return $shipments === [] ? null : $shipments[array_key_last($shipments)];
    }
}
