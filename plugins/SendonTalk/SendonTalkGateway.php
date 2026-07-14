<?php

namespace Mublo\Plugin\SendonTalk;

use Mublo\Contract\Notification\NotificationGatewayInterface;
use Mublo\Contract\Notification\NotificationSendResult;
use Mublo\Plugin\SendonTalk\Service\SendonTalkService;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkChannelRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkTemplateRepository;

class SendonTalkGateway implements NotificationGatewayInterface
{
    public function __construct(
        private SendonTalkService $service,
        private SendonTalkChannelRepository $channelRepo,
        private SendonTalkTemplateRepository $templateRepo,
        private int $domainId
    ) {}

    public function send(string $channel, string $templateCode, string $recipient, array $fieldValues): NotificationSendResult
    {
        $domainId = (int) ($fieldValues['domain_id'] ?? $this->domainId);
        $result = $this->service->send($domainId, $templateCode, $recipient, $fieldValues);

        return new NotificationSendResult($result->isSuccess(), $result->getMessage());
    }

    public function getSupportedChannels(): array
    {
        return ['alimtalk' => '카카오 알림톡'];
    }

    public function getChannelTree(int $domainId): array
    {
        $channels = $this->channelRepo->getList($domainId);
        $channelNodes = [];

        foreach ($channels as $ch) {
            if (empty($ch['is_active'])) continue;

            $templates = $this->templateRepo->getByChannel($domainId, (int) $ch['channel_id']);
            $tplList = [];
            foreach ($templates as $tpl) {
                if (empty($tpl['is_active'])) continue;
                $statusBadge = match ($tpl['kakao_status'] ?? 'draft') {
                    'approved' => ' ✅',
                    'pending'  => ' ⏳',
                    'rejected' => ' ❌',
                    default    => ' 📝',
                };
                $tplList[] = [
                    'code' => $tpl['template_code'] ?? '',
                    'name' => ($tpl['template_name'] ?? '') . $statusBadge,
                ];
            }

            $channelNodes[] = [
                'id'        => (int) $ch['channel_id'],
                'name'      => $ch['channel_name'] ?? '',
                'templates' => $tplList,
            ];
        }

        return [
            'alimtalk' => [
                'label'    => '카카오 알림톡',
                'channels' => $channelNodes,
            ],
        ];
    }
}
