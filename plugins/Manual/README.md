# Manual

Mublo Framework 매뉴얼(사용설명서) 관리 플러그인입니다.

## Overview

- 매뉴얼 책(Book) CRUD
- 매뉴얼 페이지(Page) 트리 CRUD — `parent_id` 자기참조로 깊이 무제한
- 프론트 열람: TOC 사이드바 + 본문 (공통 `manual.css`/`manual.js` 재사용, 스크롤스파이)
- 페이지 본문은 MubloEditor(HTML) 사용
- `domain_id` 기반 멀티도메인
- 기본 포함 **게시판 매뉴얼**, **쇼핑몰 매뉴얼** 자동 준비
- 번들 **스킨 제작 가이드**를 관리자에서 선택적으로 가져오기
- 블록 콘텐츠 타입 4종: 매뉴얼 목록, 목차, 페이지, 최근 수정 문서

## Block Content Types

| 타입 | 용도 | `content_items` |
|---|---|---|
| `manual_books` | 선택한 책 또는 전체 활성 책을 카드/목록으로 표시 | 책 slug 목록, 빈 배열은 전체 |
| `manual_toc` | 한 책의 활성 페이지 목차를 계층형으로 표시 | 책 slug 1개 |
| `manual_page` | 한 페이지를 전체 본문, 요약 또는 링크 카드로 표시 | `bookSlug/pageSlug` 1개 |
| `manual_recent` | 선택한 책 또는 전체 책에서 최근 수정 페이지를 표시 | 책 slug 목록, 빈 배열은 전체 |

블록 참조는 다른 설치의 블록 킷에서도 다시 연결될 수 있도록 숫자 PK 대신 slug를 저장합니다.
책과 페이지를 변경하면 해당 도메인의 Manual 블록 행 캐시가 자동으로 무효화됩니다.

## Dependency

- Mublo Core `>=1.0.0`

## Contract

- `Mublo\Contract\Manual\ManualQueryInterface` — Manual 플러그인이 구현(`ManualService`), Core/Package가 소비.
  `ContractRegistry`에 1:1 바인딩. (신규 추가 계약이며 코어 코드를 수정하지 않음)

## Install

- 설치 라우트: `POST /admin/manual/install`
- 관리자 진입점: `/admin/manual`

## Main Routes

- Front
  - `GET /manual` — 매뉴얼 목록
  - `GET /manual/{bookSlug}` — 매뉴얼 열람
  - `GET /manual/{bookSlug}/{pageSlug}` — 특정 페이지 딥링크
- Admin
  - `GET /admin/manual` — 책 목록
  - `PUT /admin/manual/config` — 프론트 스킨 설정 저장
  - `POST /admin/manual/import/skin-development` — 편집 가능한 번들 스킨 제작 가이드 가져오기(멱등)
  - `POST /admin/manual/import/board` — 운영·스킨 제작을 포함한 게시판 매뉴얼 가져오기(멱등)
  - `POST /admin/manual/import/shop` — 운영·스킨 제작을 포함한 Mublo Shop 매뉴얼 가져오기(멱등)
  - `POST /admin/manual/book/toggle-active` — 매뉴얼의 프론트 노출/숨김 즉시 변경
  - `GET|POST|PUT|DELETE /admin/manual/book...` — 책 CRUD
  - `GET /admin/manual/pages/{bookId}` — 페이지 트리
  - `GET|POST|PUT|DELETE /admin/manual/page...` — 페이지 CRUD
  - `PUT /admin/manual/page/save-tree` — 트리 순서/계층 저장

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 계층은 Controller → Service → Repository 를 준수하며 Service는 `Result`를 반환합니다.
- 책 삭제 시 페이지는 FK `ON DELETE CASCADE`, 페이지 삭제 시 자손은 애플리케이션에서 재귀 삭제합니다.
- 번들 가이드는 각각 `skin-development`, `board-manual`, `shop-manual` 슬러그를 사용합니다. 같은 버전은 덮어쓰지 않으며, 버전이 오른 기본 Board/Shop 번들은 동봉 페이지에 한해 한 번 갱신하고 관리자가 추가한 별도 페이지는 보존합니다.
- 게시판·쇼핑몰 매뉴얼은 신규 설치 시 생성되며, 기존 도메인도 `/manual` 또는 관리자 매뉴얼 목록에 처음 진입할 때 누락된 책이 자동 생성됩니다.
- 프론트 스킨은 `views/Front/skins/{skin}/` 에 두고, 선택값은 플러그인이 소유한 `plugin_manual_configs.skin_name` 에 도메인별로 저장합니다. 선택한 스킨에 해당 뷰 파일이 없으면 파일 단위로 `basic` 에 폴백하므로, 커스텀 스킨은 바꾸려는 화면만 만들면 됩니다.
