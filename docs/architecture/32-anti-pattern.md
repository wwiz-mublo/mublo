# 32. Anti Pattern

하지 말아야 할 것들이다. 각 항목은 "왜 깨지는가"를 함께 적는다. 상당수는 도구(`tools/check-extension-api.php`)나 코어 런타임이 기계적으로 거부한다.

## 경계 위반

### 부모 Package의 내부 클래스 참조

```php
// ✗ 종속 Plugin에서
use Mublo\Packages\Board\Service\BoardArticleService;
use Mublo\Packages\Board\Repository\BoardArticleRepository;
use Mublo\Packages\Board\Entity\BoardArticle;
```

부모의 다음 리팩터링에서 깨진다. 부모 개발자는 내부를 자유롭게 바꿀 권리가 있고, 그 권리가 생태계 유지비를 낮춘다. `check-extension-api`가 위반으로 보고한다. → 공개 Contract를 쓰거나, 없으면 공개를 요청하라. ([15](15-public-api.md))

### 부모 DB 테이블 직접 접근

Plugin이 부모 테이블을 직접 SELECT/UPDATE하면 부모의 스키마 변경·권한 정책·도메인 격리를 모두 우회한다. 조회는 Contract로, 변경은 Command Contract로 — 부모의 권한 검증을 다시 통과하는 것이 규약이다.

### 부모 테이블을 ALTER하는 Plugin Migration

Plugin 제거 후에도 부모 스키마에 흔적이 남고, 부모의 Migration 이력과 충돌한다. Plugin 데이터는 자기 테이블에 — 부모 레코드 참조는 ID 컬럼으로.

### Core 수정으로 기능 구현

코어를 패치한 확장은 코어 업데이트마다 깨지고, 다른 확장과 조합할 수 없다. Mublo가 해결하려는 문제 그 자체다. Event·Contract·Block으로 해결이 안 되면 확장 포인트 추가를 제안하라.

## 생명주기 오용

### register()에서 서비스 사용

```php
// ✗ register 단계에는 다른 확장의 정의가 아직 없을 수 있다
public function register(DependencyContainer $container): void
{
    $container->get(SomeOtherService::class)->doSomething();
}
```

로딩 순서에 따라 되기도 하고 안 되기도 하는 하이젠버그가 된다. 실행은 boot() 이후로.

### 멱등하지 않은 install()

install()은 reconcile·재활성화 경로에서 재호출된다. INSERT를 무조건 실행하면 데이터가 중복된다. 존재 확인 후 삽입 또는 UNIQUE 제약으로 방어하라.

### uninstall()에서 데이터 삭제

현재 `uninstall()`은 비활성화 시 호출된다 — 운영자가 잠깐 껐다 켜는 경우에도 호출된다는 뜻이다. 데이터 삭제는 별도의 명시적 purge/reset 절차로만 한다. ([14](14-extension.md))

## Manifest 오용

### manifest name과 디렉토리명 불일치

이름은 디렉토리가 진실이다. 다른 name을 적어도 무시되지만, 배포 문서와 실제 동작이 어긋나는 혼란을 만든다.

### parent와 설치 위치 불일치

`parent: "Board"`인 Plugin을 다른 Package에 풀어넣으면 로드가 거부된다. 오동작보다 거부가 낫다는 정책이므로, 배포 zip의 디렉토리 구조를 정확히 안내하라.

### 모든 확장에 critical: true

critical은 실패 격리를 끈다. 배너 하나가 죽었다고 사이트 전체가 500이 되는 것을 운영자는 원하지 않는다.

### super_only·mandatory의 의미 혼동

- mandatory는 "설치 강제"가 아니다 — 활성인 것을 끄지 못하게 하는 잠금이다.
- super_only는 운영 정책 필드다 — 하위 도메인 강제 활성이 필요한 Plugin에만.

## 설계 냄새

### 모든 Service에 Interface 만들기

"확장 가능하게"라는 이유로 내부 Service 전부에 Interface를 만들면, 전부가 사실상 공개 API가 되어 아무것도 리팩터링할 수 없게 된다. 공개는 Plugin이 실제로 쓰는 것만. ([15](15-public-api.md))

### Event payload로 내부 Entity 노출

Event에 Entity를 실으면 Entity의 모든 public 메서드가 계약이 된다. 신규 Event는 Snapshot DTO를 싣는 것이 원칙이다 (기존 Event의 Entity payload는 하위 호환으로 유지하며 점진 전환 — `packages/Board/README.md`).

### Subscriber에 Container 전체 주입

구독자가 Container를 들고 있으면 의존이 숨고 테스트가 어려워진다. 필요한 서비스를 명시 주입하라.

### 다른 도메인의 활성 상태에 기대기

"루트 도메인에서 켰으니 어디서든 되겠지"는 성립하지 않는다. 의존성 판정은 도메인별이다. 하위 도메인 강제 활성이 필요하면 super_only가 공식 경로다.

## 관련 문서

- 권장 패턴: [31. Best Practice](31-best-practice.md)
- 기계 검사: `tools/check-extension-api.php` ([15](15-public-api.md))
- 배포 금지 규정: `docs/dev-guide/extension-requirements.md`
