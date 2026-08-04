# 개발자 가이드

Mublo Framework의 아키텍처를 이해하고, 패키지와 플러그인을 개발하기 위한 가이드입니다.

## 추천 읽기 경로

1. [Mublo 철학](../philosophy.md)
2. [확장 모델](../extension-model.md)
3. [Architecture Book](../architecture/README.md)
4. [아키텍처 개요](architecture.md)
5. [요청 흐름](request-lifecycle.md)
6. 이후 필요한 주제별 문서

## 읽는 순서

### 기초 (Core 이해)

1. [Mublo 철학](../philosophy.md) — Core는 가볍게, 확장은 자유롭게
2. [확장 모델](../extension-model.md) — Plugin, Package, Event, Contract, Block
3. [아키텍처 개요](architecture.md) — 플랫폼 구조, 핵심 시스템, 계층 분리
4. [요청 흐름](request-lifecycle.md) — 부팅에서 응답까지 전체 흐름
5. [핵심 개념](core-concepts.md) — DI, Context, Result, Response
6. [라우팅과 미들웨어](routing.md) — 라우트 등록, 자동 매핑, 미들웨어
7. [데이터베이스](database.md) — DB 접근 API, QueryBuilder, 마이그레이션
8. [QueryBuilder 가이드](query-builder.md) — 쿼리 빌더 전체 레퍼런스, 실전 패턴, 안티패턴

### 클라이언트

9. [스킨 제작 튜토리얼](skin-development.md) — 콘텐츠·프레임·블록 스킨을 실제로 만들고 적용
10. [클라이언트 AJAX 시스템](client-ajax.md) — MubloRequest, MubloModal, 폼 제출

### 확장 개발

11. [이벤트 시스템](event-system.md) — EventDispatcher, Subscriber, 이벤트 발행
12. [Contract 시스템](contract-system.md) — ContractRegistry, 크로스커팅 인터페이스
13. [Manifest 기준](manifest-standard.md) — Package/Plugin 메타데이터 표준
14. [블록 편집 개요](../block-editor-overview.md) — 운영자와 개발자가 함께 보는 블록 구조
15. [블록 시스템 개발](block-system.md) — BlockRegistry, Renderer, 스킨 연동
16. [패키지 만들기](package-development.md) — 독립 애플리케이션 개발
17. [플러그인 만들기](plugin-development.md) — Core 기능 확장 개발
18. [확장 필수사항](extension-requirements.md) — 배포 전 지켜야 할 규범 (MUST/SHOULD)

### 품질

19. [호환성 정책](../compatibility-policy.md) — 안정 API와 내부 API 구분
20. [에러 처리 컨벤션](error-handling.md) — Result vs 예외, 계층별 경계 규칙
21. [테스트](testing.md) — PHPUnit, 테스트 구조, 작성법
22. [기여 가이드](contributing.md) — 코드 스타일, PR 규칙, 커밋 메시지

---

[< 문서 홈으로](../README.md)
