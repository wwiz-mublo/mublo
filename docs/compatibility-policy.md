# 호환성 정책

Mublo는 Plugin과 Package가 Core 위에서 오래 동작할 수 있도록 확장 API의 안정성을 중요하게 봅니다.

이 문서는 공개 API와 내부 API를 구분하고, 변경 시 어떤 기준을 따르는지 설명합니다.

## 안정 API

다음은 확장 개발자가 사용해도 되는 안정 API입니다.

**이 목록은 `tools/check-extension-api.php` 가 CI 에서 강제합니다.** 문서와 도구가 어긋나면 도구가 진실입니다.

| 영역 | 심볼 |
|---|---|
| 확장 등록·부팅 | `Mublo\Core\Extension\*`, `Mublo\Core\Container\*`, `Mublo\Core\Context\*` |
| 이벤트 | 이름이 `Event` 로 끝나거나 `\Event\` 아래에 있는 모든 클래스 |
| Contract | `Mublo\Contract\*`, `Mublo\Core\Registry\*` |
| 요청·응답 | `Mublo\Core\Http\*`, `Mublo\Core\Response\*`, `Mublo\Core\Result\*`, `Mublo\Core\Middleware\*`, `Mublo\Core\Session\SessionInterface`, `Mublo\Core\App\PrefixedRouteCollector` |
| 블록 시스템 | `Mublo\Core\Block\*`, `Mublo\Enum\Block\*`, `Mublo\Entity\Block\BlockRow`, `Mublo\Entity\Block\BlockColumn` |
| 상속용 기반 클래스 | `Mublo\Entity\BaseEntity`, `Mublo\Repository\BaseRepository` |
| 관리자·렌더 확장 지점 | `Mublo\Core\Dashboard\*`, `Mublo\Core\Rendering\*`, `Mublo\Core\Theme\*`, `Mublo\Core\Report\*`, `Mublo\Core\Crypto\*`, `Mublo\Service\CustomField\CustomFieldRenderer` |
| 마이페이지 확장 지점 | `Mublo\Service\Mypage\MypageMenuBuilder` (Package/Plugin 컨트롤러의 마이페이지 레이아웃용), 섹션 등록은 `MypageSectionBuildingEvent` |
| 인프라 (주입해 쓰는 것) | `Infrastructure\Database\Database`, `DatabaseException`, `SqlStatementSplitter`, `Infrastructure\Crypto\CryptoManager`, `Cache\CacheInterface`, `Storage\*`, `Image\ImageProcessor`, `Mail\*`, `Log\Logger`, `Log\LogLevel`, `Security\RateLimiter`, `Code\CodeGenerator`, `Mublo\Core\Cookie\CookieInterface` |
| 예외·헬퍼 | `Mublo\Exception\*`, `Mublo\Helper\*` |
| 파일 규약 | Package/Plugin `manifest.json` 표준, `routes.php` 등록 규칙 |
| Package Extension API | 종속 Plugin 부모의 `Contract\Extension\*`, `Api\DTO\*`, 공식 `Event\*` |

블록 렌더러는 칸 엔티티가 아니라 `Mublo\Contract\Block\BlockColumnView` 를 받습니다. 렌더러가 읽어야 하는 것만 담긴 읽기 전용 뷰이며, 저장·정렬·스택 구성 같은 코어 내부 표면은 계약 밖입니다.

`BlockRow` 와 `BlockColumn` 두 엔티티가 안정 목록에 남은 이유는 미리보기 계약(`Contract\Block\BlockPreviewRendererInterface::renderRow`) 때문입니다. 저장되지 않은 구성을 렌더하는 것이 목적이라 확장이 엔티티를 직접 조립해 건네야 합니다. 그 밖의 블록 엔티티는 코어 내부입니다.

Front 스킨의 예약 `$mublo` 데이터는 안정 API입니다. 기존 키 제거·이름 변경·타입 변경은 major 변경이며, 선택 키 추가는 하위 호환 변경입니다. 전체 규격은 [Front 스킨 데이터 계약](reference/front-view-data-contract.md)을 따릅니다. DB 행/Entity 전체와 관리자 HTML은 안정 스킨 계약이 아닙니다.

`RateLimiter` 는 안정 API이지만 **인증 경계가 아닙니다** — 캐시 장애 시 fail-open(허용+로깅)으로 동작하는 남용 방어층이며, 이 특성 자체가 계약의 일부입니다. `CodeGenerator` 는 `unique_codes` 중앙 관리를 전제로 하므로 확장이 유니크 코드를 자체 구현하는 대신 이 서비스를 주입받는 것을 권장합니다.

## 내부 API

다음은 변경될 수 있는 내부 API입니다.

- Core 내부 private/protected 메서드
- Renderer 내부 구현 세부
- Repository의 내부 쿼리 구성 (`QueryBuilder` 포함)
- 코어 도메인 서비스·리포지토리 (`AuthService`, `MemberRepository`, `MenuService` 등)
- 정적 팩토리·싱글턴 (`DatabaseManager`, `SessionManager`)
- 관리자 화면의 HTML 구조
- 캐시 키 내부 형식
- 아직 문서화되지 않은 이벤트 payload 세부

내부 API는 사용할 수는 있지만, 버전 업그레이드 시 호환성을 보장하지 않습니다.

### 번들 확장 안정화 상태

번들 Package/Plugin의 배포 대상 운영 코드는 안정 API 밖의 코어 의존이 **0건**입니다.
과거 부채를 동결하던 `tools/extension-api-baseline/*.json`도 모두 제거했습니다. Git이 빈 디렉터리를
보존하지 않으므로 baseline 디렉터리 자체가 없을 수 있습니다.

`tools/check-extension-api.php`는 다음을 함께 검사합니다.

- `use` import와 코드 안의 완전한 클래스명(`\Mublo\...`) 참조
- 새 비안정 의존과, 다시 생긴 경우 baseline 무결성 오류
- 확장별 baseline의 소유권·중복·정렬·고아·빈 파일 규칙
- 중첩 Plugin을 포함한 동일한 경로 소유권과 테스트 제외 판정

확장 소유 테스트는 fixture와 내부 구현 통합 검증을 위해 검사 범위에서 제외합니다. 배포 운영 코드의
0건 판정과 테스트 import 0건 판정은 서로 다른 목표입니다. 새 확장은 처음부터 안정 API만 사용해야
하며 baseline을 다시 만들지 않습니다.

이번 안정화에서 추가·확장한 Contract의 책임, 실제 번들 소비자, DTO와 하위 호환 규칙은
[확장 API 안정화로 신설한 Contract](reference/extension-core-contracts.md)에 고정합니다.
전체 Contract 목록은 [16. Contract 카탈로그](architecture/16-contract-catalog.md)가 진실입니다.

정확한 클래스 하나만 안정화한 `CustomFieldRenderer`, `SqlStatementSplitter`, `CryptoManager`,
`LogLevel`, `MypageMenuBuilder`는 해당 클래스만 공개 표면입니다. 이들이 속한 `Service` 또는
`Infrastructure` namespace 전체가 안정 API라는 뜻은 아닙니다.

**코어 구현 계약의 선례**: 블록 킷 등록·적용(`Contract\Block\BlockKitGatewayInterface`), 블록 페이지 조회(`Contract\Block\BlockPageQueryInterface`), 회사 정보 조회(`Contract\Site\CompanyInfoInterface`)는 코어가 DI 단일 구현으로 제공하고 확장이 생성자 타입힌트로 소비합니다. 확장이 코어 Service/Repository가 필요해지면 직접 import 하지 말고 이 방식(좁은 계약 신설)을 따르십시오 — 자세한 관례는 [dev-guide/contract-system.md](dev-guide/contract-system.md) 참조.

## Event 안정성

안정 이벤트는 다음 조건을 만족합니다.

- 실제 Plugin/Package가 사용하고 있다.
- 발행 시점이 명확하다.
- payload 의미가 도메인 관점에서 안정적이다.
- Core 내부 구현 세부를 과도하게 노출하지 않는다.

대표 안정 이벤트:

- `AdminMenuBuildingEvent`
- `LoginFormRenderingEvent`
- `RegisterFormRenderingEvent`
- `MemberRegisteredByUserEvent`
- `MemberRegisteredByAdminEvent`
- `MemberUpdatedBySelfEvent`
- `MemberUpdatedByAdminEvent`
- `DomainCreatedEvent`
- `DomainUpdatedEvent`
- `DomainDeletedEvent`
- `SearchSourceCollectEvent`
- `SearchEvent`
- `MypageSectionBuildingEvent`
- `FrontFootRenderEvent`
- `SecureFileAccessEvent`
- `SecureFileDownloadedEvent`
- `BlockPageCreatedEvent`
- `BlockPageDeletedEvent`

전체 목록과 설명은 [이벤트 시스템](dev-guide/event-system.md)을 참고합니다.

## Contract 안정성

Contract는 확장 간 동기 호출 지점이므로, 이벤트보다 더 강한 호환성 기준을 적용합니다.

Contract 변경 원칙:

- 기존 메서드 시그니처를 함부로 변경하지 않습니다.
- 반환 구조가 바뀌면 문서와 마이그레이션 가이드를 함께 제공합니다.
- 새 기능은 가능하면 새 메서드 추가로 확장합니다.
- 큰 변경은 새 인터페이스 도입을 우선 검토합니다.
- Package 내부 Service/Repository/Entity는 공개 Contract 구현에 사용하더라도 안정 API가 아닙니다.
- 고정된 실행 결과는 DTO로 반환하고, View/설정용 배열은 PHPDoc array shape와 Presenter 허용 목록으로 필드를 고정합니다.
- Package 종속 Plugin의 초기 호환성은 `requires["package:{Package}"]` 범위로 관리합니다. 별도 Extension API version은 Package version과 독립 운영할 필요가 생길 때 도입합니다.

## 버전 변경 기준

| 변경 유형 | 예시 | 권장 버전 |
|-----------|------|-----------|
| Patch | 버그 수정, 문서 수정, 내부 성능 개선 | `x.y.z+1` |
| Minor | 하위 호환되는 새 API, 새 이벤트, 새 Contract 메서드 | `x.y+1.0` |
| Major | 안정 API 제거, Contract 시그니처 변경, Provider 규칙 변경 | `x+1.0.0` |

## 확장 개발자 권장사항

- Core 내부 클래스를 직접 new 하지 말고 DI 컨테이너를 우선 사용합니다.
- Core 파일을 수정하지 않고 Event, Contract, Block으로 연결합니다.
- 관리자 메뉴는 `AdminMenuBuildingEvent`를 사용합니다.
- 데이터 조회/기능 호출은 Contract를 우선 검토합니다.
- 페이지 출력 확장은 Block 또는 렌더링 이벤트를 사용합니다.
- 새 확장 포인트가 필요하면 기존 안정 이벤트로 해결 가능한지 먼저 확인합니다.
