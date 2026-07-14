<?php
namespace Mublo\Plugin\Banner;

use Mublo\Core\Event\EventSubscriberInterface;
use Mublo\Service\Admin\Event\AdminMenuBuildingEvent;

/**
 * Banner 플러그인 관리자 메뉴 Subscriber
 *
 * 플러그인 그룹에 자체 최상위 메뉴로 등록.
 * (원칙: 블록 컨텐츠 제공 여부와 무관하게 플러그인은 플러그인 그룹 소유.
 *  블록은 registerContentType 소켓만 제공하고 메뉴 계층엔 관여하지 않는다.)
 */
class AdminMenuSubscriber implements EventSubscriberInterface
{
    /**
     * 플러그인 정보 (code prefix용)
     */
    public const PLUGIN_NAME = 'Banner';

    /**
     * 구독할 이벤트 목록
     */
    public static function getSubscribedEvents(): array
    {
        return [
            AdminMenuBuildingEvent::class => 'onAdminMenuBuilding',
        ];
    }

    /**
     * 관리자 메뉴 빌드 시 호출
     *
     * 플러그인 그룹에 '배너 관리' 최상위 메뉴로 등록.
     * 코드에는 prefix 없이 순수 코드만 지정하면 자동으로
     * P_Banner_{code} 형식으로 변환됩니다.
     */
    public function onAdminMenuBuilding(AdminMenuBuildingEvent $event): void
    {
        // 소스 정보 설정 (code prefix 적용을 위해)
        $event->setSource('plugin', self::PLUGIN_NAME);

        // 플러그인 그룹에 자체 최상위 메뉴로 추가 (Widget/Popup/Survey 와 동일 패턴)
        // 아이콘은 null → manifest.json icon 단일 소스 사용
        $event->addPluginMenu('배너 관리', null, [
            [
                'label' => '배너 목록',
                'url' => '/admin/banner/list',
                'code' => '001',  // → P_Banner_001
            ],
        ]);
    }
}
