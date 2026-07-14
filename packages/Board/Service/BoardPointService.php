<?php
namespace Mublo\Packages\Board\Service;

use Mublo\Contract\Balance\BalanceGatewayInterface;

/**
 * BoardPointService
 *
 * 게시판 포인트 적립/회수/소비 처리
 *
 * Core의 BalanceGatewayInterface를 직접 호출하여 포인트를 관리합니다.
 */
class BoardPointService
{
    private const SOURCE_TYPE = 'package';
    private const SOURCE_NAME = 'Board';

    private const ACTION_MESSAGES = [
        'article_write'     => '게시글 작성 포인트',
        'comment_write'     => '댓글 작성 포인트',
        'reaction_received' => '반응 받기 포인트',
    ];

    private const REVOKE_MESSAGES = [
        'article_write'     => '게시글 삭제 포인트 회수',
        'comment_write'     => '댓글 삭제 포인트 회수',
        'reaction_received' => '반응 취소 포인트 회수',
    ];

    private const CONSUME_MESSAGES = [
        'article_read'  => '게시글 열람',
        'file_download' => '파일 다운로드',
    ];

    public function __construct(
        private BalanceGatewayInterface $balanceManager,
        private BoardPointConfigService $configService,
    ) {}

    /**
     * 포인트 지급
     *
     * $extra 키: reference_type, reference_id, idempotency_key, board_id, group_id, reaction_type
     */
    public function award(int $domainId, int $memberId, string $action, array $extra = []): bool
    {
        $actionConfig = $this->resolveActionConfig($domainId, $action, $extra);

        if (!$actionConfig['enabled'] || ($actionConfig['point'] ?? 0) <= 0) {
            return false;
        }

        $params = [
            'domain_id'   => $domainId,
            'member_id'   => $memberId,
            'amount'      => $actionConfig['point'],
            'source_type' => self::SOURCE_TYPE,
            'source_name' => self::SOURCE_NAME,
            'action'      => $action,
            'message'     => self::ACTION_MESSAGES[$action] ?? $action,
        ];

        if (isset($extra['reference_type'])) {
            $params['reference_type'] = $extra['reference_type'];
        }
        if (isset($extra['reference_id'])) {
            $params['reference_id'] = $extra['reference_id'];
        }
        if (isset($extra['idempotency_key'])) {
            $params['idempotency_key'] = $extra['idempotency_key'];
        }

        $result = $this->balanceManager->adjust($params);
        return $result->isSuccess();
    }

    /**
     * 포인트 회수 (삭제 시)
     */
    public function revoke(int $domainId, int $memberId, string $action, array $extra = []): bool
    {
        $actionConfig = $this->resolveActionConfig($domainId, $action, $extra);

        if (!$actionConfig['enabled'] || !($actionConfig['revoke'] ?? false) || ($actionConfig['point'] ?? 0) <= 0) {
            return false;
        }

        $params = [
            'domain_id'   => $domainId,
            'member_id'   => $memberId,
            'amount'      => -$actionConfig['point'],
            'source_type' => self::SOURCE_TYPE,
            'source_name' => self::SOURCE_NAME,
            'action'      => $action . '_revoke',
            'message'     => self::REVOKE_MESSAGES[$action] ?? $action . ' 회수',
        ];

        if (isset($extra['reference_type'])) {
            $params['reference_type'] = $extra['reference_type'];
        }
        if (isset($extra['reference_id'])) {
            $params['reference_id'] = $extra['reference_id'];
        }
        if (isset($extra['idempotency_key'])) {
            $params['idempotency_key'] = $extra['idempotency_key'];
        }

        $result = $this->balanceManager->adjust($params);
        return $result->isSuccess();
    }

    /**
     * 포인트 소비 (읽기/다운로드 비용)
     *
     * @return array ['success' => bool, 'message' => ?string, 'already_paid' => bool]
     */
    public function consume(int $domainId, int $memberId, string $action, int $boardId, array $extra = []): array
    {
        $groupId = $extra['group_id'] ?? null;
        $actionConfig = $this->configService->getBoardActionConfig(
            $domainId, $boardId, $action, $groupId !== null ? (int) $groupId : null
        );

        if (!$actionConfig['enabled'] || ($actionConfig['point'] ?? 0) <= 0) {
            return ['success' => true, 'already_paid' => false];
        }

        $params = [
            'domain_id'   => $domainId,
            'member_id'   => $memberId,
            'amount'      => -$actionConfig['point'],
            'source_type' => self::SOURCE_TYPE,
            'source_name' => self::SOURCE_NAME,
            'action'      => $action,
            'message'     => self::CONSUME_MESSAGES[$action] ?? $action,
        ];

        if (isset($extra['reference_type'])) {
            $params['reference_type'] = $extra['reference_type'];
        }
        if (isset($extra['reference_id'])) {
            $params['reference_id'] = $extra['reference_id'];
        }
        if (isset($extra['idempotency_key'])) {
            $params['idempotency_key'] = $extra['idempotency_key'];
        }

        $result = $this->balanceManager->adjust($params);

        if ($result->isSuccess()) {
            return [
                'success'      => true,
                'already_paid' => $result->get('idempotent', false),
            ];
        }

        $message = $result->getMessage();
        return ['success' => false, 'message' => $message ?: '포인트 처리 중 오류가 발생했습니다.'];
    }

    /**
     * 액션 설정 resolve (게시판별 → 그룹별 → 도메인 체인)
     */
    private function resolveActionConfig(int $domainId, string $action, array $extra): array
    {
        $boardId = $extra['board_id'] ?? null;
        $groupId = $extra['group_id'] ?? null;

        if ($boardId !== null) {
            $config = $this->configService->getBoardActionConfig(
                $domainId, (int) $boardId, $action, $groupId !== null ? (int) $groupId : null
            );

            // 반응 받기: 반응 타입별 개별 포인트 오버라이드
            if ($action === 'reaction_received' && isset($extra['reaction_type'])) {
                $typePoint = $this->configService->getReactionTypePoint(
                    $domainId, (int) $boardId, (string) $extra['reaction_type']
                );
                if ($typePoint !== null) {
                    $config['point'] = $typePoint;
                }
            }

            return $config;
        }

        return $this->configService->getActionConfig($domainId, $action);
    }
}
