# 13. Manifest

모든 확장(Package, 독립 Plugin, 종속 Plugin)은 루트에 `manifest.json`을 둔다. Manifest가 없으면 확장은 발견되지 않는다. 파싱이 깨진 Manifest는 그 확장 하나만 제외되고 나머지 시스템은 정상 동작한다 (`src/Service/Extension/ExtensionService.php`의 `readManifest()`).

현재 규약은 **Manifest v1**이다. `schema_version`, `provides`, `extension-api:*` 버전 해석은 범용 Extension Runtime과 함께 도입할 로드맵 항목이다 ([34. Technical Roadmap](34-roadmap.md)).

## 필드 규약

`readManifest()`가 정규화하는 기본값 기준이다.

| 필드 | 기본값 | 의미 |
|---|---|---|
| `label` | 디렉토리명 | 관리자 화면 표시명 |
| `description` | `""` | 한 줄 설명 |
| `version` | `"1.0.0"` | 확장 버전. requires 검증의 기준 |
| `author`, `author_url` | `""` | 제작자 정보 |
| `icon` | `"bi-grid"` | 관리자 화면 아이콘 (Bootstrap Icons) |
| `type` | 위치에 따라 | `"package"` 또는 `"plugin"` |
| `parent` | 없음 | 종속 Plugin의 부모 Package명 |
| `requires` | `{}` | 의존성 선언 (아래 참조) |
| `default` | `false` | 설치기가 활성 목록에 자동 포함 — **운영자 플래그**, 개발 단계에서 설정하지 않음 |
| `mandatory` | `false` | 활성 상태에서 끌 수 없음(잠금). 설치·활성을 강제하지 않는다 — **운영자 플래그** |
| `super_only` | `false` | 루트 도메인이 켜면 하위 도메인에 강제 활성 (Plugin) — **운영자 플래그** |
| `hidden` | `false` | 관리자 확장 목록에서 숨김 (`views/Admin/Extensions/Index.php`) |
| `critical` | `false` | 로딩 실패 시 격리하지 않고 요청 전체에 전파 |
| `vendor` | `""` | 배포자 식별자. 소문자 영숫자·하이픈만 허용, 규칙 위반 시 익명 처리 |
| `capabilities` | 없음 | v1 호환 확장 필드. 선언·문서 용도 (정식 검증은 로드맵) |

주의할 점:

- **`name`은 디렉토리가 진실이다.** Manifest에 다른 `name`을 적으면 경고 로그를 남기고 디렉토리명을 사용한다(`readManifest()`가 manifest의 `name`을 `unset` 후 디렉토리명으로 덮어쓴다). 로딩·활성 목록·Provider 클래스 조립이 모두 이름 기반이기 때문이다.
- 위 표에 없는 키는 정규화되지 않고 그대로 보존된다. BoardReport의 `category`처럼 코어가 아직 해석하지 않는 필드도 manifest에 둘 수 있다.
- 전역 식별자 `id`는 `{vendor}/{name}`으로 계산된다 (vendor가 없으면 name만). 서로 다른 제작자가 만든 같은 이름의 확장을 구분한다.

## requires — 의존성 선언

확장은 Composer 의존성 해결 대상이 아니므로, 코어가 `requires`를 직접 해석한다 (`src/Service/Extension/ExtensionCompatibility.php`). **활성화하는 순간이 유일한 검증 게이트다.**

지원 키:

```json
{
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Board": ">=1.0.0 <2.0.0",
        "plugin:Banner": "^1.2"
    }
}
```

- `core` — 코어 버전 제약 (`Application::VERSION` 기준)
- `package:{Name}` / `plugin:{Name}` — 해당 확장이 **이 설정을 적용한 뒤의 같은 도메인에서** 활성이고 버전이 맞을 것
- 알 수 없는 키는 무시된다

제약 문법 (composer의 부분집합):

| 문법 | 의미 |
|---|---|
| `*` | 아무 버전 |
| `1.2.3` | 정확히 일치 |
| `>=1.2` `>1.2` `<=1.2` `<1.2` `=1.2` `!=1.2` | 비교 연산 |
| `^1.2.3` | `>=1.2.3 <2.0.0` (0.x는 `<0.{minor+1}.0`) |
| `~1.2.3` | `>=1.2.3 <1.3.0` |
| `~1.2` | `>=1.2 <2.0.0` |
| `"A B"` 또는 `"A, B"` | AND |
| `"A \|\| B"` | OR |

해석할 수 없는 제약은 **만족으로 간주한다** — 파서 한계로 멀쩡한 확장을 막는 것보다 이상한 제약을 통과시키는 편이 낫다는 정책이다.

## 종속 Plugin의 parent

```json
{
    "name": "BoardReport",
    "type": "plugin",
    "parent": "Board",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Board": ">=1.0.0 <2.0.0"
    }
}
```

- `parent`는 실제 설치 위치와 일치해야 한다. 다르면 잘못 풀린 배포물로 간주하고 로드를 거부한다.
- `requires["package:{Package}"]`는 스캔 시 `*`로 자동 주입되지만, 공식 배포 Plugin은 검증한 버전 범위를 직접 명시할 것을 권장한다.

## 예시 — 실제 Manifest

`packages/Board/Plugins/BoardReport/manifest.json` (전문):

```json
{
    "name": "BoardReport",
    "label": "게시글 신고",
    "description": "게시글 신고 접수와 블라인드 처리. Board 패키지 종속 플러그인의 레퍼런스 구현입니다.",
    "version": "1.0.0",
    "author": "Mublo",
    "author_url": "https://github.com/wwiz-mublo/mublo",
    "vendor": "mublo",
    "type": "plugin",
    "parent": "Board",
    "category": "content",
    "icon": "bi-flag",
    "requires": {
        "core": ">=1.0.0 <2.0.0",
        "package:Board": ">=1.0.0 <2.0.0"
    },
    "capabilities": [
        "board.article.read",
        "board.article.moderate",
        "board.article.actions",
        "admin.menu"
    ]
}
```

## 정책 필드(운영자 플래그)의 정확한 의미

`default`/`mandatory`/`super_only` 는 확장의 정체성이 아니라 **배포의 운영
정책**이다 — SaaS 실운영에서 필요해 추가된 기능으로, 확장을 개발·배포할 때는
설정하지 않는다 (누락 시 코어가 전부 `false` 로 정규화). 코어는 플래그가
있으면 아래대로 작동하며, 최종 소비는 자기 배포의 manifest 에 플래그를
추가하는 운영자의 몫이다.

- `default` — **설치 시점**에만 작동한다. 설치기·서브도메인 시드가 활성 목록에 넣는다. 운영자가 끈 default 확장을 시스템이 되살리지 않는다.
- `mandatory` — "끌 수 없음"만 의미한다. 활성인 mandatory 확장을 끄는 저장은 차단(되살림)되지만, 애초에 활성이 아니면 아무것도 강제하지 않는다.
- `super_only` — 루트 도메인에서 활성이면 하위 도메인에서 강제 활성된다. 하위 도메인의 저장 요청에서 이 Plugin에 대한 직접 제어는 제거된다.

## 관련 문서

- [11. Package](11-package.md) · [12. Plugin](12-plugin.md) · [14. Extension](14-extension.md)
- 호환성 정책: `docs/compatibility-policy.md`
- Manifest v2 로드맵: [34. Technical Roadmap](34-roadmap.md)
