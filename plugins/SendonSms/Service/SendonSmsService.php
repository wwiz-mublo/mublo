<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonSms\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\SendonSms\Repository\SendonSmsConfigRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsChannelRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsTemplateRepository;
use Mublo\Plugin\SendonSms\Repository\SendonSmsLogRepository;
use Mublo\Contract\Security\SensitiveValueCodecInterface;

/**
 * SendonSmsService
 *
 * 알림 게이트웨이 표준 패턴의 센드온 API 구현
 * 도메인별 자체 API 인증, 센드온 직접 충전/관리
 */
class SendonSmsService
{
    private const ENCRYPTED_CONFIG_FIELDS = ['api_id', 'api_password'];
    private const ALLOWED_MESSAGE_TYPES = ['SMS', 'LMS', 'MMS'];

    public function __construct(
        private SendonSmsConfigRepository $configRepository,
        private SendonSmsChannelRepository $channelRepository,
        private SendonSmsTemplateRepository $templateRepository,
        private SendonSmsLogRepository $logRepository,
        private ?SensitiveValueCodecInterface $encryptionService = null
    ) {
    }

    // === 연동 설정 ===

    public function getConfig(int $domainId): array
    {
        $config = $this->configRepository->findByDomainId($domainId);

        $defaults = [
            'api_id' => '',
            'api_password' => '',
            'default_sender' => '',
            'test_mode' => 1,
            'is_active' => 1,
        ];

        $merged = array_merge($defaults, $config ?? []);

        foreach (self::ENCRYPTED_CONFIG_FIELDS as $field) {
            if (!empty($merged[$field]) && $this->encryptionService) {
                $decrypted = $this->encryptionService->decrypt($merged[$field]);
                $merged[$field] = ($decrypted !== null && $decrypted !== '') ? $decrypted : '';
            }
        }

        return $merged;
    }

