# 디렉토리 구조

> 설치 후 생성되는 `config/`, `storage/` 디렉토리는 설명에 포함하지만 Git 추적 대상은 아닙니다.

## 최상위 구조

```
framework/
├── bootstrap.php          # 경로 상수, 오토로더
├── composer.json          # 의존성, PSR-4 매핑, 스크립트
├── phpunit.xml.dist       # 테스트 설정
├── .env.example           # 환경 변수 예시
├── config/                # 설정 파일 (설치 후 생성)
├── database/              # Core 마이그레이션, 시더
├── docs/                  # 공개 문서
├── packages/              # Package (독립 애플리케이션)
├── plugins/               # Plugin (기능 확장)
├── public/                # 웹 루트
├── src/                   # Core 소스 코드
├── storage/               # 캐시, 로그, 세션, 임시 파일 (설치 후 생성)
├── tests/                 # 테스트
├── tools/                 # 유지보수 스크립트
└── views/                 # 공용 뷰 템플릿
```

## src/ (Core 소스)

```
src/
├── Contract/              # 크로스커팅 계약 인터페이스
├── Controller/            # HTTP 컨트롤러
│   ├── Admin/             # 관리자
│   ├── Front/             # 프론트
│   └── Api/               # API
├── Core/                  # 프레임워크 핵심
│   ├── App/               # Application, Router
│   ├── Block/             # BlockRegistry, Renderer
│   ├── Container/         # DependencyContainer
│   ├── Context/           # Context, ContextBuilder
│   ├── Event/             # EventDispatcher
│   ├── Extension/         # ExtensionManager, MigrationRunner
│   ├── Install/           # Installer
│   ├── Middleware/        # Session/Csrf/Admin/Auth Middleware
│   ├── Rendering/         # Front/Admin 렌더링
│   ├── Response/          # JsonResponse, ViewResponse 등
│   └── Result/            # Result 반환 객체
├── Entity/                # 도메인 모델
├── Enum/                  # PHP 8.2+ Enum
├── Exception/             # 커스텀 예외
├── Helper/                # 유틸리티
├── Infrastructure/        # DB, 캐시, 메일, 스토리지, 보안
├── Repository/            # 데이터 접근 계층
├── Service/               # 비즈니스 로직
└── Subscriber/            # Core 이벤트 구독자
```

## packages/ (패키지)

```
packages/
├── Board/                 # 게시판 패키지
│   ├── BoardProvider.php
│   ├── manifest.json
│   ├── routes.php
│   ├── Block/             # 게시판 블록 렌더러
│   ├── Controller/        # 관리자/프론트 컨트롤러
│   ├── Entity/            # Article, Comment 등
│   ├── Enum/              # 게시판 상태/권한 Enum
│   ├── Event/             # 게시판 이벤트
│   ├── Repository/        # 게시판 데이터 접근
│   ├── Service/           # 게시판 비즈니스 로직
│   ├── Subscriber/        # 게시판 구독자
│   ├── Widget/            # RecentNoticesWidget
│   ├── views/             # 관리자/프론트/블록 뷰
│   └── database/          # 마이그레이션
└── Shop/                  # 쇼핑몰 패키지
    ├── ShopProvider.php
    ├── manifest.json
    ├── routes.php
    ├── Action/            # 주문 상태 액션
    ├── Block/             # 상품 블록
    ├── Controller/        # 관리자/프론트 컨트롤러
    ├── Entity/            # Product, Order, Coupon 등
    ├── Enum/              # 주문/결제/배송 Enum
    ├── Event/             # 쇼핑몰 이벤트
    ├── EventSubscriber/   # 쇼핑몰 구독자
    ├── Gateway/           # PG 게이트웨이 구현체
    ├── Repository/        # 상품/주문/쿠폰 데이터 접근
    ├── Service/           # 쇼핑몰 비즈니스 로직
    ├── tests/             # 패키지 전용 테스트
    ├── views/             # 관리자/프론트/블록 뷰
    └── database/          # 마이그레이션
```

## plugins/ (플러그인)

```
plugins/
├── Banner/                # 배너 관리
├── EmailNotify/           # 이메일 알림 게이트웨이
├── Faq/                   # FAQ
├── MemberPoint/           # 회원 포인트
├── PayApp/                # PayApp 결제 게이트웨이
├── Popup/                 # 레이어 팝업
├── SendonSms/             # Sendon SMS 게이트웨이
├── SendonTalk/            # Sendon 알림톡 게이트웨이
├── SnsLogin/              # 소셜 로그인
├── Survey/                # 설문
├── TestPay/               # 테스트 결제 게이트웨이 (개발용)
├── VisitorStats/          # 방문자 통계
└── Widget/                # 고정 위젯
```

각 플러그인 디렉토리는 보통 아래 형태를 따릅니다.

```
plugins/{PluginName}/
├── {PluginName}Provider.php
├── manifest.json
├── routes.php
├── Controller/
├── Repository/
├── Service/
├── views/
└── database/
```

## config/ (설정)

설치 시 자동 생성됩니다.

```
config/
├── database.php           # DB 연결
├── security.php           # 암호화 키, 검색 pepper, 비밀번호 해시, CSRF, 세션
├── mail.php               # 메일 드라이버, SMTP
└── ai.php                 # AI 제공자 설정
```

## storage/ (저장소)

설치 시 자동 생성됩니다.

```
storage/
├── cache/                 # 파일 캐시, 라우트 캐시
├── files/                 # 보안 파일
├── logs/                  # 애플리케이션 로그
├── sessions/              # 세션 파일
├── temp/                  # 임시 파일
└── installed.lock         # 설치 완료 표시
```

공개 업로드 파일은 운영 환경에서 `public/storage/` 아래에 생성될 수 있습니다.

## views/ (뷰 템플릿)

```
views/
├── Admin/                 # 관리자 공용 뷰
│   └── basic/             # 기본 관리자 스킨
├── Front/                 # 프론트 공용 뷰
├── Block/                 # 코어 블록 스킨
├── Components/            # 공용 컴포넌트
├── Error/                 # 에러 페이지
└── Mail/                  # 메일 템플릿
```

---

[< 레퍼런스 목록](README.md)
