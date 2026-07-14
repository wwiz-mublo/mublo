# Mublo(머블로) Framework

**Core는 가볍게, 확장은 자유롭게.**

**Mublo의 공식 한글 표기는 "머블로"입니다.** Mublo(머블로)는 Multi와 Block에서 온 이름으로, 하나의 Core에서 여러 서비스를 운영하고 Block과 Block Item으로 화면과 데이터를 자유롭게 조합한다는 의미를 담고 있습니다.

Mublo(머블로)는 하나의 가벼운 Core 위에서 Plugin, Package, Event, Contract, Block 시스템을 조합해 여러 종류의 웹 서비스를 운영하는 PHP 애플리케이션 플랫폼입니다.

Mublo의 Core는 요청 흐름, 라우팅, DI, 인증, 세션, 렌더링, 이벤트, 관리자 기반처럼 공통 실행 규칙만 담당합니다. 실제 기능은 Plugin과 Package로 분리되고, Core와 확장은 Event 또는 Contract를 통해 느슨하게 연결됩니다.

운영자는 블록 시스템으로 페이지를 구성하고, 개발자는 블록 콘텐츠 타입과 블록 아이템 공급자를 추가해 편집 영역을 확장할 수 있습니다. 하나의 Core에서 회사 홈페이지, 쇼핑몰, 커뮤니티처럼 성격이 다른 사이트를 도메인별 디자인과 기능 조합으로 운영할 수 있습니다.

## 핵심 철학

- **Light Core**: Core는 공통 실행 규칙과 안정적인 확장 포인트만 제공합니다.
- **Free Extension**: 기능은 Plugin과 Package로 분리해 필요한 만큼 붙이고 뺄 수 있습니다.
- **Loose Coupling**: 상태 변화는 Event, 데이터/기능 호출은 Contract로 연결합니다.
- **No-code Editing**: 운영자는 Block 시스템으로 페이지와 출력 영역을 직접 구성합니다.
- **Dynamic Items**: Block Item 공급자가 Package/Plugin의 데이터를 편집 가능한 출력 항목으로 제공합니다.
- **Multi Service**: 도메인마다 Theme, Package, Plugin, Block 구성을 다르게 적용할 수 있습니다.
- **Practical Packages**: Board와 Shop이 기본 포함되어 확장 구조를 실제 코드로 검증합니다.

## 설계 방향

- 관리자, 회원, 권한, 파일, 페이지 편집 같은 공통 운영 기반을 제공합니다.
- Controller → Service → Repository → Entity 구조로 업무 기능을 분리합니다.
- 명시적인 Event 클래스와 Contract 인터페이스로 확장 경계를 정의합니다.
- Board, Shop 같은 실전 패키지를 통해 확장 아키텍처를 직접 확인할 수 있습니다.
- 기술 스택 자체보다 Core와 확장 기능이 결합되는 방식을 중요하게 봅니다.

Mublo의 차별점은 단순한 멀티 도메인이 아닙니다. 하나의 Core 위에서 도메인별로 Package, Plugin, Theme, Block, Block Item을 다르게 조합해 서로 다른 서비스를 운영할 수 있다는 점입니다.

## 주요 특징

### 하나의 Core, 여러 서비스

Mublo의 멀티 도메인은 단순히 여러 도메인을 등록하는 기능이 아닙니다. 도메인마다 활성화할 Package와 Plugin, 테마, 블록 페이지, 출력 아이템을 다르게 조합하는 운영 단위입니다.

```text
company.example.com    → 회사 홈페이지  → 소개 페이지, 공지, 배너, FAQ
community.example.com  → 커뮤니티      → 게시판, 댓글, 첨부파일, 포인트
shop.example.com       → 쇼핑몰        → 상품, 장바구니, 주문, 결제, 리뷰
```

이 구성이 가능한 이유는 Core가 특정 서비스에 종속되지 않고, 기능은 Package/Plugin으로 분리되며, 화면은 Block과 Block Item으로 조립되기 때문입니다.

