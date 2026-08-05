<?php
declare(strict_types=1);
namespace Mublo\Plugin\SnsLogin\Service;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

/**
 * 제공자 측 연결 폐기와 로컬 SNS 계정 정리를 한 경로로 통일한다.
 *
 * 외부 폐기는 되돌릴 수 없다. 그래서 코어가 소유한 회원 탈퇴·삭제 흐름에서는
 * 로컬 확정(커밋) 뒤에만 폐기를 시도하고, 폐기 실패로 그 흐름을 되돌리지 않는다.
 * 실패한 연결은 행에 표시만 남긴다 — 재시도에 쓸 토큰이 그 행에 들어 있으므로
 * 지우지 않고, 관리자가 SNS 연동 내역에서 다시 해제할 수 있게 한다.
 */
class SnsConnectionManager
{
    public function __construct(
        private SnsAccountRepository $accounts,
        private SnsProviderRegistry $providers,
        private Logger $logger,
    ) {}

    /**
     * 하드 삭제처럼 로컬 행이 곧 사라지는 흐름에서, 폐기에 쓸 스냅샷을 미리 확보한다.
     *
     * 조회 실패로 코어 흐름을 막지 않는다 — 폐기만 포기하고 로그를 남긴다.
     *
     * @return SnsAccount[]
     */
    public function captureAccounts(int $memberId): array
    {
        try {
            return $this->accounts->findByMember($memberId);
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $memberId,
                'action' => 'capture_member_connections',
            ]);
            return [];
        }
    }

    /**
     * 탈퇴가 확정된 회원의 외부 연결을 폐기하고 로컬 행을 정리한다.
     *
     * 탈퇴는 소프트 삭제라 FK CASCADE 가 걸리지 않으므로, 암호화 토큰을 남기지 않으려면
     * 이 경로가 유일한 정리 수단이다. 폐기에 성공한 연결만 삭제하고,
     * 실패한 연결은 재시도 대상으로 표시해 남긴다.
     *
     * @return array{revoked: int, failed: int}
     */
    public function revokeAndCleanupForMember(int $memberId): array
    {
        $revoked = 0;
        $failed  = 0;

        foreach ($this->captureAccounts($memberId) as $account) {
            if ($this->revokeAndRecord($account)->isFailure()) {
                $failed++;
                continue;
            }

            try {
                $this->accounts->deleteById($account->getId(), $account->getDomainId());
                $revoked++;
            } catch (\Throwable $e) {
                // 외부 폐기는 이미 끝났다. 행만 남으므로 재시도 시 폐기가 한 번 더 갈 뿐이다.
                $this->logger->exception($e, context: [
                    'member_id' => $account->getMemberId(),
                    'provider' => $account->getProvider(),
                    'action' => 'delete_revoked_connection',
                ]);
            }
        }

        return ['revoked' => $revoked, 'failed' => $failed];
    }

    /**
     * 회원 행이 이미 삭제된 뒤, 확보해 둔 스냅샷으로 외부 연결만 폐기한다.
     *
     * 로컬 행은 FK CASCADE 로 함께 사라졌다. 표시를 남길 대상이 없으므로
     * 실패는 로그로만 추적된다 — 운영자가 제공자 콘솔에서 직접 처리할 수 있도록
     * provider_uid 까지 남긴다.
     *
     * @param SnsAccount[] $accounts
     * @return array{revoked: int, failed: int}
     */
    public function revokeDetachedAccounts(array $accounts): array
    {
        $revoked = 0;
        $failed  = 0;

        foreach ($accounts as $account) {
            if ($this->revoke($account)->isSuccess()) {
                $revoked++;
                continue;
            }

            $failed++;
            $this->logger->error('삭제된 회원의 SNS 연결 폐기 실패 — 제공자 측 연결이 남아 있음', [
                'member_id' => $account->getMemberId(),
                'provider' => $account->getProvider(),
                'provider_uid' => $account->getProviderUid(),
            ]);
        }

        return ['revoked' => $revoked, 'failed' => $failed];
    }

    /** 외부 연결을 폐기한 뒤 해당 로컬 연결 정보도 삭제한다. */
    public function revokeAndDelete(int $memberId, string $provider): Result
    {
        try {
            $account = $this->accounts->findByMemberAndProvider($memberId, $provider);
        } catch (\Throwable $e) {
            return $this->repositoryFailure($e, $memberId, 'load_connection');
        }
        if ($account === null) {
            return Result::failure('연결된 계정이 없습니다.');
        }

        $result = $this->revokeAndRecord($account);
        if ($result->isFailure()) {
            return $result;
        }

        try {
            $deleted = $this->accounts->deleteByMemberAndProvider($memberId, $provider);
        } catch (\Throwable $e) {
            return $this->repositoryFailure($e, $memberId, 'delete_connection');
        }
        if (!$deleted) {
            return Result::failure('SNS 연결 정보 정리에 실패했습니다.');
        }

        return Result::success('SNS 제공자와의 연결이 해제되었습니다.');
    }

    /** 관리자 화면에서 선택한 연결도 외부 제공자를 먼저 해제한다. */
    public function revokeAndDeleteById(int $id, int $domainId): Result
    {
        try {
            $account = $this->accounts->findByIdAndDomain($id, $domainId);
        } catch (\Throwable $e) {
            return $this->repositoryFailure($e, 0, 'load_admin_connection');
        }
        if ($account === null) {
            return Result::failure('연결된 계정이 없습니다.');
        }

        $result = $this->revokeAndRecord($account);
        if ($result->isFailure()) {
            return $result;
        }

        try {
            $deleted = $this->accounts->deleteById($id, $domainId);
        } catch (\Throwable $e) {
            return $this->repositoryFailure($e, $account->getMemberId(), 'delete_admin_connection');
        }
        if (!$deleted) {
            return Result::failure('SNS 연결 정보 정리에 실패했습니다.');
        }

        return Result::success('SNS 제공자와의 연결이 해제되었습니다.');
    }

    /**
     * 폐기를 시도하고, 실패하면 그 사실을 행에 남긴다.
     *
     * 로컬 행이 살아 있는 모든 경로(탈퇴 정리·본인 해제·관리자 해제)의 공통 진입점이다.
     */
    private function revokeAndRecord(SnsAccount $account): Result
    {
        $result = $this->revoke($account);
        if ($result->isSuccess()) {
            return $result;
        }

        try {
            $this->accounts->markRevokeFailed($account->getId(), $result->getMessage());
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $account->getMemberId(),
                'provider' => $account->getProvider(),
                'action' => 'mark_revoke_failed',
            ]);
        }

        return $result;
    }

    private function revoke(SnsAccount $account): Result
    {
        $providerName = $account->getProvider();
        $provider = $this->providers->get($providerName);

        if (!$provider instanceof RevocableSnsProviderInterface) {
            $this->logger->error('SNS 연결 해제 제공자를 사용할 수 없음', [
                'member_id' => $account->getMemberId(),
                'provider' => $providerName,
            ]);
            return Result::failure("{$providerName} 연결 해제 설정을 확인해주세요.");
        }

        try {
            $provider->revokeConnection($account);
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $account->getMemberId(),
                'provider' => $providerName,
                'action' => 'revoke_connection',
            ]);
            return Result::failure("{$provider->getLabel()} 연결 해제에 실패했습니다. 잠시 후 다시 시도해주세요.");
        }

        $this->logger->info('SNS 제공자 연결 해제 완료', [
            'member_id' => $account->getMemberId(),
            'provider' => $providerName,
        ]);

        return Result::success('SNS 연결이 해제되었습니다.');
    }

    private function repositoryFailure(\Throwable $e, int $memberId, string $action): Result
    {
        $this->logger->exception($e, context: [
            'member_id' => $memberId,
            'action' => $action,
        ]);

        return Result::failure('SNS 연결 정보를 처리하지 못했습니다. 잠시 후 다시 시도해주세요.');
    }
}
