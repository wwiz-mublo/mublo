# 03. Container

## 개요

Mublo의 의존성 주입 컨테이너는 `src/Core/Container/DependencyContainer.php` 하나다. PSR-11 `ContainerInterface`를 구현한 경량 컨테이너로, 클래스 주석에 원칙이 명시되어 있다: 명시적 등록 우선, Factory 기반 생성, Service만 Auto Wiring, Controller·Context·Response의 Auto Wiring 금지, 비즈니스 로직 금지.

컨테이너는 `DependencyContainer::getInstance()`로 얻는 전역 싱글톤이며, 생성 시 자기 자신을 등록해 두므로 다른 서비스가 `DependencyContainer` 타입을 주입받을 수 있다. 테스트에서는 `resetInstance()`로 초기화한다.

## 책임과 비책임

컨테이너의 책임은 정의 보관과 인스턴스 생성뿐이다. 판단·조건 분기·비즈니스 로직은 넣지 않는다. 무엇을 등록할지는 각 Provider의 몫이다 — Core는 `src/Core/Provider/ServiceProvider.php`의 `register()`에서, 확장은 각 Provider(`ExtensionProviderInterface::register()`)에서 등록한다 ([11. Package](11-package.md)).

## 등록 방식

실존하는 등록 메서드는 세 가지다 (`src/Core/Container/DependencyContainer.php`). `bind()` 같은 메서드는 존재하지 않는다.

| 메서드 | 동작 | 용도 |
|---|---|---|
| `set(string $id, $instance)` | 이미 만들어진 인스턴스를 직접 등록 | Context 등 런타임 객체 |
| `singleton(string $id, callable $factory)` | 최초 `get()` 시 생성 후 캐시, 이후 동일 인스턴스 | 대부분의 Service·Repository |
| `factory(string $id, callable $factory)` | 매 `get()`마다 새 인스턴스 | Renderer, Router 등 상태가 있는 객체 |

`singleton()`과 `factory()`는 서로 재등록 시 기존 정의와 캐시를 제거한다. 즉 같은 id를 `factory()`로 다시 등록하면 이전 싱글톤 정의·캐시가 사라지고, 반대도 마찬가지다. 팩토리 콜러블은 컨테이너 자신을 인자로 받는다: `fn(DependencyContainer $c) => ...`.

## get()과 has()

`get()`의 해석 우선순위는 코드 주석 그대로 4단계다.

1. 이미 생성된 인스턴스 (`set()`으로 등록됐거나 싱글톤 캐시에 있는 것)
2. 싱글톤 팩토리 — 생성 후 캐시
3. 일반 팩토리 — 매번 새 인스턴스, 캐시하지 않음
4. Auto Wiring 허용 클래스 — `autoResolve()`로 생성 후 **싱글톤으로 캐시**

네 단계 모두 실패하면 PSR-11 `NotFoundExceptionInterface`를 구현한 예외를 던진다.

`has()`는 PSR-11 준수를 위해 **명시적 등록(set/singleton/factory)만** true를 반환한다. Auto Wiring으로 해석 가능한 클래스라도 등록된 적이 없으면 `has()`는 false다. Auto Wiring 가능성까지 포함해 확인하려면 `canResolve()`를 쓴다.

```php
// src/Core/Container/DependencyContainer.php
public function has(string $id): bool
{
    return isset($this->instances[$id])
        || isset($this->singletonFactories[$id])
        || isset($this->factories[$id]);
}

public function canResolve(string $id): bool
{
    return $this->has($id) || $this->isServiceClass($id);
}
```

## Auto Wiring의 범위와 조건

"Service만 Auto Wiring"이라는 철학은 네임스페이스 허용 목록으로 구현된다. `isServiceClass()`가 다음 네임스페이스에 속한 실존 클래스만 통과시킨다 (`src/Core/Container/DependencyContainer.php`).