### Core / Plugin / Package 분리

Mublo는 기능을 세 층으로 나눕니다.

| 구분 | 역할 | 예시 |
|------|------|------|
| **Core** | 공통 실행 규칙과 기반 서비스 | 라우팅, DI, 인증, 세션, 렌더링, 이벤트, 관리자 기반 |
| **Plugin** | Core나 화면에 붙는 작은 기능 확장 | 배너, 팝업, FAQ, 소셜 로그인, 포인트, 통계 |
| **Package** | 자체 MVC와 데이터 구조를 가진 독립 애플리케이션 | Board, Shop |

Plugin과 Package는 모두 Provider를 통해 Core에 연결됩니다. Core 코드를 직접 수정하지 않고 서비스 등록, 이벤트 구독, 라우트 추가, 블록 등록, Contract 바인딩을 수행합니다.

이 구조의 장점은 Core가 작고 안정적으로 유지된다는 점입니다. 확장 개발자는 Provider 생명주기와 Manifest 규칙을 먼저 익혀야 합니다.

### Event / Contract 기반 확장

Mublo는 문자열 기반 전역 훅보다 명시적인 이벤트와 계약을 선호합니다.

- **Event**: "무언가 일어났다"를 알리고 후처리를 붙입니다.
  예: 회원가입 후 포인트 지급, 관리자 메뉴 추가, 로그인 폼에 소셜 로그인 버튼 삽입

- **Contract**: "특정 기능이나 데이터를 호출해서 결과를 받는" 구조입니다.
  예: 결제 게이트웨이, 알림 발송, FAQ 조회, 카테고리 공급

이 기준 덕분에 확장 모듈이 Core에 강하게 묶이지 않고, 서로 필요한 지점에서만 연결됩니다.

Event는 여러 확장이 동시에 반응하기 좋고, Contract는 결제/알림/FAQ 조회처럼 명확한 기능 제공자를 바꿔 끼우기 좋습니다. 다만 Event는 실행 순서와 실패 정책을, Contract는 인터페이스 안정성을 꾸준히 관리해야 장기 호환성이 좋아집니다.

### Block / Block Item 시스템

Mublo의 블록 시스템은 단순한 HTML 조각 편집기가 아닙니다.

운영자는 관리자에서 행(Row), 열(Column), 콘텐츠 타입, 스킨, 설정을 조합해 페이지를 구성합니다. 개발자는 Plugin/Package에서 블록 콘텐츠 타입과 출력 아이템 공급자를 등록해 편집 가능한 콘텐츠의 종류를 늘릴 수 있습니다.

예를 들어:

- Board 패키지는 게시판, 게시글, 최신글 블록을 공급합니다.
- Shop 패키지는 상품, 리뷰, 기획전 블록을 공급합니다.
- Banner 플러그인은 배너 그룹을 블록 아이템으로 공급합니다.
- Widget, Popup 같은 플러그인은 프론트 출력 영역에 운영 기능을 붙입니다.

즉, 개발자는 확장 API를 만들고 운영자는 관리자에서 조합합니다. Block Item은 특히 중요합니다. 블록이 "어디에 어떤 모양으로 출력할지"를 담당한다면, Block Item은 "무엇을 출력할지"를 Package/Plugin이 동적으로 공급합니다.

이 덕분에 운영자는 코드 수정 없이 같은 상품 블록이라도 도메인별로 다른 상품, 다른 정렬, 다른 스킨을 선택할 수 있습니다. 개발자는 새로운 업무 기능을 만들 때 해당 기능의 출력 아이템만 공급하면 페이지 편집 시스템에 자연스럽게 참여시킬 수 있습니다.

### 아키텍처 설계 관점

