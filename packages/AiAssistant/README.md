# Mublo AI Assistant package

회사 범위 인증, Android 기기 등록, 고객 cursor 동기화, 암호화 transcript 분석 작업을 제공하는 전용 업무 패키지다.

## 현재 API

- `POST /mublo-ai/api/v1/auth/login`
- `POST /mublo-ai/api/v1/auth/refresh`
- `GET /mublo-ai/api/v1/auth/me`
- `POST /mublo-ai/api/v1/auth/logout`
- `POST /mublo-ai/api/v1/devices/enroll`
- `POST /mublo-ai/api/v1/devices/{device_id}/heartbeat`
- `GET /mublo-ai/api/v1/sync/customers/bootstrap`
- `GET /mublo-ai/api/v1/sync/customers/delta`
- `POST /mublo-ai/api/v1/sync/customers/push`
- `GET /mublo-ai/api/v1/crypto/worker-key`
- `POST /mublo-ai/api/v1/interactions`
- `PUT /mublo-ai/api/v1/customer-phones/{customer_phone_id}/permissions/{channel}/{purpose}`
- `POST /mublo-ai/api/v1/messaging/eligibility/check`
- `POST /mublo-ai/api/v1/messaging/suppressions/events`
- `POST /mublo-ai/api/v1/messaging/campaigns/{campaign_id}/recipient-snapshot`
- `PUT /mublo-ai/api/v1/messaging/campaigns/{campaign_id}/dispatch-policy`
- `POST /mublo-ai/api/v1/messaging/campaigns/{campaign_id}/dispatch-preflight`
- `GET /mublo-ai/api/v1/customers/{customer_id}/analysis`
- `POST /mublo-ai/api/v1/worker/jobs/lease`
- `POST /mublo-ai/api/v1/worker/jobs/{job_id}/complete`
- `POST /mublo-ai/api/v1/worker/jobs/{job_id}/fail`

HTTP 계약은 `docs/openapi/openapi.json`, 공통 JSON Schema는 `docs/openapi/schemas`가 기준이다.

## 최초 회사 생성

Framework 설치와 `config/database.php` 생성 후 다음 스크립트를 실행한다. 비밀번호는 명령행 인자가 아니라 표준입력으로 받는다.

```text
php tools/provision-ai-assistant.php <slug> <회사명> <로그인ID> [닉네임] [framework_domain_id]
```

Android 로그인 화면에 회사 입력란이 없으므로 현재 앱 배포에서는 `framework_domain_id`를 지정해 요청 host의 Framework domain과 회사를 연결한다.
기존 설치 DB에서는 해당 domain의 활성 package 목록에 `AiAssistant`를 켜야 route가 등록된다. 신규 설치는 manifest의 `default: true`로 활성화된다.

## 보안 규칙

- 모든 repository 쿼리는 인증 principal의 `company_id`를 명시적으로 받는다.
- access token은 15분, refresh token은 30일이며 refresh는 매번 rotation한다.
- 서버 DB에는 token 원문을 저장하지 않고 SHA-256 hash만 저장한다.
- 고객 payload 전체는 Framework `SensitiveValueCodecInterface`로 암호화해 저장한다.
- 전화번호 검색 token은 서버 codec이 생성한다.
- customer/customer_phone sync는 조회·발송 검증용 암호화 directory projection도 갱신한다.
- interaction은 같은 회사·고객에 등록된 활성 `customer_phone_id` 없이는 저장하지 않는다.
- 고객 관리상태와 광고성 메시지 permission·철회·suppression은 별도 gate다.
- suppression은 단조 증가 version의 append-only 이벤트 원장과 현재 projection을 함께 갱신한다.
- 캠페인 snapshot은 등록번호를 전부 선검증하고 본문 대신 content/policy version과 판정 사유만 immutable하게 저장한다.
- dispatch preflight는 서버 현재시각으로 quiet hours·일일 빈도·stale interaction·현재 permission을 재검증하고 `READY/BLOCKED` reservation만 저장한다.
- 대화·통화 transcript는 고객 sync API가 아니라 Worker 공개키로 암호화된 interaction API로만 받는다.
- API 서버는 transcript 복호화 private key를 갖지 않으며 암호문만 저장한다.
- 통화녹음 원본 업로드 endpoint는 제공하지 않는다.
- 쓰기 재시도는 `Idempotency-Key`와 request hash가 모두 같을 때만 replay한다.

## Worker 환경 설정

- `MUBLO_AI_WORKER_KEY_ID`: 활성 Worker 공개키 ID
- `MUBLO_AI_WORKER_PUBLIC_KEY_FILE`: Worker 공개키 PEM 경로
- `MUBLO_AI_WORKER_TOKEN`: Worker API 인증 token
- `MUBLO_AI_WORKER_SIGNING_SECRET`: 분석 결과 HMAC 서명 secret

private key는 Python Worker 장비에만 둔다. 키가 설정되지 않으면 transcript 업로드 준비 endpoint는 `503`을 반환한다.

## 검사

```text
vendor/bin/phpunit packages/AiAssistant/tests
vendor/bin/phpstan analyse packages/AiAssistant --no-progress --memory-limit=1G
composer check-di
composer check-extension-api
composer check-strict-types
```
