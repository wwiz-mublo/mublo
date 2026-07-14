<?php
namespace Mublo\Service\Extension;

use Mublo\Core\Result\Result;

/**
 * 확장 ZIP의 canonical payload digest와 publisher 서명을 검증한다.
 */
final class ExtensionPackageVerifier
{
    use ArchiveJunkPolicy;

    public const SIGNATURE_FILE = 'extension-signature.json';
    private const MAX_SIGNATURE_SIZE = 65536;
    /** digest 스트리밍 실바이트 상한 — ExtensionInstaller::MAX_UNCOMPRESSED_SIZE 와 동일(200MB) */
    private const MAX_DIGEST_STREAM_SIZE = 200 * 1024 * 1024;

    private bool $requireSignature;

    /** @var array<string, array{name: string, public_key: string, sources: string[]}> */
    private array $publishers;

    /**
     * @param array{require_signature?: bool, publishers?: array<string, mixed>}|null $config
     */
    public function __construct(?array $config = null)
    {
        $config ??= $this->loadConfig();
        $this->requireSignature = (bool) ($config['require_signature'] ?? false);
        $this->publishers = $this->normalizePublishers($config['publishers'] ?? []);
    }

    public function verify(\ZipArchive $zip, string $rootDir, string $source): Result
    {
        if (preg_match('/^[A-Za-z0-9._:@\/-]{1,128}$/', $source) !== 1) {
            return Result::failure('확장 설치 출처 식별자가 올바르지 않습니다.');
        }

        $digest = $this->calculatePayloadDigest($zip, $rootDir);
        if ($digest === null) {
            return Result::failure('확장 payload checksum을 계산할 수 없습니다.');
        }

        $signaturePath = $rootDir . '/' . self::SIGNATURE_FILE;
        $raw = $zip->getFromName($signaturePath, 0, self::MAX_SIGNATURE_SIZE + 1);
        if ($raw === false) {
            if ($this->requireSignature) {
                return Result::failure('이 서버는 서명된 확장만 설치할 수 있습니다.');
            }

            return Result::success('', [
                'verification' => [
                    'status' => 'unsigned',
                    'source' => $source,
                    'payload_sha256' => $digest,
                    'key_id' => null,
                    'publisher' => null,
                ],
            ]);
        }

        if (strlen($raw) > self::MAX_SIGNATURE_SIZE) {
            return Result::failure(self::SIGNATURE_FILE . ' 파일이 너무 큽니다.');
        }

        $signature = json_decode($raw, true);
        if (!is_array($signature) || json_last_error() !== JSON_ERROR_NONE) {
            return Result::failure(self::SIGNATURE_FILE . ' 파일을 해석할 수 없습니다.');
        }

        $schema = $signature['schema'] ?? null;
        $algorithm = $signature['algorithm'] ?? null;
        $keyId = $signature['key_id'] ?? null;
        $claimedDigest = $signature['payload_sha256'] ?? null;
        $encodedSignature = $signature['signature'] ?? null;

        if ($schema !== 1 || $algorithm !== 'rsa-sha256') {
            return Result::failure('지원하지 않는 확장 서명 형식입니다.');
        }
        if (!is_string($keyId)
            || preg_match('/^[A-Za-z0-9._:@\/-]{1,128}$/', $keyId) !== 1
            || !isset($this->publishers[$keyId])) {
            return Result::failure('신뢰할 수 없는 확장 publisher key입니다.');
        }
        if (!is_string($claimedDigest)
            || preg_match('/^[a-f0-9]{64}$/', $claimedDigest) !== 1
            || !hash_equals($claimedDigest, $digest)) {
            return Result::failure('확장 payload checksum이 서명 정보와 일치하지 않습니다.');
        }
        if (!is_string($encodedSignature)) {
            return Result::failure('확장 서명값이 없습니다.');
        }

        $publisher = $this->publishers[$keyId];
        if ($publisher['sources'] !== [] && !in_array($source, $publisher['sources'], true)) {
            return Result::failure("publisher '{$publisher['name']}'에 허용되지 않은 설치 출처입니다.");
        }

        $binarySignature = base64_decode($encodedSignature, true);
        $publicKey = openssl_pkey_get_public($publisher['public_key']);
        if ($binarySignature === false || $publicKey === false) {
            return Result::failure('확장 publisher 공개키 또는 서명값이 올바르지 않습니다.');
        }

        $verified = openssl_verify($digest, $binarySignature, $publicKey, OPENSSL_ALGO_SHA256);
        if ($verified !== 1) {
            return Result::failure('확장 publisher 서명 검증에 실패했습니다.');
        }

        return Result::success('', [
            'verification' => [
                'status' => 'verified',
                'source' => $source,
                'payload_sha256' => $digest,
                'key_id' => $keyId,
                'publisher' => $publisher['name'],
            ],
        ]);
    }

