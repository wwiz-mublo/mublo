# 확장 ZIP 서명과 출처 검증

확장 ZIP은 서버에서 PHP 코드를 실행합니다. 수동 업로드는 최고 관리자만 가능하지만,
마켓·원격 설치·자동 업데이트처럼 제3자 배포 채널을 연결할 때는 publisher 서명을 필수로 해야 합니다.

## 현재 정책

- 모든 ZIP에서 파일 목록 기반 canonical payload SHA-256을 계산합니다.
- `extension-signature.json`이 없으면 `unsigned`로 표시합니다.
- 서명 파일이 있는데 publisher key가 없거나 checksum·서명이 틀리면 설치를 거부합니다.
- `config/extension-publishers.php`의 `require_signature`가 `true`이면 unsigned ZIP도 거부합니다.
- publisher별 `sources`가 비어 있지 않으면 해당 출처에서만 그 키를 인정합니다.
- 관리자 직접 업로드의 source는 `manual-upload`입니다. 마켓 구현은 고정된 별도 source를 넘겨야 합니다.

서명을 선택적으로 허용하는 현재 기본값은 기존 최고 관리자 수동 설치와의 호환을 위한 것입니다.
공식 마켓이나 자동 업데이트를 운영하는 배포에서는 반드시 `require_signature => true`로 변경합니다.

## Trust store

`config/extension-publishers.example.php`를 `config/extension-publishers.php`로 복사한 뒤
배포 환경별 정책과 공개키를 설정합니다. 설정 파일이 없으면 기존 수동 설치 호환을 위해
unsigned 허용·publisher 0개로 동작합니다.

```php
// config/extension-publishers.php
return [
    'require_signature' => true,
    'publishers' => [
        'vendor:key-2026' => [
            'name' => 'Vendor Name',
            'public_key' => "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
            'sources' => ['official-marketplace'],
        ],
    ],
];
```

공개키만 서버에 배치합니다. private key는 저장소나 운영 서버에 올리지 않고 publisher의 서명 환경에서 관리합니다.

## ZIP 서명

확장 디렉토리를 포함한 ZIP을 만든 다음 실행합니다.

```bash
php tools/sign-extension-package.php extension.zip private-key.pem vendor:key-2026
```

암호화된 private key는 환경 변수로 passphrase를 전달합니다.

```bash
MUBLO_SIGNING_KEY_PASSPHRASE='...' php tools/sign-extension-package.php extension.zip private-key.pem vendor:key-2026
```

도구는 ZIP 루트에 다음 파일을 추가합니다.

```json
{
  "schema": 1,
  "algorithm": "rsa-sha256",
  "key_id": "vendor:key-2026",
  "payload_sha256": "...",
  "signature": "base64..."
}
```

## Canonical payload

ZIP 컨테이너 자체는 압축 시간과 메타데이터에 따라 바이트가 달라질 수 있으므로 ZIP 파일 전체를
서명하지 않습니다. 대신 다음 규칙으로 payload를 고정합니다.

1. 확장 루트 아래의 일반 파일을 수집합니다.
2. `extension-signature.json`, `.DS_Store`, `Thumbs.db`, `__MACOSX` 항목은 제외합니다.
3. 루트 기준 상대 경로를 바이트순으로 정렬합니다.
4. 각 파일의 SHA-256을 계산해 `relative-path + NUL + file-hash + LF`로 연결합니다.
5. 연결한 값의 SHA-256 hex 문자열을 RSA-SHA256으로 서명합니다.

파일 추가·삭제·변경과 manifest 변경은 모두 payload checksum을 바꿉니다.

## 마켓 연동 조건

- 마켓 source 문자열은 사용자 입력이 아니라 코드에 고정합니다.
- 응답으로 받은 ZIP의 publisher key가 해당 source에 허용됐는지 확인합니다.
- 검증 성공 후에만 압축을 해제합니다.
- 설치 로그의 source, publisher, key ID, payload SHA-256을 감사 정보로 보존합니다.
- 키 폐기와 교체 시 기존 패키지 검증·업데이트 정책을 함께 운영합니다.
