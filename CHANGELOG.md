# Changelog

이 프로젝트의 주요 변경 사항을 기록합니다.

형식은 [Keep a Changelog](https://keepachangelog.com/ko/1.1.0/)를 따릅니다.

## [Unreleased]

## [1.0.0] - 2026-07-26

### Added
- Core: 멀티 도메인 컨텍스트 시스템, DI 컨테이너, 이벤트 디스패처, Contract 시스템
- Core: 라우팅, 인증/세션, 데이터베이스 추상화, 블록 기반 페이지 빌더
- Core: 웹 설치기 (6단계 — 환경 체크, DB, 도메인, 보안, 관리자, 완료)
- Core: 관리자 대시보드 및 사이트 운영 기능
- Core: 확장 호환성 검사·서명 검증·마이그레이션 체크섬·데이터 초기화 계약
- Core: 시작 킷, 사이트맵, 알림, 정책 개정 이력, 블록 개정 이력 및 복구 기능
- Package: Board — 게시판, 댓글, 카테고리, 리액션, 포인트 연동
- Package: Shop — 상품, 장바구니, 주문, 결제, 쿠폰, 배송, 리뷰
- Plugin(Board): BoardReport — 게시글 신고 접수와 블라인드 처리. 패키지 종속 플러그인의 레퍼런스 구현
- Plugin: VisitorStats — 서버사이드 방문자 통계, 대시보드 위젯
- Plugin: MemberPoint — 회원 포인트 적립/차감, 이벤트 기반 자동 지급
- Plugin: Banner — 이미지 배너 관리, 스케줄 표시, 블록 연동
- Plugin: Widget — 고정 위치 위젯 (PC 좌/우, 모바일 하단)
- Plugin: Popup — 레이어 팝업 (이미지/HTML, 반응형, 디바이스별 표시)
- Plugin: Survey — 설문 작성/수집/결과 집계, 블록 연동
- Plugin: Faq — FAQ 카테고리/항목 관리
- Plugin: Qna — Q&A 질문과 답변 관리
- Plugin: SnsLogin — 소셜 로그인 (네이버, 카카오, 구글)
- Plugin: Manual — 관리자·프론트 매뉴얼 책/페이지와 블록 콘텐츠
- Plugin: TestPay — 개발·테스트용 가상 결제. 모든 결제가 즉시 성공한다
- Plugin: PayApp — 페이앱 결제 연동 (카드/휴대폰/카카오페이/네이버페이/가상계좌)
- Plugin: EmailNotify — 코어 Mailer 기반 이메일 알림 발송, 도메인별 템플릿 관리
- Plugin: SendonSms — 센드온 SMS/LMS/MMS 발송 (도메인별 API 연동)
- Plugin: SendonTalk — 센드온 API 기반 카카오 알림톡 발송

[Unreleased]: https://github.com/wwiz-mublo/mublo/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/wwiz-mublo/mublo/releases/tag/v1.0.0
