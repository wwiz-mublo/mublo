# Manifest 기준

`manifest.json`은 Package/Plugin 메타데이터의 단일 기준이다.
관리 UI, 설치기, 확장 스캔은 이 파일을 기준으로 확장을 식별한다.

이 문서는 현재 코어 로더가 실제로 읽는 키를 기준으로 정리한다.

## 공통 원칙

- **`name`은 디렉토리명이 진실이다.** 로더가 디렉토리명으로 Provider 클래스를 조립하므로, `manifest.json`에 다른 값을 적어도 무시되고 경고만 남는다. PascalCase를 쓴다.
- **배포자는 `vendor`로 자신을 밝힌다.** 커뮤니티 배포에서 같은 이름의 확장을 구분하는 유일한 수단이다.
- 표시 이름은 `title`이 아니라 `label`을 사용한다.
- 버전은 SemVer 형식(`1.0.0`)을 사용한다.
- 의존성은 `requires` 객체에 명시한다.
- 로더가 읽지 않는 임의 키는 넣지 않는다.

## 표준 키

| 키 | 타입 | 대상 | 필수 | 설명 |
|----|------|------|------|------|
| `name` | string | 공통 | 예 | 확장 식별자. 디렉토리명과 같아야 한다 |
| `vendor` | string | 공통 | **권장** | 배포자 식별자. 소문자 영숫자·하이픈(최대 39자). `id` 구성에 쓰인다 |
| `label` | string | 공통 | 예 | 관리자/설치 화면에 보이는 이름 |
| `description` | string | 공통 | 예 | 한 줄 설명 |
| `version` | string | 공통 | 예 | SemVer 버전 |
| `author` | string | 공통 | 예 | 제작자/조직명(사람이 읽는 표기) |
| `author_url` | string | 공통 | 권장 | 제작자 사이트 URL |
| `icon` | string | 공통 | 권장 | Bootstrap Icons 클래스명 |
| `type` | string | Plugin | 예 | 항상 `plugin` |
| `category` | string | Plugin | 권장 | 플러그인 분류 |
| `hidden` | bool | Plugin | 선택 | 확장 관리 화면에서 숨김 |
| `requires` | object | 공통 | 권장 | 의존성 버전 범위 |

### 운영자 플래그 (개발 단계에서 설정하지 않는다)

아래 플래그들은 확장의 정체성이 아니라 **배포(사이트)의 운영 정책**이다.
SaaS 실운영에서 필요해 추가된 기능으로, 확장 개발자는 자신의 확장이 남의
배포에서 필수인지·기본인지 알 수 없다. 따라서 **개발·배포 단계의 manifest
에는 넣지 않는다** — 코어는 플래그가 없으면 전부 `false` 로 정규화하며,
운영자가 자기 배포의 manifest 에 직접 추가해 소비한다.

| 키 | 기본값 | 코어 동작 (플래그가 있을 때) |
|----|------|------|
| `default` | `false` | 신규 도메인 설치 시 자동 활성화 |
| `mandatory` | `false` | "끌 수 없음" 잠금 — 활성 상태를 끄는 저장을 차단(되살림). 설치·활성을 강제하지는 않는다 |
| `super_only` | `false` | 루트 도메인만 직접 제어, 활성이면 하위 도메인에 강제 활성 (Plugin) |

## `vendor` 와 `id`

확장은 composer 가 아니라 **커뮤니티의 zip/git 으로 배포**된다. 의존성 해결자가 없으므로 이름 충돌을 막을 장치가 코어 안에 있어야 한다.

`vendor` 를 적으면 로더가 스캔 시 `id` 를 만들어 준다.

```json
{ "name": "Banner", "vendor": "mublo" }   →  id = "mublo/Banner"
{ "name": "Banner" }                      →  id = "Banner"   (익명 배포)
```

- `vendor` 는 소문자 영숫자와 하이픈만 허용한다(선두는 영숫자, 최대 39자). 규칙에 맞지 않으면 익명(`""`)으로 떨어진다 — `id` 가 `vendor/name` 형태라 슬래시가 섞이면 식별자가 깨지기 때문이다.
- `id` 는 **manifest 에 직접 적지 않는다.** 로더가 `vendor` 와 디렉토리명으로 계산한다.

### 알려진 한계

디렉토리명이 곧 PHP 네임스페이스(`Mublo\Plugin\{Name}`)이므로, **서로 다른 배포자의 동명 확장을 동시에 설치할 수는 없다.** `vendor` 는 그 충돌을 *감지하고 사용자에게 알리기 위한* 것이지 공존시키기 위한 것이 아니다. 확장 이름은 되도록 고유하게 짓는다.

## `requires` 형식

최소한 `core` 요구 버전은 명시한다.

```json
{
    "requires": {
        "core": ">=1.0.0"
    }
}
```

Package 의존성이 있으면 함께 적는다.

```json
{
    "requires": {
        "core": ">=1.0.0",
        "package:Shop": ">=1.0.0"
    }
}
```

키는 `core`, `package:{Name}`, `plugin:{Name}` 세 가지다. 알 수 없는 키는 무시한다.

## `parent` (패키지 종속 플러그인 전용)

