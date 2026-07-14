<?php
namespace Mublo\Core\Extension;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Context\Context;

/**
 * ExtensionProviderInterface
 *
 * 플러그인/패키지가 구현해야 하는 Provider 인터페이스
 *
 * 책임:
 * - 서비스 등록
 * - 이벤트 구독자 등록
 * - 라우트 등록
 */
interface ExtensionProviderInterface
{
    /**
     * 서비스 등록
     *
     * 컨테이너에 서비스를 등록한다.
     * boot() 이전에 호출됨
     *
     * @param DependencyContainer $container
     */
    public function register(DependencyContainer $container): void;

    /**
     * 부팅 처리
     *
     * Context가 생성된 후 호출됨
     * 이벤트 구독자 등록 등
     *
     * @param DependencyContainer $container
     * @param Context $context
     */
    public function boot(DependencyContainer $container, Context $context): void;
}
