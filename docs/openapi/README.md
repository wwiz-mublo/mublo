# Mublo AI Assistant API contract

이 디렉터리는 Android·PHP API·Python Worker가 함께 사용하는 계약의 단일 기준이다.

## 버전 규칙

- 호환 가능한 필드 추가는 같은 `schema_version`에서 허용한다.
- 기존 필드의 의미·타입·필수 여부 변경은 새 스키마 버전으로 만든다.
- 알 수 없는 `schema_version`은 추측해 처리하지 않고 `SCHEMA_VERSION_UNSUPPORTED`로 거절한다.
- 모든 시간은 UTC RFC 3339 형식, 모든 공개 객체 ID는 UUID 문자열을 사용한다.
- 동기화 삭제는 물리 삭제 대신 tombstone을 전달한다.
- 재시도 가능한 쓰기 요청에는 `Idempotency-Key`가 필수다.

## 보안 경계

- Framework `domain_id`와 업무 `company_id`는 서로 다른 식별자다.
- 인증된 principal의 `company_id`는 URL이나 body 값보다 항상 우선한다.
- 고객명·전화번호 payload는 TLS 구간에서만 평문이며 서버 저장 시 Framework 민감값 codec으로 즉시 암호화한다. 로그 및 오류 응답에는 포함하지 않는다.
- 고객 sync changelog에서 암호화 고객·전화번호 directory projection을 유지하며 대량발송·등록번호 검증의 기준으로 사용한다.
- `interaction-upload-v2`는 등록된 활성 `customer_phone_id`를 필수로 요구하고 같은 회사·고객 소속을 저장 전에 확인한다.
- `interaction-upload-v3`는 채널 원본 식별자와 평문 digest를 함께 받아 중복 업로드와 내용 충돌을 구분하고, immutable manifest에 사용할 서버 sequence를 발급한다.
- AI 분석은 동의 영수증 등록 후 선택 고객 전체의 채널별 건수·집합 digest를 서버가 재계산한다. 하나라도 다르면 배치 전체를 생성하지 않는다.
- `manifest_sha256`은 `manifest_sha256` 필드를 추가하기 전 manifest 객체를 canonical JSON으로 직렬화한 SHA-256이다.
- 고객 등록은 광고 수신동의가 아니다. 채널·목적별 permission, 철회와 suppression을 별도 gate로 관리한다.
- suppression 변경은 versioned append-only 이벤트로 기록하며 해제가 기존 광고 동의를 되살리지 않는다.
- 캠페인 수신자 snapshot은 등록번호 검증을 먼저 끝낸 뒤 정책 version과 사유만 immutable하게 저장한다.
- dispatch preflight는 snapshot을 현재 정책으로 재검증하고 quiet hours·일일 빈도·stale interaction·승인 본문 version을 통과한 수신자만 `READY` reservation으로 고정한다.
- 기본 interaction 업로드는 `crypto-envelope-v1` 암호문만 받는다.
- 통화 오디오 원본은 이 API 범위에서 받지 않는다. transcript는 Android에서 Worker 공개키로 봉투 암호화한다.

## 파일

- `openapi.json`: HTTP API 계약
- `schemas/sync-record-v1.schema.json`: cursor 동기화 레코드
- `schemas/crypto-envelope-v1.schema.json`: 암호화 원문 envelope
- `schemas/interaction-upload-v1.schema.json`: 이전 transcript interaction 계약
- `schemas/interaction-upload-v2.schema.json`: 등록 고객 전화번호가 결합된 transcript interaction 계약
- `schemas/interaction-upload-v3.schema.json`: 다중 채널 원본 식별자·digest·서버 sequence 기반 interaction 계약
- `schemas/analysis-consent-v1.schema.json`: 선택 고객 집합에 대한 AI 분석 동의 영수증
- `schemas/analysis-batch-create-v1.schema.json`: 고객별 수집 결과와 채널 집합 검증 요청
- `schemas/analysis-batch-status-v1.schema.json`: 온보딩과 SaaS가 표시할 실제 배치·실행 상태
- `schemas/analysis-manifest-v1.schema.json`: Worker가 받는 고객 단위 immutable 입력 manifest
- `schemas/analysis-result-v2.schema.json`: 고객 단위 분석 결과 및 부족 데이터 판정
- `schemas/worker-job-v2.schema.json`: Worker lease 응답
- `schemas/worker-heartbeat-v1.schema.json`: Worker health·capability 보고
- `schemas/worker-complete-v2.schema.json`: manifest digest에 결합된 서명 완료 요청
- `schemas/customer-directory-v1.schema.json`: SaaS 최소 고객 디렉터리
- `schemas/contact-permission-v1.schema.json`: 채널·목적별 동의·철회 근거
- `schemas/messaging-eligibility-v1.schema.json`: 발송 가능 여부 사전검증 요청
- `schemas/suppression-event-v1.schema.json`: 수신거부·해제 감사 이벤트
- `schemas/campaign-recipient-snapshot-v1.schema.json`: 대량발송 전 immutable 수신자 판정 snapshot
- `schemas/campaign-dispatch-policy-v1.schema.json`: 캠페인 승인·시간대·빈도 정책
- `schemas/campaign-dispatch-preflight-v1.schema.json`: 공급자 호출 전 최종 예약 검증
- `schemas/analysis-result-v1.schema.json`: Worker 분석 결과
- `schemas/schedule-message-v1.schema.json`: 이전 일정·승인·발송 상태
- `schemas/schedule-message-v2.schema.json`: 전화번호·메시지 유형·permission version이 결합된 발송 상태
