<?php

namespace Mublo\Service\System;

use Mublo\Contract\DataResettableInterface;
use Mublo\Contract\DataResetFilesystemInterface;
use Mublo\Contract\DataResetResult;
use Mublo\Core\Extension\ExtensionManager;
use Mublo\Core\Result\Result;
use Mublo\Infrastructure\Database\Database;
use Mublo\Repository\Member\MemberRepository;
use Mublo\Service\Extension\ExtensionService;

/**
 * DataResetService
 *
 * 데이터 초기화 오케스트레이션 서비스
 * - 초기화 가능 항목 수집 (Core + 활성 Plugin/Package)
 * - 비밀번호 검증
 * - 트랜잭션 래핑 실행
 */
class DataResetService
{
    private Database $db;
    private MemberRepository $memberRepository;
    private ExtensionService $extensionService;
    private CoreDataResetter $coreDataResetter;
    private ExtensionManager $extensionManager;

    public function __construct(
        Database $db,
        MemberRepository $memberRepository,
        ExtensionService $extensionService,
        CoreDataResetter $coreDataResetter,
        ExtensionManager $extensionManager
    ) {
        $this->db = $db;
        $this->memberRepository = $memberRepository;
        $this->extensionService = $extensionService;
        $this->coreDataResetter = $coreDataResetter;
        $this->extensionManager = $extensionManager;
    }

