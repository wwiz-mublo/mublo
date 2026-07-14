<?php
namespace Mublo\Core\Container;

use Mublo\Core\Extension\ExtensionRegistrationScope;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Class DependencyContainer
 *
 * 경량 PSR-11 DI Container
 *
 * 원칙:
 * - 명시적 등록 우선 (set / factory)
 * - Factory 기반 생성
 * - Service만 auto-wiring
 * - Controller / Context / Response auto-wiring 금지
 * - 비즈니스 로직 금지
 */
class DependencyContainer implements ContainerInterface
{
    protected static ?self $instance = null;

    /**
     * 공유 인스턴스 (싱글톤 캐시)
     * [id => object]
     */
    protected array $instances = [];

    /**
     * 싱글톤 팩토리
     * [id => callable]
     * 최초 호출 시 인스턴스를 생성하고 캐시
     */
    protected array $singletonFactories = [];

    /**
     * 일반 팩토리
     * [id => callable]
     * 매 호출마다 새 인스턴스 생성
     */
    protected array $factories = [];

    /**
     * autoResolve 순환 참조 감지용 스택
     * [class => true]
     */
    protected array $buildStack = [];

    /** @var array<string, string> 명시적/auto-wired 정의를 등록한 확장 scope */
    private array $definitionOwners = [];

    private function __construct() {}

    /**
     * 싱글톤 인스턴스
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $container = new self();
            // 컨테이너 자기 자신을 등록 (DI에서 DependencyContainer를 주입받을 수 있도록)
            $container->instances[self::class] = $container;
            self::$instance = $container;
        }
        return self::$instance;
    }

    /**
     * 싱글톤 리셋 (테스트용)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * 인스턴스 직접 등록
     * (Context 등 런타임 객체)
     */
    public function set(string $id, $instance): void
    {
        $this->trackDefinitionChange($id);
        $this->instances[$id] = $instance;
    }

    /**
     * 팩토리 등록
     * 매 호출마다 새 인스턴스 생성 (Renderer, Router 등 상태가 있는 객체)
     */
    public function factory(string $id, callable $factory): void
    {
        $this->trackDefinitionChange($id);
        $this->factories[$id] = $factory;
        // factory로 재등록 시 기존 싱글톤 정의/캐시 제거
        unset($this->singletonFactories[$id], $this->instances[$id]);
    }

    /**
     * 싱글톤 등록
     * 최초 호출 시 인스턴스를 생성하고 이후 동일 인스턴스 반환
     */
    public function singleton(string $id, callable $factory): void
    {
        $this->trackDefinitionChange($id);
        $this->singletonFactories[$id] = $factory;
        // singleton으로 재등록 시 기존 factory 정의/캐시 제거
        unset($this->factories[$id], $this->instances[$id]);
    }

    /**
     * 서비스 조회
     *
     * 우선순위:
     * 1. 이미 생성된 인스턴스 (싱글톤 캐시 / set()으로 등록된 객체)
     * 2. 싱글톤 팩토리 → 생성 후 캐시
     * 3. 일반 팩토리 → 매번 새 인스턴스
     * 4. Service 네임스페이스 auto-wiring → 생성 후 캐시 (싱글톤)
     */
    public function get(string $id)
    {
        // 1. 이미 생성된 인스턴스 (싱글톤 캐시)
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // 2. 싱글톤 팩토리 → 생성 후 캐시
        if (isset($this->singletonFactories[$id])) {
            return $this->instances[$id] = ($this->singletonFactories[$id])($this);
        }

        // 3. 일반 팩토리 → 매번 새 인스턴스 (캐시하지 않음)
        if (isset($this->factories[$id])) {
            return ($this->factories[$id])($this);
        }

        // 4. Service만 auto-wiring (싱글톤으로 캐시)
        if ($this->isServiceClass($id)) {
            $this->trackDefinitionChange($id);
            return $this->instances[$id] = $this->autoResolve($id);
        }

        throw new class("Service not found: {$id}")
            extends \Exception
            implements NotFoundExceptionInterface {};
    }

    /**
     * 컨테이너가 명시적으로 관리하는 서비스인지 여부
     *
     * PSR-11 준수: 명시적 등록(set/singleton/factory)만 true 반환
     * auto-wiring 가능 여부는 canResolve() 사용
     */
    public function has(string $id): bool
    {
        return isset($this->instances[$id])
            || isset($this->singletonFactories[$id])
            || isset($this->factories[$id]);
    }

    /**
     * 컨테이너가 서비스를 제공할 수 있는지 여부
     *
     * 명시적 등록 + auto-wiring 가능 여부 모두 포함
     * get() 호출 전 안전하게 확인할 때 사용
     */
    public function canResolve(string $id): bool
    {
        return $this->has($id) || $this->isServiceClass($id);
    }

