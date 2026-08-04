<?php
declare(strict_types=1);

namespace Mublo\Plugin\SendonTalk\Service;

use Mublo\Contract\Security\SensitiveValueCodecInterface;
use Mublo\Core\Result\Result;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkConfigRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkChannelRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkTemplateRepository;
use Mublo\Plugin\SendonTalk\Repository\SendonTalkLogRepository;

class SendonTalkService
{
    public function __construct(
        private SendonTalkConfigRepository $configRepository,
        private SendonTalkChannelRepository $channelRepository,
        private SendonTalkTemplateRepository $templateRepository,
        private SendonTalkLogRepository $logRepository,
        private ?SensitiveValueCodecInterface $encryptionService = null
    ) {}

    // ═══ 설정 ═══

    public function getConfig(int $domainId): array
    {
        $config = $this->configRepository->getConfig($domainId);
        if (!$config) {
            return ['is_active' => 0, 'test_mode' => 0, 'api_id' => '', 'api_password' => ''];
        }

        if ($this->encryptionService) {
            foreach (['api_id', 'api_password'] as $field) {
                if (!empty($config[$field])) {
                    try { $config[$field] = $this->encryptionService->decrypt($config[$field]); } catch (\Throwable) {}
                }
            }
        }

        return $config;
    }

    public function saveConfig(int $domainId, array $data): Result
    {
        if ($this->encryptionService) {
            foreach (['api_id', 'api_password'] as $field) {
                if (isset($data[$field]) && $data[$field] !== '') {
                    try { $data[$field] = $this->encryptionService->encrypt($data[$field]); } catch (\Throwable) {}
                }
            }
        }

        foreach (['_clear_api_id', '_clear_api_password'] as $clearKey) {
            if (!empty($data[$clearKey])) {
                $realKey = str_replace('_clear_', '', $clearKey);
                $data[$realKey] = '';
            }
            unset($data[$clearKey]);
        }

        $data['test_mode'] = (int) ($data['test_mode'] ?? 0);
        $data['is_active'] = (int) ($data['is_active'] ?? 0);

        // 불필요 필드 제거
        unset($data['send_profile_id'], $data['send_profile_name'], $data['sender_key']);

        $saved = $this->configRepository->saveConfig($domainId, $data);
        return $saved ? Result::success('설정이 저장되었습니다.') : Result::failure('설정 저장에 실패했습니다.');
    }

    // ═══ 채널(발신프로필) ═══

    public function getChannels(int $domainId): array
    {
        return $this->channelRepository->getList($domainId);
    }

    public function fetchSendProfiles(int $domainId): Result
    {
        $config = $this->getConfig($domainId);
        $client = $this->makeClient($config);
        if (!$client) {
            return Result::failure('API 인증 정보가 설정되지 않았습니다.');
        }

        $result = $client->getSendProfiles(100);
        if ($this->isApiError($result)) {
            return Result::failure('채널 조회 실패: ' . $this->extractMessage($result));
        }

        $raw = $result['data'] ?? [];
        $profiles = $raw['sendProfiles'] ?? (is_array($raw) && isset($raw[0]) ? $raw : []);

        return Result::success('채널 목록을 조회했습니다.', ['profiles' => $profiles]);
    }

    public function saveChannel(int $domainId, array $data): Result
    {
        $channelId      = (int) ($data['channel_id'] ?? 0);
        $channelName    = trim($data['channel_name'] ?? '');
        $sendProfileId  = trim($data['send_profile_id'] ?? '');

        if (empty($channelName)) return Result::failure('채널명을 입력해주세요.');
        if (empty($sendProfileId)) return Result::failure('발신프로필 ID를 선택해주세요.');

        $payload = [
            'channel_name'    => $channelName,
            'send_profile_id' => $sendProfileId,
            'variables'       => $data['variables'] ?? null,
            'is_active'       => (int) ($data['is_active'] ?? 1),
        ];

        if ($channelId > 0) {
            $this->channelRepository->update($domainId, $channelId, $payload);
            return Result::success('채널이 수정되었습니다.');
        }

        $newId = $this->channelRepository->create($domainId, $payload);
        return Result::success('채널이 등록되었습니다.', ['channel_id' => $newId]);
    }