    public function saveConfig(int $domainId, array $data, array $clearFields = []): Result
    {
        $payload = [
            'api_id' => trim((string) ($data['api_id'] ?? '')),
            'api_password' => trim((string) ($data['api_password'] ?? '')),
            'default_sender' => trim((string) ($data['default_sender'] ?? '')),
            'test_mode' => !empty($data['test_mode']) ? 1 : 0,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        $existing = null;
        foreach (self::ENCRYPTED_CONFIG_FIELDS as $field) {
            if ($payload[$field] !== '') {
                if ($this->encryptionService) {
                    $payload[$field] = $this->encryptionService->encrypt($payload[$field]);
                }
            } elseif (in_array($field, $clearFields, true)) {
                $payload[$field] = '';
            } else {
                if ($existing === null) {
                    $existing = $this->configRepository->findByDomainId($domainId) ?? [];
                }
                $payload[$field] = $existing[$field] ?? '';
            }
        }

        $this->configRepository->upsert($domainId, $payload);

        return Result::success('연동 설정이 저장되었습니다.');
    }

    // === 채널 관리 ===

    public function getChannels(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $totalItems = $this->channelRepository->countByDomain($domainId);

        $items = $this->channelRepository->getList($domainId, $perPage, $offset);

        foreach ($items as &$item) {
            $item['template_count'] = $this->templateRepository->countByChannel(
                $domainId,
                (int) $item['channel_id']
            );
        }
        unset($item);

        return [
            'items' => $items,
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    public function saveChannel(int $domainId, array $data): Result
    {
        $channelId = (int) ($data['channel_id'] ?? 0);
        $channelName = trim((string) ($data['channel_name'] ?? ''));

        if ($channelName === '') {
            return Result::failure('채널명은 필수 항목입니다.');
        }

        $payload = [
            'channel_name' => $channelName,
            'sender_number' => trim((string) ($data['sender_number'] ?? '')),
            'variables' => $this->normalizeVariablesJson($data['variables'] ?? ''),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($channelId > 0) {
            $updated = $this->channelRepository->update($domainId, $channelId, $payload);
            if (!$updated) {
                return Result::failure('채널 수정에 실패했습니다.');
            }
            return Result::success('채널이 수정되었습니다.', ['channel_id' => $channelId]);
        }

        $newId = $this->channelRepository->create($domainId, $payload);
        if ($newId <= 0) {
            return Result::failure('채널 생성에 실패했습니다.');
        }

        return Result::success('채널이 저장되었습니다.', ['channel_id' => $newId]);
    }

    public function deleteChannel(int $domainId, int $channelId): Result
    {
        $templateCount = $this->templateRepository->countByChannel($domainId, $channelId);
        if ($templateCount > 0) {
            return Result::failure("이 채널에 {$templateCount}개의 템플릿이 있습니다. 템플릿을 먼저 삭제해주세요.");
        }

        $deleted = $this->channelRepository->delete($domainId, $channelId);

        return $deleted
            ? Result::success('채널이 삭제되었습니다.')
            : Result::failure('채널 삭제에 실패했습니다.');
    }

    // === 템플릿 관리 ===

    public function getTemplatesByChannel(int $domainId, int $channelId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $totalItems = $this->templateRepository->countByChannel($domainId, $channelId);

        return [
            'items' => $this->templateRepository->getByChannel($domainId, $channelId, $perPage, $offset),
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    public function saveTemplate(int $domainId, array $data): Result
    {
        $templateId = (int) ($data['template_id'] ?? 0);
        $channelId = (int) ($data['channel_id'] ?? 0);
        $templateCode = trim((string) ($data['template_code'] ?? ''));
        $templateName = trim((string) ($data['template_name'] ?? ''));
        $messageType = strtoupper(trim((string) ($data['message_type'] ?? 'SMS')));

        if ($channelId <= 0) {
            return Result::failure('채널을 선택해주세요.');
        }

        $channel = $this->channelRepository->findById($domainId, $channelId);
        if ($channel === null) {
            return Result::failure('존재하지 않는 채널입니다.');
        }

        if ($templateCode === '') {
            return Result::failure('템플릿 코드는 필수 항목입니다.');
        }

        if ($templateName === '') {
            return Result::failure('템플릿명은 필수 항목입니다.');
        }

        if (!in_array($messageType, self::ALLOWED_MESSAGE_TYPES, true)) {
            return Result::failure('유효하지 않은 메시지 타입입니다.');
        }

        if ($this->templateRepository->existsByCode($domainId, $templateCode, $templateId)) {
            return Result::failure('이미 사용 중인 템플릿 코드입니다.');
        }

        $adminRecipients = $this->normalizeAdminRecipients($data['admin_recipients'] ?? '');

        $payload = [
            'channel_id' => $channelId,
            'template_code' => $templateCode,
            'template_name' => $templateName,
            'message_type' => $messageType,
            'title' => trim((string) ($data['title'] ?? '')),
            'message_body' => trim((string) ($data['message_body'] ?? '')),
            'image_ids' => $this->normalizeImageIds($data['image_ids'] ?? ''),
            'variable_schema' => $this->normalizeVariablesJson($data['variable_schema'] ?? ''),
            'admin_recipients' => $adminRecipients,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
        ];

        if ($templateId > 0) {
            $updated = $this->templateRepository->update($domainId, $templateId, $payload);
            if (!$updated) {
                return Result::failure('템플릿 수정에 실패했습니다.');
            }
            return Result::success('템플릿이 수정되었습니다.', ['template_id' => $templateId]);
        }

        $newId = $this->templateRepository->create($domainId, $payload);
        if ($newId <= 0) {
            return Result::failure('템플릿 생성에 실패했습니다.');
        }

        return Result::success('템플릿이 저장되었습니다.', ['template_id' => $newId]);
    }

    public function deleteTemplate(int $domainId, int $templateId): Result
    {
        $deleted = $this->templateRepository->delete($domainId, $templateId);

        return $deleted
            ? Result::success('템플릿이 삭제되었습니다.')
            : Result::failure('템플릿 삭제에 실패했습니다.');
    }

    // === 메시지 발송 ===

    public function send(int $domainId, string $templateCode, string $recipient, array $fieldValues, ?string $reservedAt = null): Result
    {
        $template = $this->templateRepository->findByCode($domainId, $templateCode);
        if ($template === null) {
            return Result::failure("템플릿을 찾을 수 없습니다: {$templateCode}");
        }

        if (empty($template['is_active'])) {
            return Result::failure("비활성 템플릿입니다: {$templateCode}");
        }

        $channelId = (int) ($template['channel_id'] ?? 0);
        $channel = $channelId > 0 ? $this->channelRepository->findById($domainId, $channelId) : null;

        if ($channel === null) {
            return Result::failure('템플릿에 연결된 채널을 찾을 수 없습니다.');
        }

        $config = $this->getConfig($domainId);
        if (empty($config['is_active'])) {
            return Result::failure('SMS 발송이 비활성화되어 있습니다.');
        }

        if (empty($config['api_id']) || empty($config['api_password'])) {
            return Result::failure('센드온 API 인증 정보가 설정되지 않았습니다.');
        }

        // 변수 치환
        $variableMapping = $this->resolveVariables($channel, $template);
        $messageType = $template['message_type'] ?? 'SMS';

        if (!empty($variableMapping)) {
            $message = $this->substituteWithMapping($template['message_body'] ?? '', $variableMapping, $fieldValues);
            $title = $this->substituteWithMapping($template['title'] ?? '', $variableMapping, $fieldValues);
        } else {
            $message = $this->substituteMessage($template['message_body'] ?? '', $fieldValues);
            $title = $this->substituteMessage($template['title'] ?? '', $fieldValues);
        }

        // 미치환 토큰 감지
        $unresolvedTokens = $this->detectUnsubstitutedTokens($message . $title);
        if (!empty($unresolvedTokens)) {
            $errorMessage = '미치환 변수가 있습니다: ' . implode(', ', $unresolvedTokens);

            $this->logRepository->create($domainId, [
                'message_type' => $messageType,
                'template_code' => $templateCode,
                'recipient' => $recipient,
                'result_code' => 'UNRESOLVED_VARS',
                'result_message' => $errorMessage,
                'request_payload' => json_encode(['message' => $message, 'title' => $title], JSON_UNESCAPED_UNICODE),
            ]);

            return Result::failure($errorMessage);
        }

        // API 발송
        $client = new SendonApiClient(
            apiId: $config['api_id'],
            apiPassword: $config['api_password'],
            testMode: !empty($config['test_mode'])
        );

        $senderNumber = $channel['sender_number'] ?: $config['default_sender'];

        $apiParams = [
            'type' => $messageType,
            'from' => $senderNumber,
            'to' => [$recipient],
            'message' => $message,
        ];

        if ($messageType !== 'SMS' && $title !== '') {
            $apiParams['title'] = $title;
        }

        if ($messageType === 'MMS' && !empty($template['image_ids'])) {
            $imageIds = json_decode($template['image_ids'], true);
            if (is_array($imageIds) && !empty($imageIds)) {
                $apiParams['images'] = $imageIds;
            }
        }

        $isReservation = false;
        if ($reservedAt !== null && $reservedAt !== '') {
            $apiParams['reservation'] = ['datetime' => $reservedAt];
            $isReservation = true;
        }

        $apiResult = $client->sendMessage($apiParams);

        $resultCode = (string) ($apiResult['code'] ?? '');
        $resultMessage = (string) ($apiResult['message'] ?? '');
        $groupId = (string) ($apiResult['data']['groupId'] ?? '');
        $isSuccess = ((int) $resultCode) === 200;

        $this->logRepository->create($domainId, [
            'message_type' => $messageType,
            'template_code' => $templateCode,
            'recipient' => $recipient,
            'group_id' => $groupId,
            'is_reservation' => $isReservation ? 1 : 0,
            'reserved_at' => $isReservation ? $reservedAt : null,
            'result_code' => $resultCode,
            'result_message' => $resultMessage,
            'request_payload' => json_encode($apiParams, JSON_UNESCAPED_UNICODE),
            'response_payload' => json_encode($apiResult, JSON_UNESCAPED_UNICODE),
        ]);

        // 관리자 CC 발송
        if ($isSuccess && !empty($template['admin_recipients'])) {
            $adminNumbers = $this->parseAdminRecipients($template['admin_recipients']);
            foreach ($adminNumbers as $adminNumber) {
                if ($adminNumber === $recipient) {
                    continue;
                }

                $adminParams = $apiParams;
                $adminParams['to'] = [$adminNumber];
                unset($adminParams['reservation']);

                $adminResult = $client->sendMessage($adminParams);

                $this->logRepository->create($domainId, [
                    'message_type' => $messageType,
                    'template_code' => $templateCode,
                    'recipient' => $adminNumber,
                    'group_id' => (string) ($adminResult['data']['groupId'] ?? ''),
                    'result_code' => (string) ($adminResult['code'] ?? ''),
                    'result_message' => (string) ($adminResult['message'] ?? ''),
                    'request_payload' => json_encode(array_merge($adminParams, ['admin_copy' => true]), JSON_UNESCAPED_UNICODE),
                    'response_payload' => json_encode($adminResult, JSON_UNESCAPED_UNICODE),
                ]);
            }
        }

        return $isSuccess
            ? Result::success('메시지가 발송되었습니다.', ['group_id' => $groupId])
            : Result::failure('발송 실패: ' . $resultMessage);
    }

    // === 발송 이력 ===

    public function getLogs(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $totalItems = $this->logRepository->countByDomain($domainId);

        return [
            'items' => $this->logRepository->getList($domainId, $perPage, $offset),
            'pagination' => [
                'totalItems' => $totalItems,
                'perPage' => $perPage,
                'currentPage' => $page,
                'totalPages' => max(1, (int) ceil($totalItems / $perPage)),
            ],
        ];
    }

    // === 센드온 잔액 조회 ===

    public function getSendonBalance(int $domainId): Result
    {
        $config = $this->getConfig($domainId);

        if (empty($config['api_id']) || empty($config['api_password'])) {
            return Result::failure('API 인증 정보가 설정되지 않았습니다.');
        }

        $client = new SendonApiClient(
            apiId: $config['api_id'],
            apiPassword: $config['api_password'],
            testMode: !empty($config['test_mode'])
        );

        $response = $client->getPointBalance();
        $code = (int) ($response['code'] ?? 0);

        if ($code !== 200) {
            return Result::failure($response['message'] ?? '잔액 조회에 실패했습니다.');
        }

        return Result::success('잔액 조회 완료', $response['data'] ?? []);
    }

    // === 센드온 발신번호 동기화 ===

    public function fetchSenderNumbers(int $domainId): Result
    {
        $config = $this->getConfig($domainId);

        if (empty($config['api_id']) || empty($config['api_password'])) {
            return Result::failure('API 인증 정보가 설정되지 않았습니다.');
        }

        $client = new SendonApiClient(
            apiId: $config['api_id'],
            apiPassword: $config['api_password'],
            testMode: !empty($config['test_mode'])
        );

        $response = $client->getSenderNumbers();
        $code = (int) ($response['code'] ?? 0);

        if ($code !== 200) {
            return Result::failure($response['message'] ?? '발신번호 조회에 실패했습니다.');
        }

        $senders = $response['data'] ?? [];
        $existingChannels = $this->channelRepository->getList($domainId, 100, 0);
        $registeredNumbers = array_column($existingChannels, 'sender_number');

        return Result::success('발신번호 목록을 가져왔습니다.', [
            'senders' => $senders,
            'registeredNumbers' => $registeredNumbers,
        ]);
    }

    // === 변수 치환 (알림 게이트웨이 공통 패턴) ===

    private function resolveVariables(array $channel, array $template): array
    {
        $templateVars = $this->parseVariablesJson($template['variable_schema'] ?? '');
        if (!empty($templateVars)) {
            return $templateVars;
        }
        return $this->parseVariablesJson($channel['variables'] ?? '');
    }

    public function substituteMessage(string $body, array $fieldValues): string
    {
        if ($body === '' || empty($fieldValues)) {
            return $body;
        }
        $replacements = [];
        foreach ($fieldValues as $key => $value) {
            $replacements['#{' . $key . '}'] = (string) $value;
        }
        return strtr($body, $replacements);
    }

    public function substituteWithMapping(string $body, array $variableMapping, array $fieldValues): string
    {
        if ($body === '' || empty($variableMapping)) {
            return $body;
        }
        $replacements = [];
        foreach ($variableMapping as $key => $field) {
            if (array_key_exists($field, $fieldValues)) {
                $replacements['#{' . $key . '}'] = (string) $fieldValues[$field];
            }
        }
        return empty($replacements) ? $body : strtr($body, $replacements);
    }

    private function detectUnsubstitutedTokens(string $body): array
    {
        if ($body === '') {
            return [];
        }
        if (preg_match_all('/#{[^}]+}/', $body, $matches)) {
            return array_unique($matches[0]);
        }
        return [];
    }

    private function parseVariablesJson(string $json): array
    {
        if ($json === '' || $json === 'null') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $mapping = [];
        foreach ($decoded as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            $field = trim((string) ($item['field'] ?? ''));
            if ($key !== '' && $field !== '') {
                $mapping[$key] = $field;
            }
        }
        return $mapping;
    }

    private function normalizeVariablesJson(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || $input === '[]') {
            return null;
        }
        $decoded = json_decode($input, true);
        if (!is_array($decoded)) {
            return null;
        }
        $cleaned = [];
        foreach ($decoded as $item) {
            $key = trim((string) ($item['key'] ?? ''));
            if ($key !== '') {
                $cleaned[] = ['key' => $key, 'field' => trim((string) ($item['field'] ?? ''))];
            }
        }
        return !empty($cleaned) ? json_encode($cleaned, JSON_UNESCAPED_UNICODE) : null;
    }

    private function normalizeImageIds(string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || $input === '[]') {
            return null;
        }
        $decoded = json_decode($input, true);
        return (is_array($decoded) && !empty($decoded)) ? json_encode(array_values($decoded)) : null;
    }

    private function normalizeAdminRecipients(string $input): string
    {
        if (trim($input) === '') {
            return '';
        }
        $numbers = explode(',', $input);
        $cleaned = [];
        foreach ($numbers as $number) {
            $number = preg_replace('/[\s\-]/', '', trim($number));
            if ($number !== '') {
                $cleaned[] = $number;
            }
        }
        return implode(',', array_unique($cleaned));
    }

    private function parseAdminRecipients(string $input): array
    {
        if (trim($input) === '') {
            return [];
        }
        $numbers = explode(',', $input);
        $result = [];
        foreach ($numbers as $number) {
            $number = preg_replace('/[\s\-]/', '', trim($number));
            if ($number !== '') {
                $result[] = $number;
            }
        }
        return array_unique($result);
    }
}
