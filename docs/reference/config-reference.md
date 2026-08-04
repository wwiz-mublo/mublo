# 설정 파일 레퍼런스

설정 파일은 설치 시 자동 생성됩니다. `config/` 디렉토리에 위치합니다.

## .env (앱 환경)

환경과 디버그 모드는 설정 파일이 아니라 프로젝트 루트의 `.env`로 정합니다.
`.env`가 없어도 아래 기본값으로 동작합니다.

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `APP_ENV` | string | 환경 (local/production) | 'production' |
| `APP_DEBUG` | bool | 디버그 모드 — `'true'` 일 때만 켜집니다 | false |

타임존은 관리자의 도메인 설정에서 관리합니다.
암호화 키와 검색 pepper는 `config/security.php`에 있습니다.

## config/database.php

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `driver` | string | DB 드라이버 | 'mysql' |
| `host` | string | DB 호스트 | 설치 시 입력 |
| `port` | int | DB 포트 | 3306 |
| `database` | string | 데이터베이스명 | 설치 시 입력 |
| `username` | string | DB 사용자 | 설치 시 입력 |
| `password` | string | DB 비밀번호 (암호화) | 설치 시 입력 |
| `charset` | string | 문자셋 | 'utf8mb4' |
| `collation` | string | 콜레이션 | 'utf8mb4_unicode_ci' |
| `_encrypted` | bool | 비밀번호 암호화 여부 | true |
| `_encrypt_key` | string | 복호화 키 | 자동 생성 |

## config/security.php

### password

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `algo` | int | 해시 알고리즘 | PASSWORD_DEFAULT |
| `cost` | int | bcrypt 비용 | 12 |

### csrf

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `token_ttl` | int | 유휴 허용 시간 (초). `0` 이면 만료 없이 세션 수명만 따릅니다 | 설치 시 선택 |

유효시간은 슬라이딩 만료입니다. 검증에 성공할 때마다 시간이 다시 시작되므로, 글을
쓰는 도중에 끊기지 않습니다. 마지막 활동 이후 이 시간이 지나야 만료됩니다.

토큰 자체는 세션에 담은 난수를 대조하는 방식이라 별도의 키를 쓰지 않습니다.
기존 설치의 `csrf.token_key` 는 CSRF 가 아니라 파일 다운로드 서명에 쓰이던 값입니다
(아래 `file` 참고).

`POST`, `PUT`, `DELETE` 요청은 기본적으로 CSRF 검증을 통과해야 합니다.
`Authorization: Bearer ...` 헤더만으로 CSRF 검증을 우회하지 않습니다. PG 콜백,
외부 Webhook, 서버 간 API처럼 CSRF 검증이 맞지 않는 엔드포인트는 해당
Plugin/Package의 `boot()` 단계에서 `CsrfMiddleware::addExcludePath()`로 명시적으로
예외 등록해야 합니다.

### file

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `download_signing_key` | string | 첨부 다운로드 링크 서명 키 (최소 32자) | 설치 시 생성 |

**값을 바꾸면 이미 발급된 다운로드 링크가 전부 무효가 됩니다.** 정기적으로 교체하는
종류의 키가 아닙니다.

이 값은 예전에 `csrf.token_key` 라는 이름이었습니다. CSRF 와는 무관한 값인데 이름
때문에 그렇게 읽혔습니다. 옛 위치도 계속 읽으므로 **기존 설정 파일은 그대로 두어도
됩니다.** 옮기려면 키 이름만 바꾸고 값은 그대로 두세요.

### session

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `lifetime` | int | 세션 수명 (분) | 120 |
| `cookie_httponly` | bool | HttpOnly 쿠키 | true |
| `cookie_samesite` | string | SameSite 정책 | 'Lax' |
| `cookie_secure` | bool | HTTPS 전용 쿠키 | false |

### login_rate_limiting

> 이 섹션은 선택 사항입니다. 생성된 `config/security.php`에 없으면 아래 기본값이 적용됩니다.

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `enabled` | bool | Rate Limiting 활성화 | true |
| `max_attempts_per_user` | int | (계정 + IP) 조합별 최대 실패 시도 | 5 |
| `max_attempts_per_ip` | int | IP별 최대 실패 시도 | 20 |
| `decay_seconds` | int | 실패 집계 창 — 가장 오래된 실패가 이 시간을 지나면 잠금 자동 해제 | 900 (15분) |
| `cleanup_probability` | int | 오래된 기록 GC 확률(%) | 5 |

### encryption

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `key` | string | 필드 암호화 키 | 설치 시 생성 |
| `cipher` | string | 암호화 방식 | 'aes-256-gcm' |

### search

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `pepper` | string | Blind Index pepper (HMAC-SHA256) | 설치 시 생성 |

### cache / session 드라이버

.env에서 설정:

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `cache_driver` | string | 캐시 드라이버 (file/redis) | 'file' |
| `session_driver` | string | 세션 드라이버 (file/redis) | 'file' |

### redis

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `redis.host` | string | Redis 호스트 | '127.0.0.1' |
| `redis.port` | int | Redis 포트 | 6379 |
| `redis.password` | string | Redis 비밀번호 | '' |

