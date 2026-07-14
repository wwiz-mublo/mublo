# Mublo 문서

이 문서는 Mublo Core `1.0.0`과 현재 저장소의 Package·Plugin 구현을 기준으로 한다. 운영자는 설치와 사이트 관리 문서부터, 확장 개발자는 Architecture Book과 개발자 가이드부터 읽으면 된다.

## 시작 경로

| 독자 | 시작 문서 | 다음 문서 |
|---|---|---|
| 처음 설치하는 사용자 | [설치 가이드](user-guide/installation.md) | [따라하기 튜토리얼](tutorials/README.md) |
| 사이트 운영자 | [사용자 가이드](user-guide/README.md) | [블록으로 페이지 만들기](user-guide/block-page-builder.md) |
| 스킨·블록 제작자 | [스킨 제작 튜토리얼](dev-guide/skin-development.md) | [Front 스킨 데이터 계약](reference/front-view-data-contract.md) · [테마·스킨·렌더링](architecture/18-theme-rendering.md) |
| Plugin 개발자 | [Plugin](architecture/12-plugin.md) | [Manifest](architecture/13-manifest.md) · [Plugin Guide](architecture/30-plugin-guide.md) |
| Package 개발자 | [Package](architecture/11-package.md) | [Public API](architecture/15-public-api.md) · [Package Guide](architecture/29-package-guide.md) |
| Core 기여자 | [Architecture Book](architecture/README.md) | [호환성 정책](compatibility-policy.md) · [기여 가이드](../CONTRIBUTING.md) |

## 개요

- [Mublo 철학](philosophy.md) — Core와 확장의 책임을 나누는 기준
- [확장 모델](extension-model.md) — Plugin, Package, Event, Contract, Block의 관계
- [블록 편집 개요](block-editor-overview.md) — 운영자와 개발자가 함께 보는 블록 구조
- [Architecture Book](architecture/README.md) — 실제 코드에 연결한 34장 아키텍처 문서
- [호환성 정책](compatibility-policy.md) — 안정 API와 내부 구현의 경계

## 따라하기 튜토리얼

처음 설치한 분을 위한 실습 문서입니다. 순서대로 따라 하면 됩니다.

| 문서 | 내용 |
|---|---|
| [설치 직후 첫 30분](tutorials/01-first-30-minutes.md) | 관리자 접속, 사이트 이름, 메인 문구 수정, 첫 공지 |
| [메인 페이지를 블록으로 꾸미기](tutorials/02-main-page-blocks.md) | 행/열 만들기, 이미지·최신글 블록, 제목 설정 |
| [게시판 만들고 메뉴에 연결하기](tutorials/03-new-board.md) | 게시판 생성, 권한, 메뉴 연결, 메인 노출 |

## 사용자 가이드

| 문서 | 내용 |
|---|---|
| [설치](user-guide/installation.md) | 요구사항, 업로드, Composer, 웹 설치기, 설치 후 보안 |
| [첫 실행](user-guide/first-setup.md) | 설치 직후 기본 설정과 관리자 접속 |
| [관리자 기본](user-guide/admin-basics.md) | 관리자 메뉴와 공통 조작 |
| [멀티 도메인](user-guide/domain-management.md) | 도메인 추가, 설정·테마·확장 분리 |
| [블록 페이지](user-guide/block-page-builder.md) | 행·열·콘텐츠 타입·스킨 편집 |
| [게시판](user-guide/board-usage.md) | 게시판 생성, 권한, 카테고리, 댓글 |
| [회원](user-guide/member-management.md) | 회원 등급, 권한, 포인트 |
| [Plugin](user-guide/plugin-usage.md) | 번들 Plugin 활성화와 설정 |
| [문제 해결](user-guide/troubleshooting.md) | 설치·캐시·권한·화면 문제 확인 |

## 개발자 가이드와 레퍼런스

- [개발자 가이드](dev-guide/README.md) — 라우팅, DB, Event, Contract, 확장 개발, 테스트
- [스킨 제작 튜토리얼](dev-guide/skin-development.md) — 콘텐츠 스킨부터 프레임·블록·게시판·쇼핑몰 스킨까지 실습
- [API·스키마 레퍼런스](reference/README.md) — 디렉토리, DB, 설정, Event, 확장 지점
- [Front 스킨 데이터 계약](reference/front-view-data-contract.md) — 모든 스킨의 `$mublo` 공통 정보와 화면 payload 규칙
- [AI 확장 API](reference/ai-extension-api.md) — `Mublo\Contract\AI` 사용 계약과 예제
- [확장 필수사항](dev-guide/extension-requirements.md) — Package·Plugin 배포 전 MUST/SHOULD
- [테스트](dev-guide/testing.md) — PHPUnit 구조와 실행 방법

## 문서 기준

- 현재 동작은 소스 코드와 테스트가 기준이다.
- 안정 API 범위와 변경 규칙은 [호환성 정책](compatibility-policy.md)이 기준이다.
- Package가 공개하는 Event·Contract는 해당 Package README와 공개 네임스페이스를 함께 확인한다.
- 구현되지 않은 항목은 [Technical Roadmap](architecture/34-roadmap.md)에서 현재 기능과 분리해 다룬다.