    /**
     * 초기화 가능 항목 수집
     *
     * Core + 활성 Plugin/Package Provider 중 DataResettableInterface 구현체를 탐색
     *
     * @return array [['source' => 'core', 'name' => 'Core', 'categories' => [...], 'resetter' => DataResettableInterface]]
     */
    public function getResetItems(int $domainId): array
    {
        $items = [];

        // Core 항목
        $items[] = [
            'source' => 'core',
            'name' => 'Core',
            'categories' => $this->decorateCategories(
                'core',
                'Core',
                $this->coreDataResetter->getResetCategories()
            ),
            'resetter' => $this->coreDataResetter,
        ];

        // Plugin 항목
        $enabledPlugins = $this->extensionService->getEnabledPlugins($domainId);
        foreach ($enabledPlugins as $name) {
            $provider = $this->resolveProvider('plugin', $name);
            if ($provider instanceof DataResettableInterface) {
                $categories = $this->decorateCategories(
                    'plugin',
                    $name,
                    $provider->getResetCategories()
                );
                if (!empty($categories)) {
                    $items[] = [
                        'source' => 'plugin',
                        'name' => $name,
                        'categories' => $categories,
                        'resetter' => $provider,
                    ];
                }
            }
        }

        // Package 항목
        $enabledPackages = $this->extensionService->getEnabledPackages($domainId);
        foreach ($enabledPackages as $name) {
            $provider = $this->resolveProvider('package', $name);
            if ($provider instanceof DataResettableInterface) {
                $categories = $this->decorateCategories(
                    'package',
                    $name,
                    $provider->getResetCategories()
                );
                if (!empty($categories)) {
                    $items[] = [
                        'source' => 'package',
                        'name' => $name,
                        'categories' => $categories,
                        'resetter' => $provider,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * 비밀번호 검증
     */
    public function verifyPassword(int $memberId, string $password): bool
    {
        $member = $this->memberRepository->find($memberId);
        if (!$member) {
            return false;
        }

        return password_verify($password, $member->getPassword());
    }

    /**
     * 항목별 초기화 실행
     */
    public function resetCategory(string $categoryId, int $domainId, int $memberId, string $password): Result
    {
        // 비밀번호 검증
        if (!$this->verifyPassword($memberId, $password)) {
            return Result::failure('비밀번호가 일치하지 않습니다.');
        }

        // SUPER 회원 재확인
        $member = $this->memberRepository->find($memberId);
        if (!$member || !$member->isSuper()) {
            return Result::failure('SUPER 관리자만 데이터를 초기화할 수 있습니다.');
        }

        // Resetter와 카테고리 찾기
        $resetItems = $this->getResetItems($domainId);
        $targetResetter = null;
        $targetCategoryKey = null;
        $targetLabel = '';

        foreach ($resetItems as $item) {
            foreach ($item['categories'] as $cat) {
                if ($cat['id'] === $categoryId) {
                    $targetResetter = $item['resetter'];
                    $targetCategoryKey = $cat['key'];
                    $targetLabel = $cat['label'];
                    break 2;
                }
            }
        }

        if (!$targetResetter || $targetCategoryKey === null) {
            return Result::failure('초기화 대상을 찾을 수 없습니다.');
        }

        // 트랜잭션 내 실행
        try {
            $this->db->beginTransaction();
            $result = $targetResetter->reset($targetCategoryKey, $domainId);
            $this->db->commit();

            $result = $this->withDeletedFiles(
                $result,
                $targetResetter,
                $targetCategoryKey,
                $domainId
            );

            $this->writeResetLog('category', $categoryId, $targetLabel, $domainId, $memberId, $result);

            $message = "{$targetLabel} 데이터가 초기화되었습니다.";
            if (str_contains($result->details, '일부 파일 정리 실패')) {
                $message .= ' 일부 파일은 정리하지 못했습니다.';
            }
            return Result::success(
                $message,
                $result->toArray()
            );
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("[DataReset] Error: category={$categoryId}, domain={$domainId} — " . $e->getMessage());
            return Result::failure('초기화 중 오류가 발생했습니다. 관리자 로그를 확인해주세요.');
        }
    }

    /**
     * 전체 초기화 실행
     */
    public function resetAll(int $domainId, int $memberId, string $password, string $confirmText): Result
    {
        // 비밀번호 검증
        if (!$this->verifyPassword($memberId, $password)) {
            return Result::failure('비밀번호가 일치하지 않습니다.');
        }

        // 확인 문구 검증
        if ($confirmText !== '전체 초기화') {
            return Result::failure('확인 문구가 일치하지 않습니다.');
        }

        // SUPER 재확인
        $member = $this->memberRepository->find($memberId);
        if (!$member || !$member->isSuper()) {
            return Result::failure('SUPER 관리자만 전체 초기화를 수행할 수 있습니다.');
        }

        $resetItems = $this->orderedResetItems($this->getResetItems($domainId));
        $totalResult = ['tables_cleared' => 0, 'files_deleted' => 0, 'categories' => [], 'warnings' => []];
        $completed = [];

        try {
            $this->db->beginTransaction();

            foreach ($resetItems as $item) {
                foreach ($item['categories'] as $cat) {
                    $result = $item['resetter']->reset($cat['key'], $domainId);
                    $totalResult['tables_cleared'] += $result->tablesCleared;
                    $totalResult['categories'][] = $cat['label'];
                    $completed[] = [$item['resetter'], $cat['key'], $result];
                }
            }

            $this->db->commit();

            foreach ($completed as [$resetter, $categoryKey, $result]) {
                $withFiles = $this->withDeletedFiles($result, $resetter, $categoryKey, $domainId);
                $totalResult['files_deleted'] += $withFiles->filesDeleted;
                if ($withFiles->details !== $result->details) {
                    $totalResult['warnings'][] = $withFiles->details;
                }
            }

            $this->writeResetLog('all', 'all', '전체 초기화', $domainId, $memberId, $totalResult);

            $categoriesList = implode(', ', $totalResult['categories']);
            $message = "전체 초기화가 완료되었습니다. ({$categoriesList})";
            if ($totalResult['warnings'] !== []) {
                $message .= ' 일부 파일은 정리하지 못했습니다.';
            }
            return Result::success(
                $message,
                $totalResult
            );
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("[DataReset] resetAll Error: domain={$domainId} — " . $e->getMessage());
            return Result::failure('전체 초기화 중 오류가 발생했습니다. 관리자 로그를 확인해주세요.');
        }
    }

    /**
     * register/boot를 마친 Provider 해석.
     */
    private function resolveProvider(string $type, string $name): ?object
    {
        return $this->extensionManager->getLoadedProvider($type, $name);
    }

    /**
     * 확장 내부 로컬 키를 전역 고유 ID로 감싼다.
     *
     * @param DataResetCategory[] $categories
     * @return array<int, array{id: string, key: string, label: string, description: string, icon: string}>
     */
    private function decorateCategories(string $source, string $name, array $categories): array
    {
        return array_map(
            static function ($category) use ($source, $name): array {
                $data = $category->toArray();
                return ['id' => "{$source}:{$name}:{$category->key}"] + $data;
            },
            $categories
        );
    }

    /**
     * 전체 초기화는 참조 데이터를 먼저, 회원 개인정보를 마지막에 처리한다.
     * 파일 삭제는 이 순서와 별개로 DB 커밋 뒤 수행된다.
     */
    private function orderedResetItems(array $items): array
    {
        $beforeMembers = [];
        $members = [];

        foreach ($items as $item) {
            $ordinary = $item;
            $ordinary['categories'] = [];

            foreach ($item['categories'] as $category) {
                if (($category['include_in_full_reset'] ?? true) !== true) {
                    continue;
                }
                if ($item['source'] === 'core' && $category['key'] === 'members') {
                    $memberItem = $item;
                    $memberItem['categories'] = [$category];
                    $members[] = $memberItem;
                    continue;
                }
                $ordinary['categories'][] = $category;
            }

            if ($ordinary['categories'] !== []) {
                $beforeMembers[] = $ordinary;
            }
        }

        return array_merge($beforeMembers, $members);
    }

    private function withDeletedFiles(
        DataResetResult $result,
        DataResettableInterface $resetter,
        string $category,
        int $domainId
    ): DataResetResult {
        if (!$resetter instanceof DataResetFilesystemInterface) {
            return $result;
        }

        try {
            $deleted = $resetter->resetFiles($category, $domainId);
        } catch (\Throwable $e) {
            error_log(
                "[DataReset] File cleanup failed: category={$category}, domain={$domainId} — "
                . $e->getMessage()
            );
            return new DataResetResult(
                $result->tablesCleared,
                $result->filesDeleted,
                $result->details . ' (DB 초기화 완료, 일부 파일 정리 실패 — 관리자 로그 확인)'
            );
        }
        return new DataResetResult(
            $result->tablesCleared,
            $result->filesDeleted + $deleted,
            $result->details
        );
    }

    /**
     * 초기화 로그 기록
     */
    private function writeResetLog(string $type, string $category, string $label, int $domainId, int $memberId, DataResetResult|array $result): void
    {
        $logDir = MUBLO_STORAGE_PATH . '/logs/reset';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/reset_' . date('Ymd') . '.log';
        $member = $this->memberRepository->find($memberId);
        $userId = $member ? $member->getUserId() : 'unknown';

        $logEntry = sprintf(
            "[%s] type=%s, category=%s, label=%s, domain=%d, member=%s(#%d), tables=%d, files=%d\n",
            date('Y-m-d H:i:s'),
            $type,
            $category,
            $label,
            $domainId,
            $userId,
            $memberId,
            $result instanceof DataResetResult ? $result->tablesCleared : ($result['tables_cleared'] ?? 0),
            $result instanceof DataResetResult ? $result->filesDeleted : ($result['files_deleted'] ?? 0)
        );

        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
}
