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