    public function deleteChannel(int $domainId, int $channelId): Result
    {
        $tplCount = $this->channelRepository->getTemplateCount($domainId, $channelId);
        if ($tplCount > 0) {
            return Result::failure("이 채널에 {$tplCount}개의 템플릿이 있습니다. 템플릿을 먼저 삭제하세요.");
        }

        $deleted = $this->channelRepository->delete($domainId, $channelId);
        return $deleted ? Result::success('채널이 삭제되었습니다.') : Result::failure('삭제에 실패했습니다.');
    }

    // ═══ 템플릿 ═══

    public function getTemplatesByChannel(int $domainId, int $channelId): array
    {
        return $this->templateRepository->getByChannel($domainId, $channelId);
    }

    public function saveTemplate(int $domainId, array $data): Result
    {
        $templateId   = (int) ($data['template_id'] ?? 0);
        $channelId    = (int) ($data['channel_id'] ?? 0);
        $templateName = trim($data['template_name'] ?? '');

        if ($channelId <= 0) return Result::failure('채널을 선택해주세요.');
        if (empty($templateName)) return Result::failure('템플릿명을 입력해주세요.');
        if (empty(trim($data['message_body'] ?? ''))) return Result::failure('메시지 본문을 입력해주세요.');

        $payload = [
            'channel_id'       => $channelId,
            'template_code'    => trim($data['template_code'] ?? '') ?: $this->generateCode(),
            'template_name'    => $templateName,
            'message_body'     => trim($data['message_body'] ?? ''),
            'buttons'          => !empty($data['buttons'])
                ? (is_string($data['buttons']) ? $data['buttons'] : json_encode($data['buttons'], JSON_UNESCAPED_UNICODE))
                : null,
            'variable_schema'  => $data['variable_schema'] ?? null,
            'admin_recipients' => trim($data['admin_recipients'] ?? ''),
            'fallback_sms'     => (int) ($data['fallback_sms'] ?? 1),
            'is_active'        => (int) ($data['is_active'] ?? 1),
        ];

        if ($templateId > 0) {
            $this->templateRepository->update($templateId, $payload);
            return Result::success('템플릿이 수정되었습니다.');
        }

        $newId = $this->templateRepository->create($domainId, $payload);
        return Result::success('템플릿이 등록되었습니다.', ['template_id' => $newId]);
    }

    public function deleteTemplate(int $domainId, int $templateId): Result
    {
        $deleted = $this->templateRepository->delete($domainId, $templateId);
        return $deleted ? Result::success('삭제되었습니다.') : Result::failure('삭제에 실패했습니다.');
    }

    // ═══ 카카오 심사 ═══

