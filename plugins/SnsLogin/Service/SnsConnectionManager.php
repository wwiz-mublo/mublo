<?php
namespace Mublo\Plugin\SnsLogin\Service;

use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Log\Logger;
use Mublo\Plugin\SnsLogin\Contract\RevocableSnsProviderInterface;
use Mublo\Plugin\SnsLogin\Entity\SnsAccount;
use Mublo\Plugin\SnsLogin\Repository\SnsAccountRepository;
use Mublo\Plugin\SnsLogin\SnsProviderRegistry;

/**
 * 제공자 측 연결 폐기와 로컬 SNS 계정 정리를 한 경로로 통일한다.
 */
class SnsConnectionManager
{
    public function __construct(
        private SnsAccountRepository $accounts,
        private SnsProviderRegistry $providers,
        private Logger $logger,
    ) {}

    /** 회원의 모든 외부 SNS 연결을 폐기하되 로컬 행은 아직 보존한다. */
    public function revokeAllForMember(int $memberId): Result
    {
        try {
            $accounts = $this->accounts->findByMember($memberId);
        } catch (\Throwable $e) {
            $this->logger->exception($e, context: [
                'member_id' => $memberId,
                'action' => 'load_member_connections',
            ]);
            return Result::failure('SNS 연결 정보를 확인하지 못했습니다. 잠시 후 다시 시도해주세요.');
        }

        foreach ($accounts as $account) {
            $result = $this->revoke($account);
            if ($result->isFailure()) {
                return $result;
            }
        }

        return Result::success('SNS 연결이 해제되었습니다.');
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

        $result = $this->revoke($account);
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

        $result = $this->revoke($account);
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

    /** 탈퇴 완료 후 암호화 토큰을 포함한 플러그인 소유 데이터를 제거한다. */
    public function deleteLocalConnections(int $memberId): int
    {
        return $this->accounts->deleteByMember($memberId);
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
