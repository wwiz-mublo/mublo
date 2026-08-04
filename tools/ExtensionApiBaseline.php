<?php

namespace Mublo\Tools;

use RuntimeException;

/** 확장별 occurrence baseline의 결정론적 생성과 구조 검증을 담당한다. */
final class ExtensionApiBaseline
{
    /** @param array{file: string, symbol: string} $occurrence */
    public static function key(array $occurrence): string
    {
        return $occurrence['file'] . "\0" . $occurrence['symbol'];
    }

    /**
     * @param array<int, array{file: string, symbol: string}> $occurrences
     * @return array<int, array{file: string, symbol: string}>
     */
    public static function normalize(array $occurrences): array
    {
        $unique = [];
        foreach ($occurrences as $occurrence) {
            $item = [
                'file' => str_replace('\\', '/', $occurrence['file']),
                'symbol' => $occurrence['symbol'],
            ];
            $unique[self::key($item)] = $item;
        }

        $items = array_values($unique);
        usort($items, static fn(array $a, array $b): int => [$a['file'], $a['symbol']] <=> [$b['file'], $b['symbol']]);

        return $items;
    }

    /**
     * 현재 occurrence를 확장 소유 파일로 나눠 기록한다.
     *
     * @param array<int, array{file: string, symbol: string}> $occurrences
     * @return array{files: int, occurrences: int}
     */
    public static function rewrite(string $directory, array $occurrences): array
    {
        $grouped = [];
        foreach (self::normalize($occurrences) as $occurrence) {
            $classification = ExtensionApiPath::classify($occurrence['file']);
            if ($classification === null) {
                throw new RuntimeException("확장 소유자를 판정할 수 없는 경로입니다: {$occurrence['file']}");
            }

            $grouped[$classification['owner']][] = $occurrence;
        }
        ksort($grouped);

        if ($grouped !== [] && !is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("baseline 디렉터리를 만들 수 없습니다: {$directory}");
        }

        $desiredFiles = [];
        foreach ($grouped as $owner => $items) {
            $filename = $owner . '.json';
            $desiredFiles[$filename] = true;
            $path = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $filename;
            $payload = [
                '_comment' => '확장 운영 코드의 기존 비안정 코어 import. 새 occurrence 추가 금지.',
                '_generated_by' => 'php tools/check-extension-api.php --baseline',
                'owner' => $owner,
                'occurrences' => self::normalize($items),
            ];
            $json = json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            ) . "\n";

            if (file_put_contents($path, $json) === false) {
                throw new RuntimeException("baseline 파일을 기록할 수 없습니다: {$path}");
            }
        }

        if (is_dir($directory)) {
            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
                if (!isset($desiredFiles[basename($path)]) && !unlink($path)) {
                    throw new RuntimeException("0건 baseline 파일을 삭제할 수 없습니다: {$path}");
                }
            }
        }

        return ['files' => count($grouped), 'occurrences' => count(self::normalize($occurrences))];
    }

    /**
     * @return array{
     *     occurrences: array<string, array{file: string, symbol: string}>,
     *     errors: array<int, string>,
     *     files: int
     * }
     */
    public static function read(string $directory, string $basePath): array
    {
        $result = ['occurrences' => [], 'errors' => [], 'files' => 0];
        if (!is_dir($directory)) {
            return $result;
        }

        $paths = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [];
        sort($paths);

        foreach ($paths as $path) {
            $result['files']++;
            $ownerFromFile = pathinfo($path, PATHINFO_FILENAME);
            $ownerDirectory = ExtensionApiPath::ownerDirectory($ownerFromFile, $basePath);
            if ($ownerDirectory === null || !is_dir($ownerDirectory)) {
                $result['errors'][] = "고아 baseline 파일: " . basename($path);
            }

            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $result['errors'][] = basename($path) . ": 올바르지 않은 JSON ({$e->getMessage()})";
                continue;
            }

            if (!is_array($decoded)) {
                $result['errors'][] = basename($path) . ': JSON 객체가 아닙니다.';
                continue;
            }

            $declaredOwner = $decoded['owner'] ?? null;
            if ($declaredOwner !== $ownerFromFile) {
                $result['errors'][] = basename($path) . ": owner가 파일명과 다릅니다 ({$declaredOwner}).";
            }

            $rawOccurrences = $decoded['occurrences'] ?? null;
            if (!is_array($rawOccurrences)) {
                $result['errors'][] = basename($path) . ': occurrences 배열이 없습니다.';
                continue;
            }
            if ($rawOccurrences === []) {
                $result['errors'][] = basename($path) . ': occurrence가 0건인 빈 baseline 파일입니다.';
                continue;
            }

            $validItems = [];
            foreach ($rawOccurrences as $index => $raw) {
                if (!is_array($raw)
                    || !is_string($raw['file'] ?? null)
                    || ($raw['file'] ?? '') === ''
                    || !is_string($raw['symbol'] ?? null)
                    || ($raw['symbol'] ?? '') === '') {
                    $result['errors'][] = basename($path) . ': occurrences[' . $index . '] 형식이 잘못되었습니다.';
                    continue;
                }

                $item = [
                    'file' => str_replace('\\', '/', $raw['file']),
                    'symbol' => $raw['symbol'],
                ];
                $expectedOwner = ExtensionApiPath::ownerOf($item['file']);
                if ($expectedOwner !== $ownerFromFile) {
                    $result['errors'][] = basename($path) . ": 잘못된 소유 occurrence ({$item['file']}).";
                }

                $key = self::key($item);
                if (isset($result['occurrences'][$key])) {
                    $result['errors'][] = basename($path) . ": 중복 occurrence ({$item['file']} -> {$item['symbol']}).";
                    continue;
                }

                $result['occurrences'][$key] = $item;
                $validItems[] = $item;
            }

            if ($validItems !== self::normalize($validItems)) {
                $result['errors'][] = basename($path) . ': occurrences가 경로·심볼 기준으로 정렬되지 않았습니다.';
            }
        }

        ksort($result['occurrences']);
        return $result;
    }
}