| 구성 | 장점 | 주의할 점 |
|------|------|-----------|
| **Core** | 요청 흐름과 공통 규칙에 집중해 작고 안정적으로 유지하기 좋음 | 업무 기능을 Core에 넣기 시작하면 철학이 약해짐 |
| **Plugin** | 배너, FAQ, 알림, 결제처럼 작고 수평적인 기능을 붙이기 좋음 | 서로 다른 Plugin 간 직접 의존은 피하고 Event/Contract를 사용해야 함 |
| **Package** | Board, Shop처럼 자체 데이터와 화면을 가진 업무 영역을 독립적으로 구성 가능 | 규모가 커지므로 내부 계층과 문서화가 중요함 |
| **Event** | 회원가입, 주문완료, 도메인 생성 같은 상태 변화에 여러 확장이 반응 가능 | 실행 순서, 실패 허용 여부, 이벤트 의미를 명확히 관리해야 함 |
| **Contract** | 결제, 알림, 본인인증처럼 구현체를 교체하거나 여러 제공자를 등록하기 좋음 | 안정 인터페이스로 관리해야 외부 확장이 오래 유지됨 |
| **Block** | 운영자가 페이지 구조와 스킨을 조합할 수 있음 | 캐시, 사용자 상태, 반응형 스킨 정책을 함께 관리해야 함 |
| **Block Item** | Package/Plugin 데이터를 선택 가능한 출력 항목으로 공급함 | 아이템 목록의 도메인 범위와 권한 처리가 중요함 |
| **Domain** | 하나의 설치에서 서비스별 설정, 테마, 확장 활성화를 분리 가능 | 운영 정책과 데이터 격리 기준을 명확히 해야 함 |

### 실전 패키지 포함

Mublo는 빈 프레임워크만 제공하지 않습니다. 기본 저장소에 게시판과 쇼핑몰 패키지가 포함되어 있습니다.

- **Board**: 게시판, 그룹, 카테고리, 권한, 댓글, 첨부파일, 리액션, 포인트 연동
- **Shop**: 상품, 카테고리, 옵션, 장바구니, 주문, 결제, 쿠폰, 배송, 리뷰, 문의, 블록 연동

두 패키지는 Mublo의 Provider, routes, migrations, Event, Contract, Block 구조를 실제 업무 기능에 적용한 예시이기도 합니다.

### 운영 기반

Mublo는 반복 구현되기 쉬운 운영 기능을 Core와 확장 모듈에 나누어 제공합니다.

- 관리자 화면
- 회원/권한/레벨
- 도메인별 설정과 테마
- 파일 업로드와 보안 다운로드
- 캐시와 마이그레이션 관리
- 통합 검색
- 포인트/잔액 원장
- 블록 기반 페이지 구성

멀티 도메인 운영도 이 기반 위에 포함됩니다. 하나의 코드베이스에서 여러 사이트를 운영하고, 도메인별 설정, 회원, 권한, 테마, 확장 활성화를 분리할 수 있습니다.

## 아키텍처 한눈에 보기

```text
                 ┌────────────────────────────┐
                 │            Core            │
                 │ request, routing, DI, auth, │
                 │ rendering, events, admin    │
                 └──────────────┬─────────────┘
                                │
                      Domain Context
                theme + settings + extensions
                                │
        ┌───────────────────────┼───────────────────────┐
        │                       │                       │
   Event Push              Contract Pull          Block Registry
        │                       │                       │
┌───────▼────────┐      ┌──────▼──────┐      ┌─────────▼─────────┐
│     Plugin     │      │   Package   │      │    Page Builder   │
│ Banner, FAQ,   │      │ Board, Shop │      │ Row, Column, Skin │
│ Payment, Popup │      │             │      │ + Block Items     │
└───────┬────────┘      └──────┬──────┘      └─────────┬─────────┘
        │                      │                       │
        └────────────── item providers ────────────────┘
```

Core는 도메인을 해석하고, 도메인 설정에 따라 활성화된 확장만 로딩합니다. Package/Plugin은 Event와 Contract로 서로를 느슨하게 연결하고, Block Item Provider를 통해 자신이 가진 데이터를 페이지 편집 시스템에 공급합니다.

