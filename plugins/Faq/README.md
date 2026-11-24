# Faq

Mublo Framework FAQ 관리 플러그인입니다.

## Overview

- FAQ 카테고리/항목 관리
- 프론트 FAQ 목록 출력
- 간단한 FAQ API 제공

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/faq/install`
- 관리자 진입점: `/admin/faq`

## Main Routes

- Front
  - `GET /faq`
  - `GET /faq/{slug}`
  - `GET /faq/api/list`
- Admin
  - `GET /admin/faq`
  - `GET /admin/faq/items`
  - `POST /admin/faq/item`
  - `POST /admin/faq/category`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 정렬 및 스킨 저장은 `PUT` 기반 관리자 라우트를 사용합니다.

## Extension Events

패키지가 FAQ 항목에 자기 값(예: 연결 상품)을 붙일 수 있게 `Mublo\Plugin\Faq\Event\` 이벤트를 발행합니다. 플러그인은 붙는 값의 뜻을 모릅니다.

| 이벤트 | 발행 시점 | 확장이 하는 일 |
|---|---|---|
| `FaqItemFormEvent` | 관리자 `/admin/faq` 화면을 그릴 때 | `addSection($html)` — 항목 편집 창(`#itemModal`) 답변 칸 아래에 입력 칸 추가 (이스케이프는 넣는 쪽 책임) |
| `FaqItemsLoadedEvent` | 관리자 항목 목록(`scope=admin`) / 손님 `/faq`(`scope=front`) | admin: `setExtra()`(항목의 `ext` 로 내려감)·`addBadge()`(목록 표시), front: `addRelatedLink()`·`setRelatedTitle()`(답변 아래 관련 링크) |
| `FaqItemSavedEvent` | 항목 등록·수정 뒤 | `getInput()`/`hasInput()` 으로 편집 창 확장 칸이 실어 보낸 값을 꺼내 저장. 키가 없으면 건드리지 않는다 |
| `FaqItemDeletedEvent` | 항목 삭제 뒤, 카테고리 삭제로 함께 지워진 항목 포함 | `getFaqIds()` 의 항목에 붙인 값 정리 |

편집 창 확장 칸은 `#itemModal` 요소의 DOM 이벤트로 값을 주고받습니다 — `faq:item-open`(`detail.item`, 새 항목이면 null)으로 칸을 채우고, `faq:item-collect`(`detail.data`)에 값을 넣으면 저장 요청에 실려 `FaqItemSavedEvent::getInput()` 으로 돌아옵니다. 커스텀 프론트 스킨은 항목의 `related_links`(`[{label, url}]`)·`related_title` 을 선택적으로 출력합니다.

데이터 초기화(`FaqDataResetter`)로 지워지는 항목에는 `FaqItemDeletedEvent` 를 보내지 않습니다 — 확장은 자기 데이터 초기화를 따로 제공해야 합니다.
