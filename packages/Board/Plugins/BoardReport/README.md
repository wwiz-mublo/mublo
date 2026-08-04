# BoardReport

BoardReport는 Board 패키지 종속 Plugin의 공식 레퍼런스입니다.

## 배치 규약

- 위치: `packages/Board/Plugins/BoardReport`
- 활성 키: `Board/BoardReport`
- 부모: `Board`
- 호환성: Manifest v1의 `requires["package:Board"]`
- Migration source/name: `plugin`, `Board/BoardReport`

BoardProvider의 `PluginHostInterface` 구현이 이 Plugin을 발견하며, Core는 부모 Package를 먼저 register/install/boot하고 비활성화할 때는 Plugin을 먼저 처리합니다.

## 허용된 Board 의존성

- `Mublo\Packages\Board\Contract\Extension\*`
- `Mublo\Packages\Board\Api\DTO\*`
- `Mublo\Packages\Board\Event\*`

Board의 `Service`, `Repository`, `Entity`, `Helper`, Controller와 DB 테이블은 내부 구현이므로 직접 참조하지 않습니다. `php tools/check-extension-api.php`가 이 경계를 검사합니다.

## 새 Board Plugin 만들기

1. 이 디렉토리를 `packages/Board/Plugins/{PluginName}`으로 복사합니다.
2. namespace, Provider, Manifest의 이름과 설명을 변경합니다.
3. `requires["package:Board"]`에 검증한 Board 버전 범위를 기록합니다.
4. 필요한 기능은 `BoardExtensionApiInterface`와 공식 Event로만 연결합니다.
5. Plugin 전용 테이블은 자체 `database/migrations`에서 관리합니다.
6. 부모 미설치·비활성, 버전 불일치, Migration 실패를 테스트합니다.

`capabilities`는 현재 v1 호환 확장 필드이자 문서 역할을 합니다. Core의 정식 capability validation과 Manifest v2는 범용 Extension Runtime이 필요해질 때 도입합니다.
