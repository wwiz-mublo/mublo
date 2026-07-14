# 설치 가이드

## 요구사항

### 서버 환경

| 항목 | 최소 버전 | 비고 |
|------|-----------|------|
| PHP | 8.2 이상 | 필수 (설치기에서 검증) |
| MySQL | 5.7.8 이상 | JSON 타입이 도입된 5.7.8이 호환 하한 |
| MariaDB | 10.3 이상 | 신규 운영은 10.11 LTS 이상 권장 |
| 웹 서버 | Apache 또는 Nginx | mod_rewrite (Apache) 필요 |

### 필수 PHP 확장

설치기가 아래 9개 확장의 존재를 검사합니다. 하나라도 없으면 설치를 진행할 수 없습니다.

| 확장 | 용도 |
|------|------|
| `pdo` | DB 추상화 |
| `pdo_mysql` | MySQL PDO 드라이버 |
| `mysqli` | MySQL 연결 (설치기 전용) |
| `mbstring` | 다국어 문자열 처리 |
| `openssl` | 암호화/복호화 |
| `json` | JSON 직렬화 |
| `curl` | 외부 HTTP 요청 |
| `fileinfo` | 파일 타입 감지 |
| `gd` | 이미지 업로드·썸네일 (코어 `ImageProcessor` 가 직접 사용) |

### 권장 PHP 확장

없어도 설치는 되지만, 관련 기능이 제한됩니다.

| 확장 | 용도 |
|------|------|
| `zip` | 엑셀 리포트(phpspreadsheet), ZIP 압축/해제 |
| `xml` | 엑셀·PDF 리포트, XML 파싱 |
| `intl` | 국제화 기능 |

### 권장 PHP 설정

`php.ini`에서 아래 값을 확인하세요. 기본값으로도 동작하지만, 운영 환경에서는 조정을 권장합니다.

| 항목 | 권장값 | 설명 |
|------|--------|------|
| `memory_limit` | 256M 이상 | 이미지 처리, 엑셀 내보내기 시 필요 |
| `upload_max_filesize` | 20M 이상 | 파일 업로드 최대 크기 |
| `post_max_size` | 25M 이상 | `upload_max_filesize`보다 크게 설정 |
| `max_execution_time` | 60 이상 | 마이그레이션, 대량 처리 시 필요 |

### 디렉토리 퍼미션

설치 전에 아래 3개 디렉토리를 PHP 실행 사용자가 읽고 쓸 수 있어야 합니다.
설치 화면은 단순한 퍼미션 숫자가 아니라 각 디렉토리에 임시 파일을 실제로 생성하고 삭제해 확인합니다.

| 호스팅 실행 방식 | 권장 설정 | 설명 |
|------------------|-----------|------|
| PHP가 내 계정 소유자로 실행 | `755` | 국내 공유호스팅의 일반적인 suPHP·PHP-FPM 계정 분리형 |
| PHP가 디렉토리 그룹으로 실행 | `775` | 디렉토리 그룹에 PHP 실행 계정이 포함되어 있어야 함 |
| PHP가 소유자·그룹과 다른 계정으로 실행 | `707` | 설치 후 `config`만 `755`로 복구 |

```bash
chmod 755 config storage public/storage
```

| 디렉토리 | 초기 권장값 | 용도 |
|----------|------------|------|
| `config/` | 755 | 설치 시 설정 파일 자동 생성 |
| `storage/` | 755 | 캐시, 로그, 세션, 임시 파일 |
| `public/storage/` | 755 | 업로드된 파일 (웹 접근 가능) |

설치 화면에는 현재 퍼미션, 디렉토리 소유자·그룹, PHP 실행 계정이 함께 표시됩니다.
`755`와 `775`가 모두 실패하고 PHP가 소유자·그룹에 속하지 않은 것으로 표시되면,
FTP 또는 파일 관리자에서 세 디렉토리를 `707`로 변경하고 설치를 진행하세요.

VPS처럼 소유권을 직접 관리할 수 있다면 그룹을 맞춘 뒤 그룹 쓰기 권한을 부여합니다.

```bash
# 예: 배포 사용자 deploy, PHP 실행 그룹 www-data
chown -R deploy:www-data config storage public/storage
chmod 2770 config storage
chmod 2775 public/storage
```

호스팅에서 소유권을 변경할 수 없지만 PHP가 현재 파일 그룹에 포함된 경우에는
`chmod 775 config storage public/storage`를 사용할 수 있습니다.

국내 공유호스팅에서 PHP가 공용 웹서버 계정으로 실행되는 경우에는
`config`, `storage`, `public/storage` **디렉토리 자체**에 `707`을 적용할 수 있습니다.
설치 완료 후 `config`만 `755`로 반드시 되돌리세요. 런타임 쓰기가 필요한
`storage`와 `public/storage`는 `707`을 유지합니다. `777`은 사용하지 마세요.

> **중요:** 퍼미션은 디렉토리에만 적용하세요. `chmod -R`은 설정 파일과 업로드 파일에도 불필요한 실행 권한을 부여하므로 사용하지 않습니다. 설치 가능 여부는 설치 화면의 실제 파일 생성·삭제 결과를 기준으로 판단하세요.

