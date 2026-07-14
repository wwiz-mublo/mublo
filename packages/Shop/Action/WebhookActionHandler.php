<?php

namespace Mublo\Packages\Shop\Action;

use Mublo\Packages\Shop\Event\OrderStatusChangedEvent;
use Mublo\Infrastructure\Log\Logger;

/**
 * WebhookActionHandler
 *
 * 외부 URL로 주문 상태 변경 정보를 JSON HTTP 요청으로 전송한다.
 * HTTPS, 사설망 차단, HMAC 서명을 기본으로 안전하게 전송한다.
 */
class WebhookActionHandler implements ActionHandlerInterface
{
    private const TIMEOUT_SECONDS = 8;

    public function __construct(private ?Logger $logger = null) {}

    public function execute(array $config, OrderStatusChangedEvent $event): void
    {
        $url = $config['url'] ?? '';
        $method = strtoupper($config['method'] ?? 'POST');

        $resolved = $this->validateAndResolveUrl((string) $url);

        $order = $event->getOrder();
        $claim = is_array($order['_claim'] ?? null) ? $order['_claim'] : null;
        unset($order['_claim']);

        // 민감 정보 제거
        unset(
            $order['orderer_phone'],
            $order['orderer_email'],
            $order['recipient_phone'],
            $order['shipping_address1'],
            $order['shipping_address2'],
            $order['shipping_zip'],
        );

        // 실행 레코드에 고정된 ID를 모든 재시도에서 재사용해 수신자가 중복을 제거할 수 있게 한다.
        $deliveryId = strtolower((string) ($config['_delivery_id'] ?? ''));
        if (preg_match('/^[a-f0-9]{32}$/', $deliveryId) !== 1) {
            $deliveryId = substr(hash('sha256', implode('|', [
                $event->getOrderNo(),
                $event->getEventId() ?? '',
                (string) ($config['action_id'] ?? ''),
                (string) $url,
            ])), 0, 32);
        }
        $timestamp = (string) time();
        $eventName = $claim !== null ? 'claim_status_changed' : 'order_status_changed';
        $payload = [
            'delivery_id' => $deliveryId,
            'event' => $eventName,
            'order_no' => $event->getOrderNo(),
            'prev_status' => $event->getPrevStateId(),
            'prev_label' => $event->getPrevLabel(),
            'new_status' => $event->getNewStateId(),
            'new_label' => $event->getNewLabel(),
            'order' => $order,
            'timestamp' => date('c', (int) $timestamp),
        ];
        if ($claim !== null) {
            $payload['claim'] = $claim;
        }

        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            throw new \RuntimeException('웹훅 payload 직렬화에 실패했습니다.');
        }

        $headers = [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonBody),
            'X-Mublo-Event: ' . $eventName,
            'X-Mublo-Delivery: ' . $deliveryId,
            'X-Mublo-Timestamp: ' . $timestamp,
        ];
        $secret = (string) ($config['secret'] ?? '');
        if ($secret !== '') {
            $headers[] = 'X-Mublo-Signature: sha256=' . hash_hmac('sha256', $timestamp . '.' . $jsonBody, $secret);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('웹훅 연결을 초기화할 수 없습니다.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_RESOLVE => [$resolved['resolve']],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new \RuntimeException('웹훅 연결 실패: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException("웹훅 HTTP 오류: {$httpCode}");
        } else {
            $this->logger?->info('OrderStateAction:webhook 성공', [
                'order_no' => $event->getOrderNo(),
                'endpoint' => $resolved['origin'],
                'delivery_id' => $deliveryId,
                'http_code' => $httpCode,
            ]);
        }
    }

    public function getType(): string
    {
        return 'webhook';
    }

    public function getLabel(): string
    {
        return '웹훅 호출';
    }

    public function getDescription(): string
    {
        return '외부 URL로 주문 정보를 HTTP 요청으로 전송합니다. 외부 시스템 연동에 활용됩니다.';
    }

    public function allowDuplicate(): bool
    {
        return true;
    }

    public function getSchema(): array
    {
        return [
            'required' => ['url', 'method'],
            'fields' => [
                'url' => [
                    'type' => 'url',
                    'label' => '웹훅 URL',
                    'placeholder' => 'https://example.com/webhook',
                    'schemes' => ['https'],
                ],
                'method' => [
                    'type' => 'select',
                    'label' => 'HTTP 메서드',
                    'options' => [
                        'POST' => 'POST',
                        'PUT' => 'PUT',
                    ],
                ],
                'secret' => [
                    'type' => 'password',
                    'label' => '서명 비밀키',
                    'placeholder' => '선택 사항: 수신 서버와 공유할 비밀키',
                ],
            ],
        ];
    }

    /** @return array{resolve:string,origin:string} */
    private function validateAndResolveUrl(string $url): array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('웹훅 URL은 HTTPS 주소여야 합니다.');
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('웹훅 URL에는 사용자 정보를 포함할 수 없습니다.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \InvalidArgumentException('웹훅 호스트가 올바르지 않습니다.');
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            throw new \InvalidArgumentException('웹훅 호스트의 공개 IP를 확인할 수 없습니다.');
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new \InvalidArgumentException('사설망 또는 예약 IP로 웹훅을 보낼 수 없습니다.');
            }
        }

        $port = (int) ($parts['port'] ?? 443);
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('웹훅 포트가 올바르지 않습니다.');
        }

        return [
            'resolve' => "{$host}:{$port}:{$ips[0]}",
            'origin' => "https://{$host}" . ($port === 443 ? '' : ":{$port}"),
        ];
    }
}