## 이런 경우에 적합합니다

- PHP로 관리자 기능이 포함된 웹 서비스를 만들고 싶은 팀
- 관리자·회원·권한·파일·페이지 편집 기반을 반복 구현하고 싶지 않은 팀
- 확장 지점을 Event와 Contract로 명시해 장기적으로 관리하고 싶은 팀
- 기능을 Plugin/Package 단위로 나누어 장기적으로 확장하고 싶은 팀
- 게시판형 사이트, 브랜드 사이트, 쇼핑몰, 운영형 랜딩 페이지를 한 기반에서 다루고 싶은 팀

## 대표 사용 시나리오

### 회사/브랜드 사이트

- 블록 기반 페이지 구성
- 배너, 팝업, 위젯, FAQ 조합
- 방문자 통계와 설문으로 운영 데이터 수집

### 커뮤니티/게시판 중심 사이트

- 게시판 그룹/카테고리 운영
- 댓글, 첨부파일, 리액션, 포인트 연동
- 회원 기능과 소셜 로그인을 함께 사용

### 운영 기능이 포함된 쇼핑몰

- 상품, 장바구니, 주문, 결제, 쿠폰, 배송 관리
- 회원 포인트와 리뷰/문의 기능 결합
- 결제 게이트웨이와 이벤트 기반 후처리 확장

### 멀티 사이트 운영

- 하나의 코드베이스에서 여러 사이트 운영
- 도메인별 테마, 설정, 회원, 권한 분리
- 도메인별 Plugin/Package 활성화 제어

## 요구사항

- PHP 8.2 이상
- MySQL 5.7.8 이상 또는 MariaDB 10.3 이상
- 신규 운영 권장: MySQL 8.4 LTS 또는 MariaDB 10.11 LTS 이상
- Composer
- 필수 PHP 확장: pdo, pdo_mysql, mysqli, mbstring, openssl, json, curl, fileinfo, gd
- 권장 PHP 확장: zip, xml, intl

## 빠른 시작

```bash
# 1. 의존성 설치
composer install

# 2. 파일을 웹 서버 디렉토리에 배치하고 public/을 웹 루트로 설정

# 3. 브라우저에서 설치 페이지 접속
https://your-domain.com/install

# 4. 웹 설치기의 안내에 따라 진행
# DB 설정 → 도메인 설정 → 보안 설정 → 관리자 계정 생성 → 완료
```

설치가 완료되면 `/admin`으로 관리자 화면에 접속할 수 있습니다.

상세 설치 가이드: [docs/user-guide/installation.md](docs/user-guide/installation.md)

## 문서

문서는 Mublo의 설계 철학을 먼저 이해한 뒤, 확장 모델과 운영/개발 가이드로 자연스럽게 이어지도록 구성되어 있습니다.
처음 살펴본다면 문서 홈에서 전체 흐름을 확인하고, 필요한 역할에 맞춰 운영자 가이드나 개발자 가이드로 이동하면 됩니다.

| 대상 | 링크 | 설명 |
|------|------|------|
| 처음 보는 사람 | [문서 홈](docs/README.md) | 전체 문서의 시작점 |
| 아키텍처 독자 | [Architecture Book](docs/architecture/README.md) | 실제 소스 기준 Core·확장·내장 시스템 해설 |
| 처음 보는 사람 | [Mublo 철학](docs/philosophy.md) | Core는 가볍게, 확장은 자유롭게 |
| 처음 보는 사람 | [확장 모델](docs/extension-model.md) | Plugin, Package, Event, Contract, Block의 관계 |
| 운영자/개발자 | [블록 편집 개요](docs/block-editor-overview.md) | 블록과 블록 아이템 시스템 개요 |
| 운영자 | [사용자 가이드](docs/user-guide/README.md) | 설치, 관리자 사용법, 도메인/게시판/블록 운영 |
| 개발자 | [개발자 가이드](docs/dev-guide/README.md) | 아키텍처, 요청 흐름, 패키지/플러그인 개발 |
| 확장 개발자 | [이벤트 시스템](docs/dev-guide/event-system.md) | Core 이벤트와 Subscriber 구조 |
| 확장 개발자 | [Contract 시스템](docs/dev-guide/contract-system.md) | ContractRegistry와 구현체 연결 |
| 확장 개발자 | [블록 시스템](docs/dev-guide/block-system.md) | BlockRegistry, Renderer, Item 공급 |
| 확장 개발자 | [호환성 정책](docs/compatibility-policy.md) | 안정 API와 내부 API 구분 |
| 레퍼런스 | [API/스키마 레퍼런스](docs/reference/README.md) | DB 스키마, 이벤트 목록, 설정 파일, 확장 포인트 |