    public function registerKakaoTemplate(int $domainId, int $templateId): Result
    {
        $template = $this->templateRepository->findById($domainId, $templateId);
        if (!$template) return Result::failure('템플릿을 찾을 수 없습니다.');

        $channel = $this->channelRepository->findById($domainId, (int) $template['channel_id']);
        if (!$channel || empty($channel['send_profile_id'])) {
            return Result::failure('채널(발신프로필)을 찾을 수 없습니다.');
        }

        $config = $this->getConfig($domainId);
        $client = $this->makeClient($config);
        if (!$client) return Result::failure('API 인증 정보가 설정되지 않았습니다.');

        $buttons = [];
        if (!empty($template['buttons'])) {
            $parsed = is_string($template['buttons']) ? json_decode($template['buttons'], true) : $template['buttons'];
            if (is_array($parsed)) $buttons = $parsed;
        }

        $params = [
            'templateName'    => $template['template_name'],
            'templateContent' => $template['message_body'],
        ];
        if (!empty($buttons)) $params['buttons'] = $buttons;

        $result = $client->createTemplate($channel['send_profile_id'], $params);

        error_log('[SendonTalk] createTemplate response: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

        if ($this->isApiError($result)) {
            return Result::failure('심사 요청 실패: ' . $this->extractMessage($result), ['api_response' => $result]);
        }

        $kakaoTemplateId   = $result['data']['templateId'] ?? $result['templateId'] ?? $result['id'] ?? '';
        $kakaoTemplateCode = $result['data']['templateCode'] ?? $result['templateCode'] ?? '';

        $this->templateRepository->update($templateId, [
            'kakao_template_id'   => $kakaoTemplateId,
            'kakao_template_code' => $kakaoTemplateCode,
            'kakao_status'        => 'pending',
        ]);

        return Result::success('카카오 심사 요청이 완료되었습니다.', [
            'kakao_template_id' => $kakaoTemplateId,
            'api_response' => $result,
        ]);
    }

    public function checkKakaoTemplateStatus(int $domainId, int $templateId): Result
    {
        $template = $this->templateRepository->findById($domainId, $templateId);
        $kakaoTplId = $template['kakao_template_id'] ?? '';
        if (!$template || empty($kakaoTplId)) {
            return Result::failure('카카오에 등록된 템플릿이 아닙니다.');
        }

        $channel = $this->channelRepository->findById($domainId, (int) $template['channel_id']);
        if (!$channel || empty($channel['send_profile_id'])) {
            return Result::failure('채널을 찾을 수 없습니다.');
        }

        $config = $this->getConfig($domainId);
        $client = $this->makeClient($config);
        if (!$client) return Result::failure('API 인증 정보가 설정되지 않았습니다.');

        $result = $client->getTemplate($channel['send_profile_id'], $kakaoTplId);
        if ($this->isApiError($result)) {
            return Result::failure('상태 조회 실패: ' . $this->extractMessage($result));
        }

        $data   = $result['data'] ?? $result;
        $status = strtoupper($data['status'] ?? $data['templateStatus'] ?? 'UNKNOWN');

        $statusMap = [
            'NEED_REVIEW' => 'pending', 'IN_REVIEW' => 'pending',
            'REG' => 'pending', 'INSPECT' => 'pending',
            'APPROVED' => 'approved', 'COMPLETE' => 'approved',
            'REJECTED' => 'rejected', 'REJECT' => 'rejected',
            'DORMANT' => 'rejected',
        ];
        $mappedStatus = $statusMap[$status] ?? 'draft';

        $update = ['kakao_status' => $mappedStatus];
        if ($mappedStatus === 'rejected') {
            $update['kakao_rejected_reason'] = $data['rejectReason'] ?? $data['comment'] ?? '';
        }
        $this->templateRepository->update($templateId, $update);

        return Result::success('상태가 업데이트되었습니다.', ['status' => $mappedStatus]);
    }

    public function syncKakaoTemplates(int $domainId): Result
    {
        $config = $this->getConfig($domainId);
        $client = $this->makeClient($config);
        if (!$client) return Result::failure('API 인증 정보가 설정되지 않았습니다.');

        $channels = $this->channelRepository->getList($domainId);
        if (empty($channels)) return Result::failure('등록된 채널이 없습니다.');

        $updated = 0;
        $created = 0;
        $apiTotal = 0;
        $errors = [];

        foreach ($channels as $ch) {
            $pid = $ch['send_profile_id'] ?? '';
            if (empty($pid)) continue;

            $result = $client->getTemplates($pid);
            if ($this->isApiError($result)) {
                $errors[] = ($ch['channel_name'] ?? $pid) . ': ' . $this->extractMessage($result);
                continue;
            }

            $raw = $result['data'] ?? [];
            $apiTemplates = $raw['templates'] ?? (is_array($raw) && isset($raw[0]) ? $raw : []);
            if (!is_array($apiTemplates)) continue;

            $apiTotal += count($apiTemplates);
            $apiIds = [];

            foreach ($apiTemplates as $apiTpl) {
                $kakaoTplId = $apiTpl['templateId'] ?? $apiTpl['id'] ?? '';
                $apiIds[] = $kakaoTplId ?: '(empty)';
                if (empty($kakaoTplId)) continue;

                // 1차: kakao_template_id로 매칭
                $local = $this->templateRepository->findByKakaoTemplateId($domainId, $kakaoTplId);

                // 2차: 도메인 전체 템플릿에서 이름 매칭 (kakao_template_id가 비어있는 것만)
                if (!$local) {
                    $apiName = $apiTpl['templateName'] ?? $apiTpl['name'] ?? '';
                    if ($apiName !== '') {
                        $allLocal = $this->templateRepository->getByDomain($domainId, 500, 0);
                        foreach ($allLocal as $lt) {
                            if (!empty($lt['kakao_template_id'])) continue;
                            if ($lt['template_name'] === $apiName) {
                                $local = $lt;
                                // channel_id도 연결
                                if (empty($lt['channel_id']) || (int) $lt['channel_id'] === 0) {
                                    $this->templateRepository->update((int) $lt['template_id'], [
                                        'channel_id' => (int) $ch['channel_id'],
                                    ]);
                                }
                                break;
                            }
                        }
                    }
                }

                $status = strtoupper($apiTpl['status'] ?? $apiTpl['templateStatus'] ?? 'UNKNOWN');
                $statusMap = [
                    'NEED_REVIEW' => 'pending', 'IN_REVIEW' => 'pending',
                    'APPROVED' => 'approved', 'COMPLETE' => 'approved',
                    'REJECTED' => 'rejected', 'REJECT' => 'rejected',
                ];
                $mapped = $statusMap[$status] ?? 'draft';

                if ($local) {
                    // 기존 템플릿 업데이트
                    $updateData = [
                        'kakao_template_id' => $kakaoTplId,
                        'kakao_status' => $mapped,
                    ];
                    if ($mapped === 'rejected') {
                        $updateData['kakao_rejected_reason'] = $apiTpl['rejectReason'] ?? $apiTpl['comment'] ?? '';
                    }
                    $this->templateRepository->update((int) $local['template_id'], $updateData);
                    $updated++;
                } else {
                    // 로컬에 없는 템플릿 → 새로 생성
                    $apiName = $apiTpl['templateName'] ?? $apiTpl['name'] ?? '';
                    $apiContent = $apiTpl['templateContent'] ?? $apiTpl['content'] ?? '';
                    $newData = [
                        'channel_id'          => (int) $ch['channel_id'],
                        'template_code'       => $this->generateCode(),
                        'template_name'       => $apiName ?: ('카카오_' . substr($kakaoTplId, 0, 8)),
                        'message_body'        => $apiContent,
                        'kakao_template_id'   => $kakaoTplId,
                        'kakao_template_code' => $apiTpl['templateCode'] ?? '',
                        'kakao_status'        => $mapped,
                        'kakao_rejected_reason' => ($mapped === 'rejected') ? ($apiTpl['rejectReason'] ?? '') : null,
                        'fallback_sms'        => 1,
                        'is_active'           => ($mapped === 'approved') ? 1 : 0,
                    ];
                    $this->templateRepository->create($domainId, $newData);
                    $created++;
                }
            }
        }

        $msg = "API {$apiTotal}건 조회, {$created}건 신규 등록, {$updated}건 상태 업데이트.";
        if (!empty($errors)) {
            $msg .= ' 오류: ' . implode('; ', $errors);
        }

        return Result::success($msg);
    }

    // ═══ 발송 ═══

    public function send(int $domainId, string $templateCode, string $recipient, array $fieldValues): Result
    {
        $template = $this->templateRepository->findByCode($domainId, $templateCode);
        if (!$template) return Result::failure("템플릿을 찾을 수 없습니다: {$templateCode}");
        if (empty($template['is_active'])) return Result::failure('비활성 템플릿입니다.');

        $config = $this->getConfig($domainId);
        if (empty($config['is_active'])) return Result::failure('알림톡 발송이 비활성화되어 있습니다.');

        $channel = $this->channelRepository->findById($domainId, (int) $template['channel_id']);
        if (!$channel || empty($channel['send_profile_id'])) {
            return Result::failure('채널(발신프로필)을 찾을 수 없습니다.');
        }

        $client = $this->makeClient($config);
        if (!$client) return Result::failure('API 인증 정보가 설정되지 않았습니다.');

        $kakaoTemplateId = $template['kakao_template_id'] ?? '';
        if (empty($kakaoTemplateId)) return Result::failure('카카오 심사 승인된 템플릿이 아닙니다.');

        // 변수 치환
        $message = $this->substituteMessage($template['message_body'] ?? '', $fieldValues);

        if (preg_match_all('/#{[^}]+}/', $message, $matches)) {
            $this->logRepository->create($domainId, [
                'template_code' => $templateCode, 'recipient' => $recipient,
                'message_type' => 'ALIMTALK', 'result_code' => 'UNRESOLVED_VARS',
                'result_message' => '미치환 변수: ' . implode(', ', $matches[0]),
            ]);
            return Result::failure('미치환 변수가 있습니다: ' . implode(', ', $matches[0]));
        }

        $apiParams = [
            'sendProfileId' => $channel['send_profile_id'],
            'templateId'    => $kakaoTemplateId,
            'to'            => [$recipient],
        ];

        if (!empty($template['fallback_sms'])) {
            $apiParams['fallback'] = [
                'type'    => 'LMS',
                'message' => $message,
                'title'   => $template['template_name'] ?? '알림',
            ];
        }

        $apiResult = $client->sendAlimtalk($apiParams);
        $isSuccess     = !$this->isApiError($apiResult);
        $resultCode    = (string) ($apiResult['code'] ?? $apiResult['statusCode'] ?? '');
        $resultMessage = $this->extractMessage($apiResult);
        $groupId       = (string) ($apiResult['data']['groupId'] ?? $apiResult['groupId'] ?? '');

        $this->logRepository->create($domainId, [
            'template_code'    => $templateCode, 'recipient' => $recipient,
            'message_type'     => 'ALIMTALK', 'group_id' => $groupId,
            'is_fallback'      => 0, 'result_code' => $resultCode,
            'result_message'   => $resultMessage,
            'request_payload'  => json_encode($apiParams, JSON_UNESCAPED_UNICODE),
            'response_payload' => json_encode($apiResult, JSON_UNESCAPED_UNICODE),
        ]);

        return $isSuccess
            ? Result::success('알림톡이 발송되었습니다.', ['group_id' => $groupId])
            : Result::failure('발송 실패: ' . $resultMessage);
    }

    // ═══ 발송 이력 ═══

    public function getLogs(int $domainId, int $page = 1, int $perPage = 20): array
    {
        $offset = ($page - 1) * $perPage;
        return [
            'items' => $this->logRepository->getList($domainId, $perPage, $offset),
            'pagination' => [
                'totalItems'  => $this->logRepository->count($domainId),
                'currentPage' => $page,
                'perPage'     => $perPage,
            ],
        ];
    }

    // ═══ Private ═══

    private function makeClient(array $config): ?SendonTalkApiClient
    {
        if (empty($config['api_id']) || empty($config['api_password'])) return null;
        return new SendonTalkApiClient(
            apiId: $config['api_id'],
            apiPassword: $config['api_password'],
            testMode: !empty($config['test_mode'])
        );
    }

    private function isApiError(array $result): bool
    {
        $code = $result['code'] ?? $result['statusCode'] ?? -1;
        return ((int) $code) < 200 || ((int) $code) >= 300;
    }

    private function extractMessage(array $result): string
    {
        $msg = $result['message'] ?? $result['errorMessage'] ?? '';
        return is_array($msg) ? json_encode($msg, JSON_UNESCAPED_UNICODE) : (string) $msg;
    }

    private function substituteMessage(string $body, array $fieldValues): string
    {
        if ($body === '' || empty($fieldValues)) return $body;
        $replacements = [];
        foreach ($fieldValues as $key => $value) {
            $replacements['#{' . $key . '}'] = (string) $value;
        }
        return strtr($body, $replacements);
    }

    private function generateCode(): string
    {
        return 'talk_' . substr(bin2hex(random_bytes(4)), 0, 8);
    }
}
