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
  - `GET /sns-login/reauth/{provider}` — 본인 확인용 재인증(로그인 회원 전용, 콜백 주소는 로그인과 공용)
  - `GET|POST /sns-login/agree` — 가입 약관 동의(가입 약관을 운영하는 사이트에서만 거친다)
  - `POST /sns-login/unlink`
  - `GET|POST /sns-login/profile/complete`
- Admin
  - `GET|POST /admin/sns-login/settings`
  - `GET /admin/sns-login/accounts`

## Notes

- 관리자 라우트는 `AdminMiddleware`를 사용합니다.
- 계정 연결 해제는 `AuthMiddleware`를 사용합니다.
- 회원 탈퇴는 코어가 확정한 뒤에 각 제공자 연결을 폐기합니다. 폐기에 실패해도 탈퇴는 막지 않으며, 실패한 연결은 '폐기 실패'로 표시해 관리자가 재시도합니다.
- 가입 약관이 설정된 사이트는 SNS 가입도 동의 화면을 한 번 거칩니다. 약관이 없으면 종전처럼 바로 가입합니다. 동의 증빙(버전·내용 해시)은 코어가 만들며, 플러그인은 동의한 약관 번호만 넘깁니다.
- 마지막 로그인 수단은 연결 해제할 수 없습니다. 다른 연결도 로컬 비밀번호도 없으면 거절하고 비밀번호 설정을 안내합니다.
- SNS 로 가입한 회원은 로컬 비밀번호가 없습니다(빈 값). 비밀번호 설정처럼 본인 확인이 필요한 작업은 `GET /sns-login/reauth/{provider}` 로 제공자에 다시 인증해 코어의 본인 확인을 받습니다.
- 카카오 Client Secret은 카카오 로그인용 Secret을 활성화한 경우에만 입력합니다. 비즈니스 인증 Secret은 사용하지 않습니다.
- 카카오 Admin 키는 회원 탈퇴 및 서버 측 연결 해제에 필요합니다.
- 바로 가입의 회원 생성과 SNS 계정 연결은 하나의 DB 트랜잭션으로 처리됩니다.
- 자동 생성된 닉네임은 가입 후 회원정보에서 변경할 수 있습니다.
- 실제 운영에는 각 SNS 제공자 앱 설정이 필요합니다.
