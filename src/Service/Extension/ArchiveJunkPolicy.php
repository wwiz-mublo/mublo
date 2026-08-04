<?php
declare(strict_types=1);

namespace Mublo\Service\Extension;

/**
 * 압축 도구가 끼워 넣는 메타데이터 항목(macOS Finder 등) 판정 — 단일 소스.
 *
 * 서명 digest 계산(ExtensionPackageVerifier)과 실제 추출(ExtensionInstaller)이 반드시
 * '동일한' junk 집합을 무시해야 서명 커버리지 == 추출 커버리지가 성립한다. 두 곳에 로직을
 * 복제하면 한쪽만 바뀔 때 대칭이 깨져 서명 커버리지 우회가 재개되므로, 이 trait 하나로 공유한다.
 */
trait ArchiveJunkPolicy
{
    private function isJunkEntry(string $entry): bool
    {
        return str_starts_with($entry, '__MACOSX/')
            || basename($entry) === '.DS_Store'
            || basename($entry) === 'Thumbs.db';
    }
}