패키지 종속 플러그인(`packages/{Pkg}/Plugins/{Name}`)은 부모 패키지를 명시한다.

```json
{ "name": "BoardReport", "parent": "Board" }
```

- 배포물이 스스로 "어느 패키지용"인지 기술하는 키다 — 배포 사이트 분류와
  설치 안내가 이 값을 쓴다.
- 실제 설치 위치와 선언이 다르면 코어가 로드를 거부한다.
- 부모 패키지 의존(`requires."package:{Pkg}"`)은 적지 않아도 스캔 시
  자동 주입된다.
- 독립 플러그인·패키지에는 쓰지 않는다.

### 코어가 실제로 검사한다

`requires` 는 장식이 아니다. **확장을 활성화하는 순간 코어가 검사하고, 만족하지 못하면 켜지지 않는다.**
같은 저장 요청에서 함께 켜지는 확장은 "설치된 것"으로 본다(예: Shop 과 ShopAddon 을 한 번에 활성화).

확장은 composer 가 아니라 zip/git 으로 배포되므로 의존성 해결자가 없다. 이 검사가 그 자리를 대신한다.

### 지원하는 제약 문법

composer 문법의 부분집합이다.

| 표기 | 의미 |
|---|---|
| `*` 또는 생략 | 아무 버전 |
| `1.2.3` | 정확히 일치 |
| `>=1.2` `>1.2` `<=1.2` `<1.2` `=1.2` `!=1.2` | 비교 |
| `^1.2.3` | `>=1.2.3 <2.0.0` (0.x 는 `>=0.2.3 <0.3.0`) |
| `~1.2.3` | `>=1.2.3 <1.3.0` |
| `~1.2` | `>=1.2 <2.0.0` |
| `A B` 또는 `A,B` | 둘 다 만족 (AND) |
| `A \|\| B` | 하나만 만족해도 됨 (OR) |

**해석할 수 없는 제약(`dev-main`, `@stable` 등)은 만족으로 처리한다.** 파서의 한계 때문에 멀쩡한 확장을 막는 쪽이, 이상한 제약을 통과시키는 쪽보다 나쁘기 때문이다.

### 알려진 한계

검사 지점은 **활성화 시점** 하나다. 이미 켜 둔 확장이 코어 업그레이드로 비호환이 되어도 부팅 시 자동으로 꺼지지는 않는다.

## Package manifest 예시

```json
{
    "name": "MyPackage",
    "label": "내 패키지",
    "description": "패키지 설명",
    "version": "1.0.0",
    "author": "Mublo",
    "author_url": "https://github.com/wwiz-mublo/mublo",
    "icon": "bi-box",
    "requires": {
        "core": ">=1.0.0"
    }
}
```

## Plugin manifest 예시

```json
{
    "name": "MyPlugin",
    "label": "내 플러그인",
    "description": "플러그인 설명",
    "version": "1.0.0",
    "author": "Mublo",
    "author_url": "https://github.com/wwiz-mublo/mublo",
    "type": "plugin",
    "category": "content",
    "icon": "bi-puzzle",
    "requires": {
        "core": ">=1.0.0"
    }
}
```

운영자 플래그(`default`/`mandatory`/`super_only`)는 예시에 없다 — 개발 단계에서
설정하지 않으며, 필요한 배포의 운영자가 직접 추가한다.

## 권장 category 값

`category`는 강제값은 아니지만, 아래 정도로 맞추는 편이 좋다.

| 값 | 용도 예시 |
|----|-----------|
| `content` | Banner, Faq, Popup, Widget |
| `member` | MemberPoint, SnsLogin |
| `marketing` | Survey, VisitorStats |
| `infrastructure` | 운영 연동, 배포 보조, 외부 시스템 연결 플러그인 |
| `payment` | 결제 게이트웨이 플러그인 |
| `messaging` | 알림톡, 문자, 메일 연동 플러그인 |

## 레거시 키

아래 키는 현재 코어 manifest 표준으로 보지 않는다.

| 키 | 상태 | 비고 |
|----|------|------|
| `title` | 사용 중지 | `label`로 대체 |
| `provider` | 불필요 | Provider는 디렉토리/클래스 규칙으로 찾는다 |
| `hidden` | 운영 키로 유지 | 인프라성 플러그인을 확장 관리 화면에서 숨길 때 사용 |

기존 확장에 위 키가 남아 있어도 동작할 수는 있지만, 새 확장과 정리 대상 확장에는 사용하지 않는다.

## 실무 기준

- Package는 `type`을 넣지 않는다.
- Plugin은 `type: "plugin"`을 명시한다.
- `author_url`과 `requires.core`는 빠뜨리지 않는다.
- 운영자 플래그(`default`/`mandatory`/`super_only`)는 개발 단계에서 넣지 않는다.
  운영자가 자기 배포에서 인프라성 플러그인에 `hidden: true` + `super_only: true`
  조합을 검토하는 식으로 소비한다.
- manifest는 설명용 파일이 아니라 설치/관리 UI의 입력값이므로, 임시 키를 넣지 않는다.

## 관련 문서

- [패키지 만들기](package-development.md)
- [플러그인 만들기](plugin-development.md)
