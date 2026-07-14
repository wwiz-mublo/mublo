# 31. Best Practice

각 장에 흩어져 있는 권장 패턴을 한곳에 모은다. 근거 장을 함께 표기한다.

## Provider

- **register()는 등록만, boot()는 연결만.** register 단계에서는 다른 확장의 서비스가 아직 없을 수 있다. 서비스 인스턴스를 꺼내는 일은 boot 이후로 미룬다. ([11](11-package.md))
- **Provider는 composition root로 유지한다.** 비즈니스 로직을 Provider에 두지 않는다 — 등록·바인딩·구독 연결까지만.
- **install()과 Migration은 멱등으로.** reconcile, 재활성화, 관리자 재시도가 재호출을 전제한다. ([14](14-extension.md))

## 공개 표면 설계 (Package 개발자)

- **작은 Contract 원칙.** 모든 Service에 Interface를 만들지 않는다. Plugin이 실제로 쓰는 기능만 `Contract/Extension`으로 공개한다. ([15](15-public-api.md))
- **Entity 대신 Snapshot.** Plugin에게 내부 Entity를 넘기지 않는다. readonly DTO로 변환해 영속 구조 변경의 자유를 지킨다.
- **Event는 안정 계약으로 설계한다.** 발생 시점·의미·getter가 공개되는 순간 계약이다. 이름은 시제 관례(-ing: 저장 전 개입 가능, -ed: 완료 후 통지)를 따른다. ([08](08-event.md))
- **구현체는 `@internal`.** Contract 구현 클래스에는 `@internal`을 표기해 도구와 사람 모두에게 경계를 알린다.

## 확장 개발 (Plugin 개발자)

- **부모 접근은 공개 API 주입 하나로 수렴.** 생성자에서 `{Package}ExtensionApiInterface`를 받으면 부모 내부 리팩터링에서 자유롭다. ([12](12-plugin.md), [30](30-plugin-guide.md))
- **버전 범위는 명시.** 자동 주입되는 `package:{Pkg} = *`는 안전망이지 선언이 아니다. 검증한 범위를 적는다. ([13](13-manifest.md))
- **코어가 보장하는 것을 다시 방어하지 않는다.** 부모 우선 순서, 부모 실패 시 차단, 도메인별 판정은 코어 규약이다. Plugin 코드의 방어 분기는 노이즈다. ([30](30-plugin-guide.md) B.6)
- **경계 검사를 CI에 넣는다.** `php tools/check-extension-api.php`.

## Manifest·배포

- **name을 적지 않는다.** 이름은 디렉토리가 진실이다. 적으면 경고와 함께 무시된다. ([13](13-manifest.md))
- **vendor를 선언한다.** 서로 다른 제작자가 만든 같은 이름의 확장과 구분되는 전역 id(`vendor/Name`)를 갖는다.
- **critical은 신중히.** `critical: true`는 실패 격리를 포기하고 요청 전체를 죽인다. "이 확장 없이 뜨는 게 더 위험한" 확장에만. ([14](14-extension.md))

## 멀티 도메인

- **활성·installed 상태는 도메인별임을 전제한다.** "서버에 설치했다"와 "이 도메인에서 활성이다"는 다른 상태다. ([14](14-extension.md))
- **install 훅에서 도메인 데이터를 만들 때는 Context의 domainId를 쓴다.** 전역 상태를 가정하지 않는다.
- **Seeder는 `fn($pdo, $domainId)` 시그니처로 도메인 인자를 존중한다.** ([11](11-package.md))

## 테스트

- **경계 시나리오를 우선한다.** 정상 동작보다 부모 미설치·비활성·버전 불일치·Migration 실패가 생태계 품질을 결정한다. 실물 예: `tests/Unit/Core/Extension/ExtensionManagerLifecycleTest.php`, `tests/Unit/Service/Extension/ExtensionLifecycleMigrationTest.php`.
- **회귀를 테스트로 고정한다.** 순서 보장·격리 규칙 같은 "코어가 보장하는 것"이 바뀌면 생태계 전체가 깨진다 — 보장 자체를 테스트한다.

## 관련 문서

- 반대편 목록: [32. Anti Pattern](32-anti-pattern.md)
- 배포 규정(MUST/SHOULD 전체): `docs/dev-guide/extension-requirements.md`
