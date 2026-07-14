# Survey

Mublo Framework 설문 플러그인입니다.

## Overview

- 설문 생성/수정/상태 관리
- 사용자 참여 및 제출
- 결과 집계 화면 제공

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/survey/install`
- 관리자 진입점: `/admin/survey/surveys`

## Main Routes

- Front
  - `GET /survey/{id}`
  - `POST /survey/{id}/submit`
- Admin
  - `GET /admin/survey/surveys`
  - `GET /admin/survey/surveys/create`
  - `POST /admin/survey/surveys/store`
  - `GET /admin/survey/surveys/{id}/result`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 블록 시스템 연동이 가능한 설문 플러그인입니다.
