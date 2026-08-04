<?php
declare(strict_types=1);
namespace Mublo\Plugin\Banner\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\EventInterface;
use Mublo\Core\Result\Result;
use Mublo\Plugin\Banner\Event\BannerSaveEvent;
use Mublo\Plugin\Banner\Event\BannerContentChangedEvent;
use Mublo\Plugin\Banner\Repository\BannerRepository;

/**
 * BannerService
 *
 * 배너 CRUD 비즈니스 로직
 */
class BannerService
{
    private BannerRepository $bannerRepository;
    private ?EventDispatcher $eventDispatcher;

    public function __construct(BannerRepository $bannerRepository, ?EventDispatcher $eventDispatcher = null)
    {
        $this->bannerRepository = $bannerRepository;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @template T of EventInterface
     * @param T $event
     * @return T
     */
    private function dispatch(EventInterface $event): EventInterface
    {
        return $this->eventDispatcher?->dispatch($event) ?? $event;
    }

    /**
     * 배너 목록 (관리자)
     */
    public function getList(int $domainId, int $page = 1, int $perPage = 20, array $search = []): Result
    {
        $result = $this->bannerRepository->findPaginated($domainId, $page, $perPage, $search);

        return Result::success('', [
            'items' => $result['items'],
            'totalItems' => $result['total'],
            'perPage' => $perPage,
            'currentPage' => $page,
            'totalPages' => (int) ceil($result['total'] / $perPage),
        ]);
    }

    /**
     * 배너 단건 조회
     */
    public function getBanner(int $domainId, int $bannerId): Result
    {
        $banner = $this->bannerRepository->findWithDomain($bannerId, $domainId);

        if (!$banner) {
            return Result::failure('배너를 찾을 수 없습니다.');
        }

        return Result::success('', ['banner' => $banner->toArray()]);
    }

    /**
     * 배너 생성
     */
    public function create(int $domainId, array $data): Result
    {
        if (empty($data['title'] ?? '')) {
            return Result::failure('배너 제목을 입력해 주세요.');
        }

        if (empty($data['pc_image_url'] ?? '')) {
            return Result::failure('PC 이미지를 등록해 주세요.');
        }

        $insertData = [
            'domain_id' => $domainId,
            'title' => $data['title'],
            'pc_image_url' => $data['pc_image_url'],
            'mo_image_url' => !empty($data['mo_image_url']) ? $data['mo_image_url'] : null,
            'link_url' => $data['link_url'] ?? null,
            'link_target' => $data['link_target'] ?? '_self',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : null,
            'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
        ];

        $bannerId = $this->bannerRepository->create($insertData);

        if (!$bannerId) {
            return Result::failure('배너 생성에 실패했습니다.');
        }

        // 패키지 확장: extras 수집
        $saveEvent = new BannerSaveEvent($domainId, $bannerId, $data, false);
        $this->dispatch($saveEvent);

        if ($saveEvent->hasExtras()) {
            $this->bannerRepository->updateWithDomain($bannerId, $domainId, [
                'extras' => json_encode($saveEvent->getExtras(), JSON_UNESCAPED_UNICODE),
            ]);
        }

        $this->dispatch(new BannerContentChangedEvent($domainId));

        return Result::success('배너가 등록되었습니다.', ['banner_id' => $bannerId]);
    }

    /**
     * 배너 수정
     */
    public function update(int $domainId, int $bannerId, array $data): Result
    {
        $banner = $this->bannerRepository->findWithDomain($bannerId, $domainId);

        if (!$banner) {
            return Result::failure('배너를 찾을 수 없습니다.');
        }

        if (empty($data['title'] ?? '')) {
            return Result::failure('배너 제목을 입력해 주세요.');
        }

        if (empty($data['pc_image_url'] ?? '')) {
            return Result::failure('PC 이미지를 등록해 주세요.');
        }

        $updateData = [
            'title' => $data['title'],
            'pc_image_url' => $data['pc_image_url'],
            'mo_image_url' => !empty($data['mo_image_url']) ? $data['mo_image_url'] : null,
            'link_url' => $data['link_url'] ?? null,
            'link_target' => $data['link_target'] ?? '_self',
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => (int) ($data['is_active'] ?? 1),
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : null,
            'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
        ];

        // 패키지 확장: extras 수집
        $saveEvent = new BannerSaveEvent($domainId, $bannerId, $data, true);
        $this->dispatch($saveEvent);

        $updateData['extras'] = $saveEvent->hasExtras()
            ? json_encode($saveEvent->getExtras(), JSON_UNESCAPED_UNICODE)
            : null;

        $this->bannerRepository->updateWithDomain($bannerId, $domainId, $updateData);

        $this->dispatch(new BannerContentChangedEvent($domainId));

        return Result::success('배너가 수정되었습니다.');
    }

    /**
     * 배너 삭제
     */
    public function delete(int $domainId, int $bannerId): Result
    {
        $banner = $this->bannerRepository->findWithDomain($bannerId, $domainId);

        if (!$banner) {
            return Result::failure('배너를 찾을 수 없습니다.');
        }

        $this->bannerRepository->deleteWithDomain($bannerId, $domainId);

        $this->dispatch(new BannerContentChangedEvent($domainId));

        return Result::success('배너가 삭제되었습니다.');
    }

    /**
     * 순서 일괄 변경
     *
     * @param array $items [['banner_id' => int, 'sort_order' => int], ...]
     */
    public function updateOrder(int $domainId, array $items): Result
    {
        foreach ($items as $item) {
            $bannerId = (int) ($item['banner_id'] ?? 0);
            $order = (int) ($item['sort_order'] ?? 0);

            if ($bannerId > 0) {
                $this->bannerRepository->updateSortOrder($bannerId, $domainId, $order);
            }
        }

        $this->dispatch(new BannerContentChangedEvent($domainId));

        return Result::success('정렬 순서가 변경되었습니다.');
    }

    /**
     * 도메인별 활성 배너 목록 (블록 에디터 아이템 선택용)
     *
     * 선택 UI 표시용 현재 배너 데이터를 반환합니다. 저장 시에는 JS provider가
     * ID 배열만 canonical form으로 반환합니다.
     *
     * @return array [['id' => string, 'label' => string, 'pc_image_url' => string, ...], ...]
     */
    public function getListForBlock(int $domainId): array
    {
        $banners = $this->bannerRepository->findByDomain($domainId, true);

        return array_map(fn($row) => [
            'id' => (string) $row['banner_id'],
            'label' => $row['title'],
            'pc_image_url' => $row['pc_image_url'] ?? '',
            'mo_image_url' => $row['mo_image_url'] ?? '',
            'link_url' => $row['link_url'] ?? '',
            'link_target' => $row['link_target'] ?? '_self',
            'extras' => isset($row['extras']) ? (is_string($row['extras']) ? json_decode($row['extras'], true) : $row['extras']) : null,
        ], $banners);
    }

    /**
     * ID 배열로 활성 배너 조회 (블록 렌더링용)
     */
    public function findByIds(int $domainId, array $bannerIds): array
    {
        $rows = $this->bannerRepository->findByIds($domainId, $bannerIds);

        return array_map(function ($row) {
            if (isset($row['extras']) && is_string($row['extras'])) {
                $row['extras'] = json_decode($row['extras'], true);
            }
            return $row;
        }, $rows);
    }

    /**
     * ID 배열로 extras만 조회 (banner_id → extras 매핑)
     *
     * 블록 렌더링 시 content_items 스냅샷에 의존하지 않고
     * 현재 DB의 extras를 가져오기 위해 사용합니다.
     *
     * @return array<int, array|null> [banner_id => extras, ...]
     */
    public function getExtrasMap(int $domainId, array $bannerIds): array
    {
        return $this->bannerRepository->findExtrasMap($domainId, $bannerIds);
    }
}
