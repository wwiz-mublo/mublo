# 01. Philosophy

이 장은 Mublo의 설계 철학을 요약하고, 각 원칙이 실제 코드 어디에서 실현되는지 연결한다. 처음 접하는 독자를 위한 짧은 설명은 [Mublo 철학](../philosophy.md)을 함께 본다.

## 한 문장 정의

> Mublo는 Core가 실행 환경과 확장 규약을 제공하고, 애플리케이션의 도메인 기능을 Package로 분리하며, 각 Package가 코어의 보장 아래 또 다른 개발자를 위한 확장 플랫폼이 될 수 있게 하는 계층형 애플리케이션 플랫폼이다.

이 정의는 현재 상태의 기술이 아니라 아키텍처 원칙이다. 신규 기능과 장기 구조에 적용하며, 현재 Core에 남아 있는 기존 도메인 기능은 점진적으로 경계를 정리한다.

## 계층 구조

```text
Mublo Core          실행 환경 + 확장 규약 (발견·검증·순서·격리)
    ↓
Package             개발자 A의 플랫폼 (예: Board)
    ↓
종속 Plugin         개발자 B, C의 제품 (예: Board/BoardReport)
```

Mublo는 Framework → Application → Plugin 구조에, Package 자체가 종속 Plugin의 발견 규약과 공개 API를 선언하는 계층을 둔다. 이 부모·자식 규약을 각 Package 개발자의 관례가 아니라 **코어가 보장하는 1급 규약**으로 제공하는 것이 Mublo의 차별점이다.

## 여섯 가지 철학과 코드의 대응

### 1. Core는 최소한만 제공한다

Core는 특정 애플리케이션의 비즈니스를 만들지 않는다. Container, Router, Context, Event, Extension 관리와 생명주기까지만 책임진다.

- 코드: `src/Core/` 아래에 게시판·쇼핑몰 등 도메인 로직이 없다. 확장 런타임(`src/Core/Extension/`)은 Package 이름으로 Provider를 찾을 뿐 그 내용을 알지 못한다.

### 2. 애플리케이션 기능은 Package다

게시판도, 쇼핑몰도 Package로 구현한다. 이는 신규 기능과 장기 구조에 적용하는 원칙이다.

- 코드: `packages/Board/`가 대표 사례다. Core는 `manifest.json` 발견([13. Manifest](13-manifest.md)), 의존성 검증, 로딩 순서, 실패 격리라는 운영 규칙만 제공한다.

### 3. Package는 또 다른 플랫폼이 될 수 있다

Package는 종속 Plugin의 발견 규약을 선언하고, 공개 Contract와 Event를 제공하며, 제3자 개발자를 수용한다.

- 코드: `src/Core/Extension/PluginHostInterface.php` — Package Provider가 이 인터페이스를 구현하면 종속 Plugin을 수용한다. 코어는 Package 내부 디렉토리를 스스로 읽지 않는다. Board의 공개 표면은 `packages/Board/Contract/Extension/`과 `packages/Board/Api/DTO/`에 있다.

### 4. 확장은 Event와 Contract로 한다

Core를 수정하지 않는다. 부모 Package도 수정하지 않는다.

- 코드: BoardReport는 `BoardExtensionApiInterface`(Contract)와 Board 공식 Event만 사용한다. 이 경계는 `tools/check-extension-api.php`가 기계적으로 검사한다. [15. Public API](15-public-api.md) 참조.

### 5. 자동보다 명시를 우선한다

- 코드: Provider는 `register()`에서 명시 등록([03. Container](03-container.md)), Manifest는 명시 선언, 종속 Plugin의 부모는 `parent` 필드로 명시하고 실제 설치 위치와 일치해야 로드된다(`src/Service/Extension/ExtensionService.php`).

### 6. 생태계는 Framework가 아니라 개발자가 만든다

Mublo가 만드는 것은 생태계가 아니라 생태계를 만들 수 있는 조건이다. 각 Package 생태계의 소유자는 그 Package 개발자다.

- 코드: 종속 Plugin의 존재 여부는 전적으로 Package의 `discoverPlugins()` 응답이 결정한다(`src/Core/Extension/NestedPlugin.php`). 코어는 그 응답을 검증하고 운영할 뿐이다.

## 이 책을 읽는 순서

- Mublo 위에서 애플리케이션을 만드는 개발자: [02. Core](02-core.md) → [05. Router](05-router.md) → [08. Event](08-event.md) → [29. Package Guide](29-package-guide.md)
- Package를 플랫폼으로 키우려는 개발자: [11. Package](11-package.md) → [15. Public API](15-public-api.md) → [33. Reference Packages](33-reference-packages.md)
- 종속 Plugin을 만드는 개발자: [12. Plugin](12-plugin.md) → [13. Manifest](13-manifest.md) → [30. Plugin Guide](30-plugin-guide.md)

## 관련 문서

- [Mublo 철학](../philosophy.md)
- 안정 API 정책: `docs/compatibility-policy.md`
