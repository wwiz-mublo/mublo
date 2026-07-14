# 기여 가이드

Mublo Framework에 기여해 주셔서 감사합니다.

## 시작하기

1. 저장소를 포크합니다
2. 로컬에 클론합니다
3. 브랜치를 생성합니다

```bash
git checkout -b feature/my-feature
```

## 개발 환경

### 직접 구성

- PHP 8.2 이상
- MySQL 5.7 이상 (또는 MariaDB 10.3 이상)
- Composer
- 필수 PHP 확장: pdo, pdo_mysql, mysqli, mbstring, openssl, json, curl, fileinfo, gd

```bash
composer install
composer check   # DI 규칙 + 확장 API + 정적분석 + 테스트 (CI 게이트와 동일)
# composer test                 # 테스트만
# composer analyse              # 정적분석 (PHPStan level 0)
# composer check-extension-api  # 확장이 안정 API 만 쓰는지
```

로컬 개발 시 `config/`, `storage/`, `.env`는 설치 과정 또는 환경별 설정으로 준비합니다. 설치 과정은 [설치 가이드](docs/user-guide/installation.md)를 참고하세요.

## 코드 스타일

- 클래스명: PascalCase (`MemberService`)
- 메서드명: camelCase (`findById`)
- 한 클래스 한 책임
- Controller에 비즈니스 로직 금지
- Service는 Result 객체 반환

## 커밋 메시지

한글로 작성합니다.

```
feat: 회원 포인트 자동 지급 기능 추가
fix: 게시판 권한 검사 누락 수정
refactor: BoardService 메서드 분리
docs: 설치 가이드 업데이트
test: MemberService 단위 테스트 추가
```

## Pull Request

1. `main` 브랜치에서 최신 코드를 가져옵니다
2. 기능 브랜치에서 작업합니다
3. 전체 CI 게이트를 통과시킵니다 (`composer check` — DI·확장 API·정적 분석·테스트)
4. PR을 생성합니다

저장소에 [PR 템플릿](.github/pull_request_template.md)이 준비되어 있습니다. PR 생성 시 자동으로 표시됩니다.

### PR 체크리스트

- [ ] 테스트 통과
- [ ] 기존 테스트 깨뜨리지 않음
- [ ] 코드 스타일 준수
- [ ] 관련 문서 업데이트 (필요 시)
- [ ] `CHANGELOG.md`의 `[Unreleased]` 갱신 (아래 참고)

## CHANGELOG

사용자나 확장 개발자에게 보이는 변경은 `CHANGELOG.md`의 `[Unreleased]` 섹션에 함께 적는다.
[Keep a Changelog](https://keepachangelog.com/ko/1.1.0/) 형식을 따르며, `Added` / `Changed` / `Deprecated` / `Removed` / `Fixed` / `Security` 로 분류한다.

내부 리팩터링이나 테스트 추가처럼 밖에서 관찰되지 않는 변경은 적지 않아도 된다.
**코어의 안정 API가 바뀌었다면 반드시 적는다** — 확장 개발자가 이 기록으로 호환성을 판단한다.

## 이슈 등록

버그 리포트와 기능 요청에 각각 [이슈 템플릿](.github/ISSUE_TEMPLATE/)이 준비되어 있습니다.

- **버그**: "Bug Report" 템플릿 사용
- **기능 요청**: "Feature Request" 템플릿 사용
- **보안 취약점**: 공개 이슈 대신 [SECURITY.md](SECURITY.md) 절차를 따라 주세요

## 모듈 추가/변경 시 주의

- 새 패키지나 플러그인을 추가하면 `README`, `docs/README`, 해당 모듈 README를 함께 갱신합니다.
- 기존 모듈의 구조를 바꾸는 변경은 관련 문서도 함께 수정합니다.

## 질문이 있다면

이슈를 생성해 주세요.
