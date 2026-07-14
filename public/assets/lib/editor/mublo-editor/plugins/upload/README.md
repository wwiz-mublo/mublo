# MubloEditor Image Upload Plugin

이미지 업로드를 위한 PHP 서버 사이드 핸들러입니다.

## 디렉토리 구조

```
plugins/
  upload/
    upload.php      # 업로드 핸들러
    README.md       # 이 파일
```

## 사용법

> 운영 환경에서는 프레임워크 라우트 `/api/v1/editor/upload` 사용을 권장합니다.
> `plugins/upload/upload.php`의 직접 실행 방식은 standalone 테스트/레거시 통합용이며,
> 기본적으로 비활성화되어 있습니다. `allow_standalone_handler`를 켜면 별도 인증/CSRF
> 보호가 없으므로 공개 운영 환경에서는 사용하지 마세요.

### 1. 서버 설정

PHP 서버 실행:
```bash
cd /path/to/Mublo-editor
php -S localhost:8000
```

### 2. MubloEditor 설정

```javascript
// 방법 1: uploadUrl 옵션 사용 (가장 간단)
const editor = MubloEditor.create('#editor', {
    // folder 파라미터로 저장 경로 지정 (기본값: temp)
    uploadUrl: 'plugins/upload/upload.php?folder=temp'
});

// 방법 2: 커스텀 핸들러 사용
editor.setImageUploadHandler(async (blobInfo, progress) => {
    const formData = new FormData();
    formData.append('file', blobInfo.blob(), blobInfo.filename());

    const response = await fetch('plugins/upload/upload.php', {
        method: 'POST',
        body: formData
    });

    const data = await response.json();
    return data.url;
});
```

standalone 핸들러를 직접 호출해야 하는 테스트 환경에서는 `config.local.php`에 아래 설정을 명시해야 합니다.

```php
return [
    'allow_standalone_handler' => true,
];
```

## 설정 옵션

`upload.php`에서 다음 설정을 변경할 수 있습니다:

```php
// 저장소 폴더 이름을 변경하려면 이 변수를 수정하세요.
$storageFolderName = 'data'; // 예: 'storage'
// 도메인을 포함한 전체 URL을 사용하려면 true로 변경하세요.
$includeDomain = false;      // true: http://domain.com/data/..., false: /data/...

$config = [
    // 경로는 folder 파라미터에 따라 동적으로 변경됩니다.
    'max_file_size' => 5 * 1024 * 1024,              // 최대 5MB
    'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
    'allowed_extensions' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
];
```

## 응답 형식

### 성공 시
```json
{
    "url": "plugins/upload/uploads/20250121_123456_abc123.jpg",
    "filename": "20250121_123456_abc123.jpg",
    "originalName": "my-image.jpg",
    "size": 102400,
    "type": "image/jpeg"
}
```

### 실패 시
```json
{
    "error": "에러 메시지"
}
```

## 보안 기능

- MIME 타입 검증 (실제 파일 내용 확인)
- 확장자 검증
- 파일 크기 제한
- 안전한 파일명 생성 (날짜 + 랜덤 해시)
- POST 요청만 허용

## 권한 설정

프로젝트 루트의 `data` 디렉토리에 쓰기 권한이 필요합니다:
```bash
chmod 755 data
```

## 고급 사용법: 임시 저장 및 이동 (Garbage Collection)

에디터에 이미지를 업로드했지만 글을 저장하지 않고 나가는 경우, 불필요한 이미지(Garbage)가 서버에 남게 됩니다. 이를 방지하기 위해 **"임시 폴더 저장 -> 글 저장 시 실제 폴더로 이동"** 전략을 권장합니다.

### 1단계: 에디터 설정 (임시 폴더 사용)

에디터 초기화 시 `folder=temp`를 지정하여 이미지를 임시 폴더에 업로드합니다.

```javascript
MubloEditor.create('#editor', {
    uploadUrl: 'plugins/upload/upload.php?folder=temp'
});
```

### 2단계: 글 저장 시 파일 이동 (서버 사이드 처리)

사용자가 글 작성 폼을 제출하면, 백엔드(PHP 등)에서 본문에 포함된 이미지를 추출하여 실제 폴더(예: `posts/123`)로 이동시킵니다. `upload.php`의 `action=move` 기능을 활용할 수 있습니다.

**요청 예시 (PHP cURL 또는 프론트엔드 fetch):**
```
POST /plugins/upload/upload.php
Content-Type: application/x-www-form-urlencoded

action=move
file=20250121_abc.jpg
from=temp
target=posts/123
```

### 3단계: 쓰레기 파일 정리 (Garbage Collection)

`uploads/temp` 폴더에 남아있는 오래된 파일(예: 24시간 경과)을 주기적으로 삭제합니다. 크론(Cron)을 사용하지 않고, 관리자 페이지 접속 시나 글 저장 로직의 끝부분에 아래 함수를 호출하여 정리할 수 있습니다.

**PHP 정리 함수 예시:**

```php
// upload.php를 include하여 사용
require_once 'plugins/upload/upload.php';

// temp 폴더의 24시간 지난 파일 삭제 (자동으로 경로 계산됨)
MubloEditorUploader::cleanupTemp(24);
```
```
