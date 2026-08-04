<?php
declare(strict_types=1);
namespace Mublo\Core\Extension;

/**
 * PluginHostInterface — 플러그인 수용 패키지 선언
 *
 * 패키지 Provider 가 이 인터페이스를 구현하면 그 패키지는 종속 플러그인을
 * 수용한다. **코어는 패키지 내부 디렉토리를 스스로 읽지 않는다** — 어떤
 * 플러그인이 있는지는 전적으로 패키지가 답한다. 구현하지 않은 패키지에는
 * 플러그인을 넣어도 코어가 무시한다.
 *
 * 표준 디렉토리 규약(packages/{Pkg}/Plugins/{Name})을 그대로 쓰려면
 * 코어가 제공하는 기본 구현 한 줄이면 된다:
 *
 * ```php
 * class BoardProvider implements ExtensionProviderInterface, PluginHostInterface
 * {
 *     use PluginHostTrait;   // Plugins/ 디렉토리 규약으로 발견
 *     ...
 * }
 * ```
 *
 * 다른 방식(다른 디렉토리, 자체 검증 등)으로 플러그인을 관리하고 싶은
 * 패키지는 discoverPlugins() 를 직접 구현한다.
 *
 * 주의: 이 메서드는 register()/boot() 전, 인자 없는 생성 직후에 호출될 수
 * 있다 — 컨테이너·DB 에 의존하지 말고 파일시스템/정적 정보만 사용할 것.
 */
interface PluginHostInterface
{
    /**
     * 이 패키지가 제공하는 종속 플러그인 목록
     *
     * @return array<string, array{dir: string, providerClass: string}>
     *   플러그인 이름(패키지 접두 없는 자체 이름) => [
     *     'dir'           => 플러그인 디렉토리 절대 경로 (manifest.json, routes.php 위치),
     *     'providerClass' => Provider FQCN,
     *   ]
     */
    public function discoverPlugins(): array;
}