- `Mublo\Service\`
- `Mublo\Infrastructure\`
- `Mublo\Repository\`
- `Mublo\Model\`
- `Mublo\Core\Middleware\`
- `Mublo\Core\Block\Renderer\`
- `Mublo\Core\Crypto\`

이 목록에 없는 것 — Controller, Context, Response, 그리고 **확장의 모든 클래스**(`Mublo\Packages\`, `Mublo\Plugin\`) — 는 Auto Wiring되지 않는다. 따라서 Package·Plugin의 Service는 반드시 Provider의 `register()`에서 명시적으로 등록해야 한다. 이것이 확장 개발에서 register()가 생략될 수 없는 이유다.

`autoResolve()`의 생성 규칙은 다음과 같다.

- 생성자 인자는 모두 클래스 타입이어야 한다. scalar·array·builtin 타입 힌트는 optional이면 기본값을 쓰고, 아니면 `RuntimeException`으로 실패한다.
- 의존성은 재귀적으로 `get()`한다. optional 또는 nullable 파라미터는 해석을 시도하되, 미등록이면 기본값(없으면 null)으로 대체한다.
- 이렇게 생성된 인스턴스는 싱글톤으로 캐시된다.

## 순환 의존 처리

`autoResolve()`는 `buildStack` 배열로 생성 중인 클래스를 추적한다. 재귀 해석 중 같은 클래스를 다시 만나면 의존 사슬을 담은 `RuntimeException`을 던진다.

```php
// src/Core/Container/DependencyContainer.php — autoResolve()
if (isset($this->buildStack[$class])) {
    $chain = implode(' → ', array_keys($this->buildStack)) . " → {$class}";
    throw new \RuntimeException("Circular dependency detected: {$chain}");
}
```

이 감지는 Auto Wiring 경로에만 적용된다. 팩토리 클로저 안에서 서로를 `get()`하는 순환은 별도로 감지하지 않으므로, 정의 시점에 피해야 한다.

## Provider register()의 표준 패턴

확장 Provider의 `register()`는 컨테이너에 정의만 등록하고 인스턴스를 만들지 않는다. 실전 예는 Board Package의 `packages/Board/BoardProvider.php`다.

```php
// packages/Board/BoardProvider.php — register() 발췌
public function register(DependencyContainer $container): void
{
    // Repository: Database만 주입
    $container->singleton(BoardArticleRepository::class, fn(DependencyContainer $c) =>
        new BoardArticleRepository($c->get(Database::class))
    );

    // Service: Repository·Core 서비스를 조립
    $container->singleton(BoardArticleService::class, fn(DependencyContainer $c) =>
        new BoardArticleService(
            $c->get(BoardArticleRepository::class),
            $c->get(BoardConfigRepository::class),
            $c->get(MemberRepository::class),
            $c->get(BoardPermissionService::class),
            $c->get(EventDispatcher::class),
            $c->get(AuthService::class)
        )
    );

    // 공개 Contract: 인터페이스를 id로 등록 — 내부 Service를 종속 Plugin에 직접 노출하지 않는다
    $container->singleton(BoardArticleReaderInterface::class, fn(DependencyContainer $c) =>
        new BoardArticleReader(
            $c->get(BoardArticleService::class),
            $c->get(BoardConfigService::class)
        )
    );
}
```

패턴의 요점은 세 가지다.

1. 모두 `singleton()` + 지연 팩토리다. register() 시점에는 아무것도 생성되지 않고, 최초 `get()` 때 의존 사슬이 조립된다.
2. 의존성은 팩토리 안에서 `$c->get()`으로 명시한다. 확장 클래스는 Auto Wiring 대상이 아니므로 생성자 조립을 직접 쓴다.
3. 종속 Plugin에 노출할 것은 공개 Contract 인터페이스(`BoardArticleReaderInterface` 등)를 id로 등록한다. 구현 클래스가 아니라 인터페이스로 `get()`하게 하는 것이 Package 공개 표면 규약이다 ([15. Public API](15-public-api.md)).

## 경계

컨테이너에 등록되어 있다는 사실이 곧 공개 API라는 뜻은 아니다. Package의 공개 표면은 `Contract/Extension`, `Api/DTO`, 공식 Event뿐이며, 내부 Service·Repository를 다른 확장이 `get()`으로 꺼내 쓰는 것은 금지 패턴이다 ([32. Anti Pattern](32-anti-pattern.md)). Core 안정 API 목록은 `docs/compatibility-policy.md`를 따른다.

## 관련 문서

- [02. Core](02-core.md) — 부트 단계에서 컨테이너가 채워지는 순서
- [11. Package](11-package.md) — Provider 생명주기와 register/boot
- [15. Public API](15-public-api.md) — 컨테이너 등록과 공개 표면의 구분
- 관련 가이드: `docs/dev-guide/core-concepts.md`(핵심 개념), `docs/dev-guide/package-development.md`(Package 개발), `docs/compatibility-policy.md`(안정 API 목록)
