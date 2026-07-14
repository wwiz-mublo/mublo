<?php

namespace Mublo\Plugin\Widget\Service;

use Mublo\Core\Result\Result;
use Mublo\Plugin\Widget\Repository\WidgetItemRepository;
use Mublo\Plugin\Widget\Repository\WidgetConfigRepository;

class WidgetService
{
    public function __construct(
        private WidgetItemRepository $itemRepository,
        private WidgetConfigRepository $configRepository
    ) {
    }

    // === 설정 ===

    public function getConfig(int $domainId): array
    {
        $config = $this->configRepository->findByDomainId($domainId);

        return array_merge([
            'left_enabled' => 0,
            'right_enabled' => 0,
            'mobile_enabled' => 0,
            'left_skin' => 'basic',
            'right_skin' => 'basic',
            'mobile_skin' => 'basic',
            'left_width' => 50,
            'right_width' => 50,
            'mobile_width' => 40,
        ], $config ?? []);
    }

    public function saveConfig(int $domainId, array $data): Result
    {
        $this->configRepository->upsert($domainId, $data);
        return Result::success('설정이 저장되었습니다.');
    }

    /**
     * 포지션별 스킨 파일 경로 반환
     */
    public function getSkinPath(int $domainId, string $position): string
    {
        $config = $this->getConfig($domainId);
        $skin = $config[$position . '_skin'] ?? 'basic';

        $skinPath = MUBLO_PLUGIN_PATH . '/Widget/views/Front/skins/' . $position . '/' . $skin . '/widget.php';
        if (!file_exists($skinPath)) {
            $skinPath = MUBLO_PLUGIN_PATH . '/Widget/views/Front/skins/' . $position . '/basic/widget.php';
        }

        return $skinPath;
    }

    // === 아이템 CRUD ===

    public function getList(int $domainId, string $position = '', string $keyword = ''): array
    {
        $items = $this->itemRepository->getList($domainId, 100, 0, $position, $keyword);
        $totalItems = $this->itemRepository->countByDomain($domainId, $position, $keyword);

        return [
            'items' => $items,
            'totalItems' => $totalItems,
        ];
    }

    public function getItem(int $domainId, int $itemId): ?array
    {
        return $this->itemRepository->findById($domainId, $itemId);
    }

    public function create(int $domainId, array $data): Result
    {
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::failure('제목은 필수 항목입니다.');
        }

        $data['title'] = $title;
        $itemId = $this->itemRepository->create($domainId, $data);

        return $itemId > 0
            ? Result::success('위젯이 등록되었습니다.', ['item_id' => $itemId])
            : Result::failure('위젯 등록에 실패했습니다.');
    }

    public function update(int $domainId, int $itemId, array $data): Result
    {
        $existing = $this->itemRepository->findById($domainId, $itemId);
        if ($existing === null) {
            return Result::failure('위젯을 찾을 수 없습니다.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            return Result::failure('제목은 필수 항목입니다.');
        }

        $data['title'] = $title;
        $updated = $this->itemRepository->update($domainId, $itemId, $data);

        return $updated
            ? Result::success('위젯이 수정되었습니다.', ['item_id' => $itemId])
            : Result::failure('위젯 수정에 실패했습니다.');
    }

    public function delete(int $domainId, int $itemId): Result
    {
        $deleted = $this->itemRepository->delete($domainId, $itemId);

        return $deleted
            ? Result::success('위젯이 삭제되었습니다.')
            : Result::failure('위젯 삭제에 실패했습니다.');
    }

    public function updateOrder(int $domainId, array $orders): Result
    {
        foreach ($orders as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $order = (int) ($item['order'] ?? 0);
            if ($itemId > 0) {
                $this->itemRepository->updateSortOrder($domainId, $itemId, $order);
            }
        }

        return Result::success('순서가 변경되었습니다.');
    }

    // === 프론트용 ===

    /**
     * 프론트 렌더링용: 설정 + 활성 아이템
     */
    public function getActiveWidgets(int $domainId): array
    {
        $config = $this->getConfig($domainId);
        $grouped = $this->itemRepository->findActiveGrouped($domainId);

        return [
            'config' => $config,
            'left' => $config['left_enabled'] ? $grouped['left'] : [],
            'right' => $config['right_enabled'] ? $grouped['right'] : [],
            'mobile' => $config['mobile_enabled'] ? $grouped['mobile'] : [],
        ];
    }
}