### trusted_proxies

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `trusted_proxies` | array | 신뢰 프록시 IP 목록 | .env `TRUSTED_PROXIES` (기본 빈 값) |

> 기본값은 빈 값으로 프록시 헤더를 신뢰하지 않습니다. `TRUSTED_PROXIES=*`는
> **모든 접속의 forwarded 헤더를 신뢰**하므로 원본 서버 직접 접근이 방화벽으로
> 차단된 환경에서만 사용하세요. 실서비스에서는 Cloudflare IP 대역이나 내부 프록시
> 주소로 좁혀 설정하는 것을 권장합니다.

`X-Forwarded-For`, `X-Real-IP`, `CF-Connecting-IP` 같은 프록시 헤더는 요청의
`REMOTE_ADDR`이 `trusted_proxies`에 포함될 때만 신뢰합니다. Cloudflare 뒤에서
운영하더라도 원본 서버 직접 접근이 가능하다면 Cloudflare IP 대역이나 내부 프록시
주소를 반드시 이 목록에 넣고, 직접 접근은 방화벽에서 차단하는 것을 권장합니다.

## config/mail.php

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `driver` | string | 메일 드라이버 (mail/smtp) | .env `MAIL_DRIVER` ('mail') |
| `from_address` | string | 발신자 이메일 | .env `MAIL_FROM_ADDRESS` |
| `from_name` | string | 발신자 이름 | .env `MAIL_FROM_NAME` |
| `smtp.host` | string | SMTP 호스트 | .env `MAIL_SMTP_HOST` |
| `smtp.port` | int | SMTP 포트 | .env `MAIL_SMTP_PORT` |
| `smtp.encryption` | string | 암호화 (tls/ssl) | .env `MAIL_SMTP_ENCRYPTION` |
| `smtp.username` | string | SMTP 사용자 | .env `MAIL_SMTP_USERNAME` |
| `smtp.password` | string | SMTP 비밀번호 | .env `MAIL_SMTP_PASSWORD` |

## config/upload.php

설치 시 생성되며, 운영자가 직접 열어 고치는 것을 전제로 합니다. 파일이나 키가 없으면
아래 기본값으로 동작합니다(부재가 '제한 없음'이 되지는 않습니다).

| 키 | 타입 | 설명 | 기본값 |
|----|------|------|--------|
| `editor_image.max_size_mb` | int/float | 로그인 회원이 에디터 본문에 넣는 이미지 한 장의 최대 크기(MB) | `20` |
| `editor_image.guest_max_size_mb` | int/float | 비로그인 방문자(비회원 글쓰기)의 최대 크기(MB) | `5` |

숫자가 아니거나 0 이하인 값은 기본값으로 되돌립니다. 오타 하나로 업로드가 통째로
막히거나, 반대로 상한이 사라지는 쪽이 더 나쁘기 때문입니다.

비회원 값을 따로 두는 이유는 `POST /api/v1/editor/upload` 가 로그인 없이 호출되고
저장된 파일이 공개 URL 로 열리기 때문입니다. 회원과 같은 한도를 주면 무단 업로드로
디스크가 차거나 파일 호스팅으로 악용될 여지가 그만큼 커집니다. 임시 파일 정리는
자동으로 돌지 않으므로(관리자 → 시스템에서 수동 실행), 값을 올릴 때는 정리 주기를
함께 정하시길 권합니다.

여기서 정한 값보다 php.ini 의 `upload_max_filesize` / `post_max_size` 가 작으면 그쪽이
먼저 막습니다. 큰 파일을 허용하려면 두 곳을 함께 올려야 합니다.

게시판 첨부파일 크기는 이 파일이 아니라 **게시판별 설정**(관리자 → 게시판 관리 →
설정 → 파일설정)에서 정합니다.

## .env 환경 변수

```env
# 앱
APP_DEBUG=false              # 디버그 모드 (운영: false)
APP_ENV=production           # 환경명

# 캐시/세션
CACHE_DRIVER=file            # file | redis
SESSION_DRIVER=file          # file | redis

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# 메일
MAIL_DRIVER=mail             # mail | smtp
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=Mublo
MAIL_SMTP_HOST=
MAIL_SMTP_PORT=587
MAIL_SMTP_ENCRYPTION=tls
MAIL_SMTP_USERNAME=
MAIL_SMTP_PASSWORD=

# 프록시
TRUSTED_PROXIES=             # 쉼표 구분 IP 목록
```

## 경로 상수 (bootstrap.php)

| 상수 | 값 |
|------|-----|
| `MUBLO_ROOT_PATH` | 프로젝트 루트 |
| `MUBLO_CONFIG_PATH` | config/ |
| `MUBLO_STORAGE_PATH` | storage/ |
| `MUBLO_PUBLIC_PATH` | public/ |
| `MUBLO_PUBLIC_STORAGE_PATH` | public/storage/ |
| `MUBLO_PLUGIN_PATH` | plugins/ |
| `MUBLO_PACKAGE_PATH` | packages/ |
| `MUBLO_ASSET_URI` | /assets |

---

[< 레퍼런스 목록](README.md)
