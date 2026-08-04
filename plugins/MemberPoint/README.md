# MemberPoint

Mublo Framework 회원 포인트 플러그인입니다.

## Overview

- 회원 생애주기 이벤트(가입·레벨업) 기반 **자동 포인트 적립**
- 적립 규칙(가입 보너스·레벨업 보너스) 관리자 설정
- 회원 마이페이지 포인트 내역 제공

> 포인트 내역 조회/수동 조정/무결성 검증은 코어 "포인트 지갑"(`/admin/point`)으로 일원화되어
> 이 플러그인에서는 제공하지 않습니다. 포인트 적립/차감 자체는 코어 `BalanceManager`가 담당합니다.

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/member-point/install`
- 관리자 진입점: `/admin/member-point/member-settings`

## Main Routes

- Front
  - `GET /member-point/my` — 내 포인트 내역
- Admin
  - `GET|POST /admin/member-point/member-settings` — 적립 규칙 설정

## How it works

- `MemberEventSubscriber`가 회원 이벤트를 구독해 자동 적립한다.
  - `MemberRegisteredEvent` → 가입 보너스
  - `MemberUpdatedByAdminEvent` → 레벨업 보너스
- 적립 결과는 코어 `BalanceManager`(`balance_logs` 원장 + `point_balance` 잔액)에 기록된다.

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