저장소에는 빈 `config/` 디렉토리와 빈 `public/storage/` 디렉토리가 포함되어 있습니다. 삭제하지 말고 그대로 두세요.

## 파일 업로드

### 1. 다운로드

배포 파일을 다운로드합니다.

### 2. 서버에 업로드

FTP 또는 SSH로 웹 서버 디렉토리에 업로드합니다. `public/` 디렉토리가 웹 루트(DocumentRoot)가 되어야 합니다.

```
/var/www/mysite/           ← 프로젝트 루트
├── config/
├── packages/
├── plugins/
├── public/                ← DocumentRoot (웹 루트)
│   ├── index.php
│   ├── install/
│   └── storage/
├── src/
├── storage/
└── ...
```

### 3. Composer 의존성 설치

```bash
cd /var/www/mysite
composer install --no-dev
```

## 웹 설치기 실행

브라우저에서 설치 페이지에 접속합니다.

```
https://your-domain.com/install
```

처음 접속하면 루트 `LICENSE`의 MIT 라이선스 전문과 요약이 표시됩니다.
라이선스에 동의해야 환경 체크를 비롯한 설치 단계에 접근할 수 있습니다.
Mublo에 포함된 제3자 라이브러리는 각 라이브러리의 라이선스 조건을 따릅니다.

동의 후 설치기는 기존 6단계로 진행됩니다.

### 1단계: 환경 체크

PHP 버전, 필수 확장, 디렉토리 퍼미션을 자동으로 검사합니다.

- 필수 항목에 실패하면 다음 단계로 진행할 수 없습니다
- 권장 항목은 경고만 표시하고 진행 가능합니다

### 2단계: 데이터베이스 설정

설치기는 연결 직후 서버 종류와 버전을 확인합니다. MySQL 5.7.8 미만 또는
MariaDB 10.3 미만이면 데이터베이스를 생성하거나 마이그레이션을 실행하기 전에 중단합니다.
신규 운영 환경은 MySQL 8.4 LTS 또는 MariaDB 10.11 LTS 이상을 권장합니다.

| 입력 항목 | 기본값 | 설명 |
|----------|--------|------|
| DB 호스트 | localhost | 데이터베이스 서버 주소 |
| DB 포트 | 3306 | MySQL 기본 포트 |
| 데이터베이스명 | (없음) | 없으면 자동 생성 |
| DB 사용자 | root | MySQL 사용자 |
| DB 비밀번호 | (없음) | |

**"연결 테스트"** 버튼으로 접속을 확인한 뒤 다음으로 진행합니다.

이 단계에서 일어나는 일:
- 데이터베이스가 없으면 UTF8MB4 인코딩으로 자동 생성
- `config/database.php` 생성 (비밀번호는 암호화 저장)
- Core + 기본 패키지 마이그레이션 실행
- `schema_migrations` 테이블로 마이그레이션 이력 관리

> **주의:** DB 사용자가 `CREATE DATABASE` 권한이 없으면 자동 생성에 실패합니다. 이런 환경에서는 DB를 미리 만들어 두고, 생성된 데이터베이스명을 입력한 뒤 설치를 진행하세요.

### 3단계: 도메인 설정

| 입력 항목 | 설명 |
|----------|------|
| 도메인명 | 현재 접속 도메인 자동 감지 |
| 사이트 제목 | 브라우저 탭, 검색엔진에 표시 |
| 사이트 부제 | 태그라인 (선택) |
| 관리자 이메일 | 시스템 알림용 (선택) |
| 타임존 | 기본 Asia/Seoul |

이 단계에서 기본 블록 페이지(홈페이지)와 기본 게시판(공지사항, 자유게시판)이 자동으로 생성됩니다.

### 4단계: 보안 설정

| 입력 항목 | 기본값 | 설명 |
|----------|--------|------|
| 암호화 키(Encrypt Key) | 자동 생성값 | 필드 암호화 키 (최소 32자). 미리 채워진 랜덤값이며 비우면 오류 |
| CSRF 토큰 키 | 자동 생성값 | 미리 채워진 랜덤값 (최소 32자). 편집 가능하나 비우면 오류 |
| 비밀번호 해시 비용 | 12 | bcrypt 비용 (높을수록 안전하지만 느림) |
| CSRF 토큰 유효시간 | 3600초 | 1시간 |

이 단계에서 생성되는 파일:
- `config/app.php` — 암호화 키, 앱 설정
- `config/security.php` — 비밀번호, CSRF, 세션, 캐시 설정
- `config/mail.php` — 메일 드라이버 설정
- `config/ai.php` — AI 공급자·모델 허용 목록과 호출 제한 기본값

### 5단계: 관리자 계정 생성

| 입력 항목 | 설명 |
|----------|------|
| 관리자 아이디 | 최초 관리자 로그인 ID |
| 비밀번호 | bcrypt로 해시 처리 |
| 비밀번호 확인 | 재입력 |

기본 회원 등급 6개가 자동으로 생성됩니다:

| 등급 | 레벨값 | 설명 |
|------|--------|------|
| SUPER | 255 | 최고 관리자 |
| STAFF | 230 | 운영 스태프 |
| PARTNER | 220 | 파트너 |
| SITE_MASTER | 215 | 사이트 운영자 (해당 도메인의 최고 권한) |
| SUPPLIER | 210 | 공급자 |
| BASIC | 1 | 일반 회원 |

### 6단계: 설치 완료

`storage/installed.lock` 파일이 생성되면 설치가 완료됩니다.

## 설치 완료 확인

### 관리자 접속

```
https://your-domain.com/admin
```

5단계에서 생성한 관리자 아이디/비밀번호로 로그인합니다.

### 프론트 페이지 확인

```
https://your-domain.com
```

기본 블록 페이지가 표시되면 정상입니다.

## 설치 후 보안 조치

설치 완료 후 반드시 아래 조치를 수행하세요.

### 1. 설치 디렉토리 삭제

```bash
rm -rf public/install/
```

설치 디렉토리가 남아 있으면 재설치 위험이 있습니다.

### 2. 설정 파일 권한 확인

설치기는 설정 파일과 설치 잠금 파일을 소유자만 읽고 쓸 수 있는 `600`으로 설정합니다.
서버에서 권한 변경이 지원되지 않았거나 배포 과정에서 권한이 달라졌다면 아래처럼 다시 설정하세요.

```bash
# PHP가 파일 소유자와 같은 사용자로 실행되는 공유호스팅
chmod 600 config/database.php config/app.php config/security.php config/mail.php
chmod 600 storage/installed.lock
```

배포 사용자와 PHP 실행 사용자가 같은 그룹을 사용하는 서버에서는 설정 파일의 그룹을
PHP 실행 그룹으로 맞춘 뒤 `640`을 사용합니다.

```bash
chgrp www-data config/database.php config/app.php config/security.php config/mail.php
chgrp www-data storage/installed.lock
chmod 640 config/database.php config/app.php config/security.php config/mail.php
chmod 640 storage/installed.lock
chmod 750 config
```

`config` 디렉토리 자체에는 파일을 읽기 위한 실행(`x`) 권한이 필요하므로 `444`로 변경하지 마세요.
`storage/`와 `public/storage/`는 설치 후에도 캐시·로그·업로드를 저장하므로 PHP의 쓰기 권한을 유지해야 합니다.

## 환경 변수 (.env)

프로젝트 루트에 `.env` 파일을 생성하면 환경별 설정을 관리할 수 있습니다.

```env
# 앱 환경
APP_DEBUG=false          # 운영 환경에서는 반드시 false
APP_ENV=production

# 캐시/세션 드라이버 (file 또는 redis)
CACHE_DRIVER=file
SESSION_DRIVER=file

# Redis (드라이버가 redis일 때)
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=

# 메일 (mail = PHP mail함수, smtp = SMTP 서버)
MAIL_DRIVER=mail
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME=Mublo

# SMTP 설정 (MAIL_DRIVER=smtp일 때)
MAIL_SMTP_HOST=
MAIL_SMTP_PORT=587
MAIL_SMTP_ENCRYPTION=tls
MAIL_SMTP_USERNAME=
MAIL_SMTP_PASSWORD=
```

## 문제 해결

### 설치 페이지가 안 보일 때

- `public/` 디렉토리가 웹 서버의 DocumentRoot로 설정되어 있는지 확인
- Apache의 경우 `mod_rewrite`가 활성화되어 있는지 확인
- `.htaccess` 파일이 `public/` 안에 있는지 확인

### DB 연결 오류

- MySQL 서버가 실행 중인지 확인
- 호스트, 포트, 사용자명, 비밀번호가 정확한지 확인
- 해당 사용자에게 데이터베이스 생성 권한이 있는지 확인 (자동 생성 시 필요)
- 생성 권한이 없으면 데이터베이스를 미리 만든 뒤 그 이름으로 설치

### 퍼미션 오류

```bash
# 디렉토리 퍼미션 확인
ls -la config/
ls -la storage/
ls -la public/storage/

# 공유호스팅처럼 PHP가 파일 소유자 권한으로 실행되는 경우
chmod 755 config storage public/storage
```

그래도 쓰기 불가라면 설치 화면에 표시된 소유자·그룹·PHP 실행 계정을 비교합니다.

- PHP가 그룹에 포함되어 있으면 디렉토리에 `775` 적용
- PHP가 소유자·그룹과 모두 다르면 세 디렉토리에 `707` 적용
- 설치 후 `config`만 `755`로 복구
- 운영 중 쓰기가 필요한 `storage`, `public/storage`는 `707` 유지

`777`과 재귀 권한 변경(`chmod -R`)은 사용하지 마세요.

### "이미 설치됨" 메시지

이미 설치된 상태에서 `/install`에 접근하면 403 오류가 표시됩니다. 재설치가 필요하면 아래 파일을 삭제하세요.

```bash
rm storage/installed.lock
rm config/database.php
```

---

[< 사용자 가이드 목록](README.md) | [다음: 첫 실행과 기본 설정 >](first-setup.md)
