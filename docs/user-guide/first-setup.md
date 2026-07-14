# 첫 실행과 기본 설정

설치가 완료된 직후 해야 할 설정들입니다.

## 관리자 접속

```
https://your-domain.com/admin
```

설치 시 생성한 관리자 아이디/비밀번호로 로그인합니다.

## 사이트 기본 정보

관리자 > 환경설정에서 사이트 정보를 확인하고 수정합니다.

### 기본 설정
- **사이트 제목** — 브라우저 탭과 검색엔진에 표시
- **부제목** — 사이트 태그라인
- **관리자 이메일** — 시스템 알림 수신 주소

### 회사 정보
- 회사명, 대표자, 사업자등록번호 등

### SEO 설정
- 기본 메타 설명, 키워드
- OG 이미지 설정

### 테마 설정
- 프론트 스킨 선택
- 관리자 스킨 선택

## 메일 설정

`.env` 파일에서 메일 발송 방식을 설정합니다.

```env
# PHP mail() 함수 사용 (기본)
MAIL_DRIVER=mail

# 또는 SMTP 서버 사용
MAIL_DRIVER=smtp
MAIL_SMTP_HOST=smtp.gmail.com
MAIL_SMTP_PORT=587
MAIL_SMTP_ENCRYPTION=tls
MAIL_SMTP_USERNAME=your@gmail.com
MAIL_SMTP_PASSWORD=app-password
```

## 캐시/세션 설정

기본값은 파일 기반입니다. Redis를 사용하면 성능이 향상됩니다.

```env
# 파일 기반 (기본)
CACHE_DRIVER=file
SESSION_DRIVER=file

# Redis 기반 (운영 환경 권장)
CACHE_DRIVER=redis
SESSION_DRIVER=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
```

## 다음 단계

1. [관리자 화면 기본 사용법](admin-basics.md) — 메뉴 구조와 조작법 파악
2. [블록으로 페이지 만들기](block-page-builder.md) — 메인 페이지 구성
3. [게시판 운영](board-usage.md) — 게시판 생성과 설정

---

[< 이전: 설치 가이드](installation.md) | [다음: 관리자 화면 기본 사용법 >](admin-basics.md)