    public function calculatePayloadDigest(\ZipArchive $zip, string $rootDir): ?string
    {
        /** @var array<int, array{name: string, relative: string, index: int}> $entries */
        $entries = [];
        $prefix = $rootDir . '/';
        $signaturePath = $prefix . self::SIGNATURE_FILE;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (!str_starts_with($name, $prefix)
                || str_ends_with($name, '/')
                || $name === $signaturePath
                || $this->isJunkEntry($name)) {
                continue;
            }

            $entries[] = [
                'name' => $name,
                'relative' => substr($name, strlen($prefix)),
                'index' => $i,
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            $pathOrder = strcmp($left['relative'], $right['relative']);
            return $pathOrder !== 0 ? $pathOrder : $left['index'] <=> $right['index'];
        });

        // 실바이트 상한을 '누적'으로 둔다(extractEntries 의 $written 과 동일 축). central directory
        // 신고 크기를 속인 zip 은 validateStructure(신고 합산)를 통과할 수 있고, verify 는 extract
        // 보다 먼저 실행되므로 상한 없는 스트리밍은 실제 해제 바이트를 무제한 소비하는 CPU/시간 DoS 가
        // 된다. 파일당 리셋이 아니라 '누적' 상한이라야 다수-중형 파일 누적까지 extract 와 대칭으로 막힌다.
        $payloadContext = hash_init('sha256');
        $streamed = 0;
        foreach ($entries as $entry) {
            $stream = $zip->getStreamIndex($entry['index']);
            if ($stream === false) {
                return null;
            }

            $fileContext = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false) {
                    fclose($stream);
                    return null;
                }
                $streamed += strlen($chunk);
                if ($streamed > self::MAX_DIGEST_STREAM_SIZE) {
                    fclose($stream);
                    return null;
                }
                hash_update($fileContext, $chunk);
            }
            fclose($stream);

            hash_update(
                $payloadContext,
                $entry['relative'] . "\0" . hash_final($fileContext) . "\n"
            );
        }

        return hash_final($payloadContext);
    }

    /** @return array{require_signature?: bool, publishers?: array<string, mixed>} */
    private function loadConfig(): array
    {
        $path = defined('MUBLO_CONFIG_PATH')
            ? MUBLO_CONFIG_PATH . '/extension-publishers.php'
            : dirname(__DIR__, 3) . '/config/extension-publishers.php';

        if (!is_file($path)) {
            return [];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }

    /**
     * @param array<string, mixed> $publishers
     * @return array<string, array{name: string, public_key: string, sources: string[]}>
     */
    private function normalizePublishers(array $publishers): array
    {
        $normalized = [];
        foreach ($publishers as $keyId => $publisher) {
            if (!is_string($keyId) || !is_array($publisher)) {
                continue;
            }

            $name = $publisher['name'] ?? $keyId;
            $publicKey = $publisher['public_key'] ?? null;
            $sources = $publisher['sources'] ?? [];
            if (!is_string($name) || !is_string($publicKey) || !is_array($sources)) {
                continue;
            }

            $normalized[$keyId] = [
                'name' => $name,
                'public_key' => $publicKey,
                'sources' => array_values(array_filter($sources, 'is_string')),
            ];
        }

        return $normalized;
    }

}