## 포함 패키지

| 패키지 | 설명 |
|--------|------|
| [Board](packages/Board/README.md) | 게시판 — 다단계 권한, 리액션, 중첩 댓글, 카테고리, 첨부파일, 포인트 연동 |
| [Shop](packages/Shop/README.md) | 쇼핑몰 — 상품, 장바구니, 주문, 결제, 쿠폰, 배송, 리뷰, 문의, 블록 연동 |

## 주요 포함 플러그인

아래는 대표적인 번들 Plugin입니다. 전체 목록과 각 확장의 아키텍처 역할은 [Reference Packages](docs/architecture/33-reference-packages.md)를 참고하세요.

| 플러그인 | 설명 |
|----------|------|
| [Banner](plugins/Banner/README.md) | 이미지 배너 관리, 스케줄 표시, 블록 연동 |
| [FAQ](plugins/Faq/README.md) | FAQ 카테고리/항목 관리, 프론트 페이지, Contract 기반 조회 |
| [Widget](plugins/Widget/README.md) | 고정 위치 위젯, PC 사이드바, 모바일 하단 출력 |
| [Popup](plugins/Popup/README.md) | 레이어 팝업, 스케줄, 디바이스 타겟팅 |
| [MemberPoint](plugins/MemberPoint/README.md) | 회원 포인트 적립/차감, 이벤트 기반 자동 지급 |
| [VisitorStats](plugins/VisitorStats/README.md) | 서버사이드 방문자 통계, 대시보드 위젯 |
| [SnsLogin](plugins/SnsLogin/README.md) | 소셜 로그인 |
| [Survey](plugins/Survey/README.md) | 설문 작성, 수집, 결과 집계 |

## 디렉토리 구조

```text
mublo/
├── config/          # 설정 파일 (설치 시 자동 생성)
├── database/        # Core 마이그레이션
├── docs/            # 문서
├── packages/        # 패키지 (Board, Shop)
├── plugins/         # 플러그인 (Banner, FAQ 등)
├── public/          # 웹 루트 (index.php, install/, storage/)
├── src/             # Core 소스
├── storage/         # 캐시, 로그, 세션, 임시 파일
├── tests/           # 테스트
└── views/           # 뷰 템플릿
```

## 품질

Mublo는 PHPUnit 테스트와 DI 규칙 검사를 포함합니다.

```bash
composer test
composer check
```

## 버전과 호환성

현재 Core 버전은 `1.0.0`입니다. 확장에서 사용할 수 있는 안정 API와 변경 규칙은 [호환성 정책](docs/compatibility-policy.md)을 기준으로 합니다. 내부 구현에 의존하지 않고 문서에 공개된 Event·Contract·Provider 규약을 사용해야 합니다.

## 기여

기여를 환영합니다. 개발 환경, 코드 스타일, PR 규칙은 [CONTRIBUTING.md](CONTRIBUTING.md)를 참고해 주세요.

## 보안

보안 취약점은 공개 이슈 대신 [SECURITY.md](SECURITY.md)의 절차를 따라 신고해 주세요.

## 라이선스

MIT License
