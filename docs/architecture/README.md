# Mublo Architecture Book

Mublo Core와 확장 Runtime, Package·Plugin, 내장 서브시스템을 실제 소스 기준으로 설명한다. 현재 기준은 Mublo Core `1.0.0`의 `main` 구현이며 이후 변경은 문서와 소스를 함께 갱신해야 한다.

이 책은 운영자 매뉴얼이 아니다. 설치와 화면 운영은 [사용자 가이드](../user-guide/README.md), 빠른 개발 절차는 [개발자 가이드](../dev-guide/README.md), 안정 API의 규범은 [호환성 정책](../compatibility-policy.md)을 본다.

## 제1부 — 코어 Runtime

| 장 | 내용 |
|---|---|
| [01. Philosophy](01-philosophy.md) | Mublo의 책임 분리와 확장 철학 |
| [02. Core](02-core.md) | Application 부팅 순서와 Core의 책임 |
| [03. Container](03-container.md) | 의존성 등록과 Auto Wiring 경계 |
| [04. Context](04-context.md) | 요청 문맥과 도메인 해석 |
| [05. Router](05-router.md) | 라우트 등록·디스패치와 확장 게이트 |
| [06. Request](06-request.md) | Request 객체와 입력 접근 |
| [07. Response](07-response.md) | HTML·JSON·View·Redirect·File 응답 |
| [08. Event](08-event.md) | EventDispatcher, Subscriber, 공식 Event 규약 |

## 제2부 — 플랫폼과 확장 Runtime

| 장 | 내용 |
|---|---|
| [09. 멀티 도메인](09-multi-domain.md) | 도메인별 상태·설정·확장 분리 |
| [10. 인프라 서비스](10-infrastructure.md) | Database·Cache·Storage·Mail·Log·보안 기반 |
| [11. Package](11-package.md) | Package 구조, Provider, 생명주기 |
| [12. Plugin](12-plugin.md) | 독립 Plugin과 Package 종속 Plugin |
| [13. Manifest](13-manifest.md) | `manifest.json`과 `requires` 검증 |
| [14. Extension Runtime](14-extension.md) | 발견·등록·부팅·설치·실패 격리 |
| [15. Public API](15-public-api.md) | 안정 API와 내부 구현의 경계 |
| [16. Contract 카탈로그](16-contract-catalog.md) | Core 공개 Contract와 Registry |

## 제3부 — 내장 서브시스템

| 장 | 내용 |
|---|---|
| [17. 블록 시스템](17-block-system.md) | 콘텐츠 타입·아이템·스킨·블록페이지·블록킷 |
| [18. 테마·스킨·렌더링](18-theme-rendering.md) | Front/Admin 렌더링과 Frame Override |
| [19. 회원·커스텀 필드](19-member-custom-fields.md) | 가입 Event, 회원 등급, 필드, 약관 |
| [20. 권한 모델](20-permission-model.md) | 도메인 운영자, 관리자 권한, 대리 로그인 |
| [21. 포인트](21-balance-point.md) | BalanceManager와 확장별 정책 |
| [22. 대시보드 위젯](22-admin-dashboard-widgets.md) | 관리자 위젯과 메뉴 확장 |
| [23. Report 엔진](23-report-engine.md) | CSV·XLSX·PDF, 청크, 권한, 감사 |
| [24. 알림](24-notification.md) | 내부 알림과 외부 채널 Contract |
| [25. 검색·마이페이지·메뉴](25-search-mypage-menu.md) | Package가 결과·섹션·메뉴를 공급하는 방법 |
| [26. 통계·트래킹](26-tracking.md) | VisitorStats와 전환 추적 경계 |
| [27. AI 시스템](27-ai.md) | HTML 블록 AI와 공개 AI Contract |
| [28. 클라이언트 JS](28-client-js.md) | 공개 JS 라이브러리와 내부 관리자 스크립트 |

## 제4부 — 확장 개발

| 장 | 내용 |
|---|---|
| [29. Package Guide](29-package-guide.md) | Package 제작 절차 |
| [30. Plugin Guide](30-plugin-guide.md) | 독립·종속 Plugin 제작 절차 |
| [31. Best Practice](31-best-practice.md) | 권장 설계 패턴 |
| [32. Anti Pattern](32-anti-pattern.md) | 금지·주의 패턴 |
| [33. Reference Packages](33-reference-packages.md) | Board·Shop과 번들 확장 사례 |
| [34. Technical Roadmap](34-roadmap.md) | 현재 구현 밖의 기술 항목과 착수 조건 |

## 확장 유형 선택

- 화면 표현만 바꾸면 스킨을 추가한다.
- 기존 블록에 선택 가능한 데이터를 추가하면 아이템 공급자나 수집 Event를 사용한다.
- Core에 독립적인 기능을 붙이면 Plugin으로 만든다.
- 특정 Package의 공개 API에 결합하면 종속 Plugin으로 만든다.
- 하나의 업무 영역과 자체 확장 표면을 소유하면 Package로 만든다.
