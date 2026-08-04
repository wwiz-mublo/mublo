<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms;

use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;
use Mublo\Plugin\SendonSms\Service\SendonSmsService;
use Mublo\Plugin\SendonSms\Repository\SendonSmsChannelRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsTemplateRepository;

class SendonSmsGateway implements NotificationGatewayInterface
{
    public function __construct(
        private SendonSmsService $service,
        private SendonSmsChannelRepository $channelRepo,
        private SendonSmsTemplateRepository $templateRepo,
        private int $domainId
    ) {
    }

    public function send(
        string $channel,
        string $templateCode,
        string $recipient,
        array $fieldValues
    ): NotificationSendResult {
        $result = $this->service->send($this->domainId, $templateCode, $recipient, $fieldValues);

        return new NotificationSendResult($result->isSuccess(), $result->getMessage());
    }

    public function getSupportedChannels(): array
    {
        return [
            'sms' => 'SMS/LMS/MMS',
        ];
    }

    public function getChannelTree(int $domainId): array
    {
        $tree = [
            'sms' => [
                'label' => 'SMS/LMS/MMS',
                'channels' => [],
            ],
        ];

        $channels = $this->channelRepo->getList($domainId, 100, 0);

        foreach ($channels as $ch) {
            if (empty($ch['is_active'])) {
                continue;
            }

            $templates = $this->templateRepo->getByChannel($domainId, (int) $ch['channel_id'], 200, 0);

            $tplList = [];
            foreach ($templates as $tpl) {
                $tplList[] = [
                    'code' => $tpl['template_code'] ?? '',
                    'name' => $tpl['template_name'] ?? '',
                ];
            }

            $tree['sms']['channels'][] = [
                'id' => (int) $ch['channel_id'],
                'name' => $ch['channel_name'] ?? '',
                'templates' => $tplList,
            ];
        }

        return $tree;
    }
}
