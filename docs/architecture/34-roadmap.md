# 34. Technical Roadmap

이 책의 본문은 현재 구현만 서술한다. 이 장은 현재 소스에 아직 구현되지 않은 기술 항목과 착수 조건을 구분해 기록한다. 아래 항목은 현재 기능이나 확정된 공개 API가 아니다.

## 현재 경계 요약

| 주제 | 현재 | 지향 |
|---|---|---|
| 확장 계층 | Core → Package → 종속 Plugin (1단계 중첩) | 다단계 Dependency Graph |
| Manifest | v1 (`parent` + `requires`) | v2 (`schema_version`, `provides`, `extension-api:*`) |
| 호환성 선언 | Package 버전 범위 | Package 버전과 Extension API 버전의 독립 운영 |
| lifecycle 훅 | install / uninstall(=disable 의미) | install / enable / disable / uninstall / purge / upgrade 분리 |
| capability | 선언·문서 용도 (v1 호환 확장 필드) | 정식 검증·관리자 표시·감사 |
| Plugin 신뢰 모델 | 서버에서 실행되는 신뢰 코드 | 보안 sandbox는 표방하지 않음 — 필요해지면 별도 설계 |

## 보류 중인 범용 Extension Runtime

다음 구성 요소는 북극성 구조로 설계가 확정돼 있으나, 실제 복잡성이 발생하기 전까지 구현을 보류한다.

- ExtensionDescriptor / ExtensionCatalog — 모든 확장 유형의 단일 기술 모델과 전역 인벤토리
- DomainExtensionState — 도메인별 explicit/effective 활성 상태의 구조화
- ExtensionDependencyResolver / ResolvedExtensionPlan — 의존성 그래프·위상 정렬·차단 목록
- ExtensionLifecycleCoordinator — 설치·활성·부팅·비활성의 단일 조정자
- Router / Asset / Reset의 Catalog 통합
- 의존성 그래프 관리자 UI

착수 조건(어느 하나가 실제로 발생할 때 재검토):

- Package 간 runtime 의존성이 실제로 등장
- 2단계 이상 종속(`Board → BoardReport → BoardReportAI`)이 필요
- 저장 목록 정렬만으로 lifecycle 순서를 표현할 수 없음
- 순환 의존 문제가 실제로 발생
- 세 번째 이상의 Package Platform에서 발견·정렬 코드가 반복
- Runtime·Router·Asset의 판정 불일치가 반복적 결함으로 나타남

두 번째 Package Platform의 등장은 재검토 시점이지만, 서로 독립적이라면 범용 그래프 도입의 충분조건은 아니다.

## 문서에 미치는 영향

로드맵 항목이 구현되면 이 책에서 갱신될 장:

| 구현 항목 | 갱신 대상 |
|---|---|
| Manifest v2 | [13. Manifest](13-manifest.md) — v2 규약 절 추가, 하위 호환 규칙 |
| lifecycle v2 | [11. Package](11-package.md), [14. Extension](14-extension.md) — 훅 의미 분리 |
| Dependency Resolver | [14. Extension](14-extension.md) — 로딩 순서 절 대체 |
| extension-api 버전 | [15. Public API](15-public-api.md) — 버전 선언·검증 절 |

## 개발자 도구 계획

기존 `tools/check-extension-api.php`는 단계적으로 강화한다 (선언하지 않은 capability 사용 경고, `@internal` 참조 검사 확대). 장기적으로 `extension:validate`, `extension:doctor`, `extension:graph`, `make:package-host`, `make:nested-plugin` 도구를 추가한다. 신규 규약 위반은 초기에는 경고, 안정화 후 오류로 전환한다.

## 기타 기술 보류 항목

- **Report 엔진 최적화** — `RowProviderInterface::isRewindable()`은 선언만 있고 엔진이 아직 활용하지 않는다. `ReportManager::mergeChunks()`의 정의 재실행 비용 문제와 묶어, rewindable Provider는 rewind로 병합하는 최적화가 자연스러운 활용 경로다. 무거운 리포트 정의가 등장하면 착수한다. (폐기하지 않기로 결정 — 활용 경로 보존)
- **폐기 예정 심볼** — `ConfigFormInterface`(블록 설정 폼, `registerContentType` 의 adminScript 옵션으로 대체됨)는 @deprecated 상태이며 다음 major에서 제거한다. 같은 목록에 있던 `SuperOnlyMiddleware` 는 이미 제거됐다 — `super_only` 는 라우트 등록 억제로 처리한다([20. 권한 모델](20-permission-model.md)).
- **방문 로그 IP 해시 전환** — 현재는 원본 저장 + **관리자 수동 퍼지**(`apiPurge`, 기본 30일·최소 7일)이며 자동 삭제 스케줄러는 없다([26. 통계·트래킹](26-tracking.md)). UV 판정 로직 개편 시 일별 솔트 해시로 전환해 원문 미보관을 검토한다.

## 관련 문서

- [13. Manifest](13-manifest.md) · [14. Extension Runtime](14-extension.md) · [15. Public API](15-public-api.md)
- 안정 API 정책: `docs/compatibility-policy.md`
