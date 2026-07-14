<?php

namespace Mublo\Plugin\Popup\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\Popup\Repository\PopupRepository;
use Mublo\Plugin\Popup\Repository\PopupConfigRepository;

class PopupService
{
    public function __construct(
        private PopupRepository $repository,
        private PopupConfigRepository $configRepository
    ) {
    }

    // === 설정 ===

    public function getConfig(int $domainId): array
    {
        $config = $this->configRepository->findByDomainId($domainId);

        return array_merge([
            'popup_skin' => 'basic',
        ], $config ?? []);
    }

    public function saveConfig(int $domainId, array $data): Result
    {
        $this->configRepository->upsert($domainId, $data);
        return Result::success('설정이 저장되었습니다.');
    }

    /**
     * 현재 도메인의 팝업 스킨 경로 반환
     */
    public function getSkinPath(int $domainId): string
    {
        $config = $this->getConfig($domainId);
        $skin = $config['popup_skin'] ?? 'basic';

        $skinPath = MUBLO_PLUGIN_PATH . '/Popup/views/Front/skins/' . $skin . '/popup.php';
        if (!file_exists($skinPath)) {
            $skinPath = MUBLO_PLUGIN_PATH . '/Popup/views/Front/skins/basic/popup.php';
        }

        return $skinPath;
    }

    public function getList(int $domainId, int $page = 1, int $perPage = 20, string $search = ''): array
    {
        return $this->repository->findPaginated($domainId, $page, $perPage, $search);
    }

    public function getPopup(int $domainId, int $popupId): ?array
    {
        return $this->repository->findById($domainId, $popupId);
    }

    public function create(int $domainId, array $data): Result
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::failure('제목은 필수 항목입니다.');
        }

        $data['title'] = $title;
        $popupId = $this->repository->create($domainId, $data);

        if ($popupId <= 0) {
            return Result::failure('팝업 생성에 실패했습니다.');
        }

        return Result::success('팝업이 등록되었습니다.', ['popup_id' => $popupId]);
    }

    public function update(int $domainId, int $popupId, array $data): Result
    {
        $existing = $this->repository->findById($domainId, $popupId);
        if ($existing === null) {
            return Result::failure('팝업을 찾을 수 없습니다.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::failure('제목은 필수 항목입니다.');
        }

        $data['title'] = $title;
        $updated = $this->repository->update($domainId, $popupId, $data);

        return $updated
            ? Result::success('팝업이 수정되었습니다.', ['popup_id' => $popupId])
            : Result::failure('팝업 수정에 실패했습니다.');
    }

    public function delete(int $domainId, int $popupId): Result
    {
        $existing = $this->repository->findById($domainId, $popupId);
        if ($existing === null) {
            return Result::failure('팝업을 찾을 수 없습니다.');
        }

        $deleted = $this->repository->delete($domainId, $popupId);

        return $deleted
            ? Result::success('팝업이 삭제되었습니다.')
            : Result::failure('팝업 삭제에 실패했습니다.');
    }

    public function updateOrder(int $domainId, array $orders): Result
    {
        foreach ($orders as $item) {
            $popupId = (int) ($item['id'] ?? 0);
            $order = (int) ($item['order'] ?? 0);
            if ($popupId > 0) {
                $this->repository->updateSortOrder($domainId, $popupId, $order);
            }
        }

        return Result::success('순서가 변경되었습니다.');
    }

    /**
     * 프론트 표시용: 메인 페이지 활성 팝업 목록
     */
    public function getActivePopups(int $domainId): array
    {
        return $this->repository->findActiveForPage($domainId);
    }
}
