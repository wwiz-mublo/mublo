# SnsLogin

Mublo Framework SNS 로그인 플러그인입니다.

## Overview

- 네이버/카카오/Google OAuth 로그인
- SNS 계정 연결 및 해제
- 바로 가입 시 중복되지 않는 임의의 한글 닉네임 자동 생성
- 회원 탈퇴 및 연동 해제 시 네이버·카카오·Google 제공자 토큰/연결 폐기
- 관리자 제공자 설정 및 연결 계정 관리

## Dependency

- Mublo Core `>=1.0.0`

## Install

- 설치 라우트: `POST /admin/sns-login/install`
- 관리자 진입점: `/admin/sns-login/settings`

## Main Routes

- Front
  - `GET /sns-login/auth/{provider}`
  - `GET /sns-login/callback/{provider}`
  - `POST /sns-login/unlink`
  - `GET|POST /sns-login/profile/complete`
- Admin
  - `GET|POST /admin/sns-login/settings`
  - `GET /admin/sns-login/accounts`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 계정 연결 해제는 `AuthMiddleware`를 사용합니다.
- 회원 탈퇴 전 각 제공자의 연결 해제가 성공해야 탈퇴가 진행되며, 완료 후 저장된 SNS 토큰과 연결 정보가 삭제됩니다.
- 카카오 Client Secret은 카카오 로그인용 Secret을 활성화한 경우에만 입력합니다. 비즈니스 인증 Secret은 사용하지 않습니다.
- 카카오 Admin 키는 회원 탈퇴 및 서버 측 연결 해제에 필요합니다.
- 바로 가입의 회원 생성과 SNS 계정 연결은 하나의 DB 트랜잭션으로 처리됩니다.
- 자동 생성된 닉네임은 가입 후 회원정보에서 변경할 수 있습니다.
- 실제 운영에는 각 SNS 제공자 앱 설정이 필요합니다.
