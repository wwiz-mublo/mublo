<?php

use Mublo\Service\Extension\ExtensionPackageVerifier;

require_once __DIR__ . '/../bootstrap.php';

$fail = static function (string $message): never {
    fwrite(STDERR, "[SIGN] {$message}" . PHP_EOL);
    exit(1);
};

if ($argc < 4) {
    $fail('Usage: php tools/sign-extension-package.php <zip> <private-key.pem> <key-id>');
}

$zipPath = (string) $argv[1];
$keyPath = (string) $argv[2];
$keyId = (string) $argv[3];

if (!is_file($zipPath) || !is_file($keyPath)) {
    $fail('ZIP 또는 private key 파일을 찾을 수 없습니다.');
}
if (preg_match('/^[A-Za-z0-9._:@\/-]{1,128}$/', $keyId) !== 1) {
    $fail('key-id 형식이 올바르지 않습니다.');
}

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    $fail('ZIP 파일을 열 수 없습니다.');
}

try {
    $roots = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = (string) $zip->getNameIndex($i);
        if (str_starts_with($entry, '__MACOSX/') || !str_ends_with($entry, '/manifest.json')) {
            continue;
        }
        $roots[explode('/', $entry, 2)[0]] = true;
    }

    if (count($roots) !== 1) {
        $fail('ZIP에서 단일 확장 루트의 manifest.json을 찾을 수 없습니다.');
    }
    $rootDir = (string) array_key_first($roots);

    $verifier = new ExtensionPackageVerifier([]);
    $digest = $verifier->calculatePayloadDigest($zip, $rootDir);
    if ($digest === null) {
        $fail('payload checksum을 계산할 수 없습니다.');
    }

    $privateKeyPem = file_get_contents($keyPath);
    if ($privateKeyPem === false) {
        $fail('private key를 읽을 수 없습니다.');
    }

    $passphrase = getenv('MUBLO_SIGNING_KEY_PASSPHRASE');
    $privateKey = openssl_pkey_get_private(
        $privateKeyPem,
        $passphrase === false ? '' : $passphrase
    );
    if ($privateKey === false) {
        $fail('private key를 열 수 없습니다. passphrase를 확인하세요.');
    }

    if (!openssl_sign($digest, $binarySignature, $privateKey, OPENSSL_ALGO_SHA256)) {
        $fail('payload 서명에 실패했습니다.');
    }

    $signature = json_encode([
        'schema' => 1,
        'algorithm' => 'rsa-sha256',
        'key_id' => $keyId,
        'payload_sha256' => $digest,
        'signature' => base64_encode($binarySignature),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($signature === false
        || !$zip->addFromString($rootDir . '/' . ExtensionPackageVerifier::SIGNATURE_FILE, $signature)) {
        $fail('ZIP에 서명 파일을 기록할 수 없습니다.');
    }
} finally {
    $zip->close();
}

echo "[SIGN] OK key_id={$keyId} payload_sha256={$digest}" . PHP_EOL;
