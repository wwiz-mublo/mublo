<?php

namespace Mublo\Service\Extension;

/**
 * 확장 호환성 검사
 *
 * 확장은 composer 가 아니라 커뮤니티 zip/git 으로 배포된다. 의존성 해결자가 없으므로
 * manifest.json 의 `requires` 를 코어가 직접 해석해야 한다. 검증하지 않으면 사용자는
 * 코어 v1 에 v2 용 확장을 넣고 백지 화면을 본다.
 *
 * requires 키
 *   - "core"            : 코어 버전 제약
 *   - "package:{Name}"  : 해당 패키지가 설치되어 있고 버전이 맞을 것
 *   - "plugin:{Name}"   : 해당 플러그인이 설치되어 있고 버전이 맞을 것
 *
 * 지원하는 제약 문법 (composer 의 부분집합)
 *   *            아무 버전
 *   1.2.3        정확히 일치
 *   >=1.2  >1.2  <=1.2  <1.2  =1.2  !=1.2
 *   ^1.2.3       >=1.2.3 <2.0.0   (0.x 는 >=0.2.3 <0.3.0)
 *   ~1.2.3       >=1.2.3 <1.3.0
 *   ~1.2         >=1.2   <2.0.0
 *   "A B"        AND (공백 또는 쉼표)
 *   "A || B"     OR
 */
class ExtensionCompatibility
{
    /**
     * manifest 의 requires 를 검사한다.
     *
     * @param array<string, mixed> $manifest 검사 대상 확장의 manifest
     * @param string $coreVersion 현재 코어 버전
     * @param array<string, string> $installedVersions "package:Board" => "1.0.0" 형태
     * @return string[] 사람이 읽는 사유 목록. 비어 있으면 호환.
     */
    public function check(array $manifest, string $coreVersion, array $installedVersions = []): array
    {
        $requires = $manifest['requires'] ?? [];
        if (!is_array($requires) || $requires === []) {
            return [];
        }

        $reasons = [];

        foreach ($requires as $target => $constraint) {
            if (!is_string($target) || !is_string($constraint)) {
                continue;
            }

            if ($target === 'core') {
                if (!$this->satisfies($coreVersion, $constraint)) {
                    $reasons[] = "코어 {$constraint} 이(가) 필요합니다. 현재 {$coreVersion}.";
                }
                continue;
            }

            if (!str_contains($target, ':')) {
                continue; // 알 수 없는 키는 무시한다
            }

            [$kind, $name] = explode(':', $target, 2);
            $label = $kind === 'package' ? '패키지' : '플러그인';

            $installed = $installedVersions[$target] ?? null;
            if ($installed === null) {
                $reasons[] = "{$label} '{$name}' 이(가) 설치되어 있지 않습니다.";
                continue;
            }

            if (!$this->satisfies($installed, $constraint)) {
                $reasons[] = "{$label} '{$name}' {$constraint} 이(가) 필요합니다. 현재 {$installed}.";
            }
        }

        return $reasons;
    }

    /**
     * 버전이 제약을 만족하는가.
     *
     * 해석할 수 없는 제약은 **만족으로 본다.** 우리 파서의 한계 때문에 멀쩡한 확장을
     * 막는 것이, 이상한 제약을 통과시키는 것보다 나쁘기 때문이다.
     */
    public function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        // AND 는 공백으로 나누므로, 연산자와 버전 사이의 공백("> = 1.0.0")을 먼저 없앤다.
        // 그러지 않으면 ">=" 와 "1.0.0" 이 별개의 제약으로 쪼개진다.
        $constraint = preg_replace('/(>=|<=|!=|>|<|=|\^|~)\s+/', '$1', $constraint) ?? $constraint;

        // OR: 하나라도 만족하면 통과
        foreach (explode('||', $constraint) as $orGroup) {
            if ($this->satisfiesAll($version, $orGroup)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 공백/쉼표로 이어진 제약을 모두 만족하는가 (AND).
     */
    private function satisfiesAll(string $version, string $group): bool
    {
        $parts = preg_split('/[\s,]+/', trim($group), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($parts === []) {
            return true;
        }

        foreach ($parts as $part) {
            if (!$this->satisfiesOne($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private function satisfiesOne(string $version, string $constraint): bool
    {
        // 캐럿: ^1.2.3 → >=1.2.3 <2.0.0 / ^0.2.3 → >=0.2.3 <0.3.0
        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1);
            $upper = $this->caretUpperBound($base);

            return $upper !== null
                && version_compare($version, $base, '>=')
                && version_compare($version, $upper, '<');
        }

        // 틸데: ~1.2.3 → >=1.2.3 <1.3.0 / ~1.2 → >=1.2 <2.0.0
        if (str_starts_with($constraint, '~')) {
            $base = substr($constraint, 1);
            $upper = $this->tildeUpperBound($base);

            return $upper !== null
                && version_compare($version, $base, '>=')
                && version_compare($version, $upper, '<');
        }

        if (preg_match('/^(>=|<=|!=|>|<|=)\s*(.+)$/', $constraint, $m) === 1) {
            $operator = $m[1] === '=' ? '==' : $m[1];

            return version_compare($version, trim($m[2]), $operator);
        }

        // 연산자 없음 → 정확히 일치
        if (preg_match('/^\d/', $constraint) === 1) {
            return version_compare($version, $constraint, '==');
        }

        // 해석 불가 — 막지 않는다
        return true;
    }

    private function caretUpperBound(string $base): ?string
    {
        $parts = $this->numericParts($base);
        if ($parts === null) {
            return null;
        }

        [$major, $minor] = $parts;

        // 0.x 는 minor 를 호환 경계로 본다 (composer 와 동일)
        return $major === 0 ? '0.' . ($minor + 1) . '.0' : ($major + 1) . '.0.0';
    }

    private function tildeUpperBound(string $base): ?string
    {
        $parts = $this->numericParts($base);
        if ($parts === null) {
            return null;
        }

        [$major, $minor, $hasPatch] = $parts;

        // ~1.2.3 → <1.3.0 (patch 를 명시했으면 minor 를 올린다)
        // ~1.2   → <2.0.0 (minor 까지만 적었으면 major 를 올린다)
        return $hasPatch ? $major . '.' . ($minor + 1) . '.0' : ($major + 1) . '.0.0';
    }

    /**
     * @return array{0: int, 1: int, 2: bool}|null [major, minor, patch 명시 여부]
     */
    private function numericParts(string $version): ?array
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim($version), $m) !== 1) {
            return null;
        }

        return [
            (int) $m[1],
            isset($m[2]) ? (int) $m[2] : 0,
            isset($m[3]),
        ];
    }
}
