<?php
namespace Mublo\Core\Block;

/**
 * BlockItemsProviderInterface
 *
 * Plugin/Package가 블록 아이템 선택 목록을 제공하기 위한 인터페이스
 *
 * DualListbox 모드에서 사용:
 * - Plugin이 이 인터페이스를 구현한 클래스를 BlockRegistry에 등록
 * - BlockrowController가 콘텐츠 타입 변경 시 해당 Provider를 통해 아이템 목록 조회
 *
 * 사용 예:
 * ```php
 * // 1. Provider 구현
 * class BannerItemsProvider implements BlockItemsProviderInterface
 * {
 *     public function getItems(int $domainId): array
 *     {
 *         return [
 *             ['id' => '1', 'label' => '메인 배너'],
 *             ['id' => '2', 'label' => '서브 배너'],
 *         ];
 *     }
 * }
 *
 * // 2. BlockRegistry에 등록 (Provider::boot())
 * BlockRegistry::registerContentType(
 *     type: 'banner',
 *     kind: BlockContentKind::PLUGIN->value,
 *     title: '배너',
 *     rendererClass: BannerRenderer::class,
 *     options: [
 *         'hasItems' => true,
 *         'itemsProvider' => BannerItemsProvider::class,
 *     ]
 * );
 * ```
 */
interface BlockItemsProviderInterface
{
    /**
     * 아이템 목록 반환
     *
     * DualListbox에 표시할 아이템 목록을 반환합니다.
     *
     * @param int $domainId 현재 도메인 ID
     * @return array<int, array{id: string, label: string}>
     */
    public function getItems(int $domainId): array;
}
