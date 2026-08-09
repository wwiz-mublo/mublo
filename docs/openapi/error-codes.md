# Mublo AI Assistant stable error codes

`error.code`는 Android, SaaS Web과 Worker가 행동을 결정하는 안정 계약이다. 사용자 문구나 PHP exception message로 분기하지 않는다.

| HTTP | Code | Retry | 의미·행동 |
|---:|---|---:|---|
| 400 | `MALFORMED_REQUEST` | 아니요 | JSON 또는 기본 request 형식 오류 |
| 401 | `TOKEN_EXPIRED` | 갱신 | access token 갱신 |
| 401 | `WORKER_UNAUTHORIZED` | 아니요 | Worker token/certificate 확인 |
| 403 | `TENANT_SCOPE_DENIED` | 아니요 | 인증 회사 밖 접근 |
| 403 | `CONSENT_REQUIRED` | 사용자 | 동의 receipt 재확인 |
| 404 | `CUSTOMER_NOT_FOUND` | sync 후 | 활성 고객이 없음 |
| 404 | `BATCH_NOT_FOUND` | 아니요 | 회사 범위 batch 없음 |
| 404 | `RUN_NOT_FOUND` | 아니요 | 회사 범위 run 없음 |
| 409 | `IDEMPOTENCY_PAYLOAD_MISMATCH` | 아니요 | 같은 key에 다른 body |
| 409 | `INTERACTION_CONTENT_CONFLICT` | 사용자 | 같은 원본 ID의 content digest 불일치 |
| 422 | `INTERACTION_CONTENT_MISMATCH` | 아니요 | content digest와 암호화 envelope의 plaintext digest 불일치 |
| 409 | `INPUT_SET_MISMATCH` | reconciliation | 채널별 count 또는 set digest 불일치 |
| 409 | `CUSTOMER_SET_MISMATCH` | 사용자 | 동의·배치의 선택 고객 집합 digest 불일치 |
| 409 | `ANALYSIS_CONSENT_REQUIRED` | 사용자 | 현재 고객 집합과 일치하는 동의 영수증이 없음 |
| 409 | `LEASE_EXPIRED` | 새 lease | 만료 lease의 renew/complete/fail |
| 409 | `RUN_STATE_CONFLICT` | 조회 후 | 현재 상태에서 허용되지 않는 전이 |
| 422 | `SCHEMA_INVALID` | 아니요 | versioned schema 불일치 |
| 422 | `CHANNEL_NOT_READY` | 사용자 | 권한거부·수집실패 채널 포함 |
| 422 | `ANALYSIS_RESULT_INVALID` | 아니요 | result schema/evidence/count 오류 |
| 422 | `SIGNATURE_INVALID` | 아니요 | Worker result 서명 검증 실패 |
| 422 | `WORKER_SIGNATURE_INVALID` | 아니요 | Worker v2 Ed25519 서명 또는 key ID 불일치 |
| 503 | `WORKER_SIGNATURE_UNAVAILABLE` | 운영조치 | API 서버에 Ed25519 검증 기능이 없음 |
| 429 | `RATE_LIMITED` | 예 | `Retry-After` 준수 |
| 503 | `WORKER_UNAVAILABLE` | 예 | 활성 Worker/key 없음 |
| 503 | `SERVER_TEMPORARY` | 예 | 제한적 backoff 재시도 |

모든 오류 응답은 `retryable`, `trace_id`와 민감정보가 없는 `details`를 제공한다. 타회사 객체는 존재 여부를 감추기 위해 정책에 따라 403 대신 404를 반환할 수 있다.
