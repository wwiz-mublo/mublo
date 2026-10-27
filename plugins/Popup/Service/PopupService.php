<?php
declare(strict_types=1);

namespace Mublo\Plugin\Popup\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\Popup\Repository\PopupRepository;
use Mublo\Plugin\Popup\Repository\PopupConfigRepository;

class PopupService
{
    private const INVALID_LINK_MESSAGE = '링크 URL은 http(s)·mailto·tel 주소나 / 로 시작하는 경로만 쓸 수 있습니다.';

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

        $link = $this->normalizeLinkUrl($data['link_url'] ?? null);
        if ($link === false) {
            return Result::failure(self::INVALID_LINK_MESSAGE);
        }

        $data['title'] = $title;
        $data['link_url'] = $link;
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

        $link = $this->normalizeLinkUrl($data['link_url'] ?? null);
        if ($link === false) {
            return Result::failure(self::INVALID_LINK_MESSAGE);
        }

        $data['title'] = $title;
        $data['link_url'] = $link;
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

    /**
     * 팝업 전체 링크 정제
     *
     * 상대 경로(/, ./, ../, #)와 http·https·mailto·tel 만 허용한다.
     * 스킴 없는 주소(example.com)는 브라우저가 상대 경로로 읽으므로 https:// 를 붙인다.
     *
     * @return string|null|false 빈 값이면 null, 허용하지 않는 스킴(javascript: 등)이면 false
     */
    public function normalizeLinkUrl(mixed $url): string|null|false
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }

        if (preg_match('#^[/\#]#', $url) || preg_match('#^\.{1,2}/#', $url)) {
            return $url;
        }

        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $matches)) {
            return in_array(strtolower($matches[1]), ['http', 'https', 'mailto', 'tel'], true) ? $url : false;
        }

        return 'https://' . $url;
    }
}