    /**
     * Service 클래스 판별
     *
     * 규칙:
     * - Mublo\Service 네임스페이스 허용
     * - Mublo\Infrastructure 네임스페이스 허용
     * - 필요 시 추가 가능
     */
    protected function isServiceClass(string $class): bool
    {
        if (!class_exists($class)) {
            return false;
        }

        $allowedNamespaces = [
            'Mublo\\Service\\',
            'Mublo\\Infrastructure\\',
            'Mublo\\Repository\\',
            'Mublo\\Model\\',
            'Mublo\\Core\\Middleware\\',
            'Mublo\\Core\\Block\\Renderer\\',
            'Mublo\\Core\\Crypto\\',
        ];

        foreach ($allowedNamespaces as $namespace) {
            if (str_starts_with($class, $namespace)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reflection 기반 Service 자동 생성
     *
     * 규칙:
     * - 생성자 인자는 모두 class type 이어야 함
     * - scalar / array / builtin 타입 ❌
     * - 의존성은 재귀적으로 get()
     * - optional 파라미터는 타입이 있으면 생성 시도, 없으면 기본값 사용
     */
    protected function autoResolve(string $class)
    {
        // 순환 참조 감지
        if (isset($this->buildStack[$class])) {
            $chain = implode(' → ', array_keys($this->buildStack)) . " → {$class}";
            throw new \RuntimeException("Circular dependency detected: {$chain}");
        }

        $this->buildStack[$class] = true;

        try {
            $ref = new ReflectionClass($class);

            if (!$ref->isInstantiable()) {
                throw new \RuntimeException("Cannot instantiate {$class}");
            }

            $constructor = $ref->getConstructor();

            // 생성자 없으면 바로 생성
            if ($constructor === null) {
                return new $class();
            }

            $args = [];

            foreach ($constructor->getParameters() as $param) {
                $type = $param->getType();

                // 타입 힌트가 없거나 builtin 타입인 경우
                if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                    // optional이면 기본값 사용
                    if ($param->isOptional()) {
                        $args[] = $param->getDefaultValue();
                        continue;
                    }
                    throw new \RuntimeException(
                        "Service autowiring failed: {$class}::\${$param->getName()}"
                    );
                }

                $typeName = $type->getName();

                // optional 파라미터 처리: 타입이 있으면 생성 시도
                if ($param->isOptional() || $type->allowsNull()) {
                    try {
                        $args[] = $this->get($typeName);
                    } catch (NotFoundExceptionInterface) {
                        // 미등록 서비스 → 기본값 사용 (정상적인 optional)
                        $args[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                    }
                    continue;
                }

                $args[] = $this->get($typeName);
            }

            return $ref->newInstanceArgs($args);
        } finally {
            unset($this->buildStack[$class]);
        }
    }

    /**
     * 현재 확장 scope가 변경하는 컨테이너 정의의 이전 상태를 기록한다.
     */
    private function trackDefinitionChange(string $id): void
    {
        $owner = ExtensionRegistrationScope::currentOwner();
        if ($owner === null) {
            return;
        }

        // 같은 scope(owner)가 같은 id를 이 요청에서 이미 변경했다면, 최초 previous(원래 상태)만
        // 보존하고 여기서 멈춘다. 두 번째 이후의 undo까지 기록하면, 롤백이 undoCallbacks[owner]를
        // 먼저 unset한 상태에서 undo가 역순 실행될 때 owner 가드(:309, :331)가 어긋나 원본 복원
        // 체인이 끊긴다 — 이중 등록(register+boot 동일 id) 시 절반만 롤백되어 코어 원본이 소실되는 버그.
        if (($this->definitionOwners[$id] ?? null) === $owner) {
            return;
        }

        $previous = [
            'instanceExists' => array_key_exists($id, $this->instances),
            'instance' => $this->instances[$id] ?? null,
            'singletonExists' => array_key_exists($id, $this->singletonFactories),
            'singleton' => $this->singletonFactories[$id] ?? null,
            'factoryExists' => array_key_exists($id, $this->factories),
            'factory' => $this->factories[$id] ?? null,
            'ownerExists' => array_key_exists($id, $this->definitionOwners),
            'owner' => $this->definitionOwners[$id] ?? null,
        ];

        $this->definitionOwners[$id] = $owner;

        ExtensionRegistrationScope::recordCommit(function () use ($id, $owner): void {
            if (($this->definitionOwners[$id] ?? null) === $owner) {
                unset($this->definitionOwners[$id]);
            }
        });

        ExtensionRegistrationScope::recordUndo(function () use ($id, $owner, $previous): void {
            if (($this->definitionOwners[$id] ?? null) !== $owner) {
                return;
            }

            unset(
                $this->instances[$id],
                $this->singletonFactories[$id],
                $this->factories[$id],
                $this->definitionOwners[$id]
            );

            if ($previous['instanceExists']) {
                $this->instances[$id] = $previous['instance'];
            }
            if ($previous['singletonExists']) {
                $this->singletonFactories[$id] = $previous['singleton'];
            }
            if ($previous['factoryExists']) {
                $this->factories[$id] = $previous['factory'];
            }
            if ($previous['ownerExists']
                && $previous['owner'] !== null
                && ExtensionRegistrationScope::has($previous['owner'])) {
                $this->definitionOwners[$id] = $previous['owner'];
            }
        });
    }
}
