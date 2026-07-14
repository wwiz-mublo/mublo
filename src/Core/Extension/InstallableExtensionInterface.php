<?php
namespace Mublo\Core\Extension;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;

/**
 * InstallableExtensionInterface
 *
 * 설치/제거 라이프사이클이 필요한 플러그인/패키지가 선택적으로 구현
 *
 * - ExtensionProviderInterface와 별도 인터페이스 (BC break 없음)
 * - install(): 첫 활성화 시 1회 실행 (메뉴 등록, 초기 설정 등)
 * - uninstall(): 비활성화 시 실행 (메뉴 삭제 등, DB 테이블/데이터는 보존)
 */
interface InstallableExtensionInterface
{
    /**
     * 설치 처리
     *
     * 첫 활성화 시 1회 호출
     * 프론트 메뉴 등록, 초기 설정값 생성 등
     *
     * @param DependencyContainer $container
     * @param Context $context
     */
    public function install(DependencyContainer $container, Context $context): void;

    /**
     * 제거 처리
     *
     * 비활성화 시 호출
     * 프론트 메뉴 삭제 등 (DB 테이블/데이터는 보존)
     *
     * @param DependencyContainer $container
     * @param Context $context
     */
    public function uninstall(DependencyContainer $container, Context $context): void;
}
