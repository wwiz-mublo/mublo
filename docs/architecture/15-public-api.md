# 15. Public API

확장 생태계가 성립하려면 "무엇에 의존해도 되는가"가 명확해야 한다. Mublo는 안정 API의 경계를 문서와 도구 두 층으로 관리한다. 안정 API 목록의 진실은 `docs/compatibility-policy.md`이며, 이 장은 그 정책을 확장 개발 관점에서 요약한다.

## 두 개의 경계

### 1. Core 안정 API — 모든 확장에게

확장(Package·Plugin)이 의존할 수 있는 Core 표면. 전체 목록은 `docs/compatibility-policy.md`의 "안정 API" 표가 진실이다. 범주만 요약하면:

- Provider·확장 규약: `ExtensionProviderInterface`, `InstallableExtensionInterface`, `PluginHostInterface` 등
- 실행 환경: Container, Context, Request/Response, Event 시스템
- 주입해 쓰는 인프라: `Database`, `CacheInterface`, `Storage\*`, `Logger` 등
- 파일 규약: `manifest.json` 표준, `routes.php` 등록 규칙

### 2. Package 공개 표면 — 종속 Plugin에게

Package가 종속 Plugin에게 보장하는 표면은 세 가지뿐이다.

```text
Mublo\Packages\{Package}\
├── Contract\Extension\*      공개 Contract (안정)
├── Api\DTO\*                 readonly Snapshot DTO (안정)
├── Event\*                   공식 Event (안정)
│
├── Service\*                 내부 — 계약 아님
├── Repository\*              내부
├── Entity\*                  내부
├── Helper\*                  내부
└── Controller\*              내부
```

Package 내부 Service·Repository·Entity는 **공개 Contract 구현에 사용되더라도** 안정 API가 아니다. 예를 들어 `packages/Board/Api/BoardArticleReader.php`는 내부적으로 `BoardArticleService`를 사용하지만 `@internal`로 표시된 구현체이며, Plugin이 의존할 대상은 `BoardArticleReaderInterface`(Contract)다.

## 왜 작은 Contract인가

원칙 (`packages/Board`의 실제 설계 기준):

- 모든 내부 Service에 Interface를 만들지 않는다. **Plugin이 실제로 사용하는 작은 Contract만 공개한다.**
- 내부 Entity를 넘기지 않는다. Plugin에게는 readonly Snapshot DTO를 전달해 Package의 영속 구조를 독립적으로 변경할 수 있게 한다.
- Package 내부 구현은 자유롭게 리팩터링할 수 있어야 한다 — 공개 표면이 작을수록 그 자유가 크다.

```php
// 공개 Contract: Plugin이 필요한 것만
interface BoardArticleReaderInterface
{
    public function findAccessibleById(int $articleId, int $domainId): ?ArticleSnapshot;
}
```

## 버전과 호환성 관리

- 종속 Plugin의 호환성은 현재 `requires["package:{Package}"]`의 버전 범위로 관리한다 (Manifest v1).
- 별도의 Extension API 버전(`extension-api:*`)과 `provides` 해석은 Package 버전과 API 버전을 독립 운영할 필요가 생길 때 도입한다 ([34. Technical Roadmap](34-roadmap.md)).
- Event는 안정 계약이다: 발생 시점과 의미를 유지하고, 기존 getter는 최소 한 major version 동안 유지하며, payload 확장은 가능한 한 additive getter로 한다.
- 폐기 절차: `@deprecated` + 대체 API + 제거 예정 major를 기록한다. 상세는 `docs/compatibility-policy.md`.

## 도구 검사 — check-extension-api

`php tools/check-extension-api.php`가 경계를 기계적으로 검사한다.

검사 규칙:

- 확장(plugins/, packages/) 소스의 `use` 문을 수집해 안정 API 목록과 대조한다.
- 독립 Plugin 간 참조는 requires의 소관이므로 검사하지 않는다.
- **종속 Plugin은 부모 Package의 `Contract\Extension\*`, `Api\DTO\*`, `Event\*`만 허용된다.** 부모의 Service·Repository 등 그 외 심볼 import는 위반으로 보고된다.
- 자기 자신의 네임스페이스(`Mublo\Packages\{Pkg}\Plugins\{Name}\*`) 참조는 허용된다.

CI 또는 배포 전 점검에 포함할 것을 권장한다. 위반이 있으면 확장은 부모의 다음 리팩터링에서 깨질 수 있는 상태다.

## 확장 개발자 체크리스트

- [ ] 데이터 조회·기능 호출: 부모/코어의 Contract를 먼저 찾는다. 없으면 Package 개발자에게 공개 요청 — 내부 클래스를 몰래 쓰지 않는다.
- [ ] 페이지 출력 확장: Block 또는 렌더링 Event를 사용한다.
- [ ] `php tools/check-extension-api.php` 통과.
- [ ] 의존한 Package의 버전 범위를 manifest `requires`에 기록.

## 관련 문서

- 안정 API 목록(진실): `docs/compatibility-policy.md`
- [08. Event](08-event.md) · [12. Plugin](12-plugin.md) · [13. Manifest](13-manifest.md)
- 실물 사례: [33. Reference Packages](33-reference-packages.md)
