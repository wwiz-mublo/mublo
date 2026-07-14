<?php

namespace Mublo\Infrastructure\Database;

/**
 * SQL 문자열을 여러 문(statement) 으로 안전하게 분할.
 *
 * 순진한 `explode(';', $sql)` 은 문자열 리터럴·주석 안의 `;` 도 분할 경계로
 * 오인해 SQL을 깨뜨린다. 이 클래스는 문자 단위로 파싱해 다음을 정확히 처리:
 *
 *   - 문자열 리터럴:  '...'  "..."  `...`
 *     • 백슬래시 이스케이프 (`\'`, `\"`)
 *     • SQL 표준 따옴표 중복 이스케이프 (`''`, `""`)
 *   - 라인 주석:      `-- ... \n`,  `# ... \n`
 *   - 블록 주석:      `/* ... *\/`  (MySQL 조건부 `/*! ... *\/` 도 동일하게 건너뜀)
 *   - 문 구분자:      위 컨텍스트 밖의 `;`
 *
 * 반환: 빈 문(주석만 있는 문장 포함) 은 걸러낸 trim 된 SQL 문자열 배열.
 */
class SqlStatementSplitter
{
    /**
     * @return string[]
     */
    public function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $c = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            // 라인 주석: -- 또는 #
            if (($c === '-' && $next === '-') || $c === '#') {
                // 줄 끝까지 건너뜀. 개행 문자는 남겨서 공백 역할을 하게 한다.
                while ($i < $length && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // 블록 주석: /* ... */
            if ($c === '/' && $next === '*') {
                $i += 2;
                while ($i < $length - 1) {
                    if ($sql[$i] === '*' && $sql[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                // */ 를 못 찾고 끝에 도달한 경우 남은 위치로 이동
                if ($i < $length - 1 === false) {
                    $i = $length;
                }
                continue;
            }

            // 문자열 리터럴: ' " `
            if ($c === "'" || $c === '"' || $c === '`') {
                $quote = $c;
                $current .= $c;
                $i++;
                while ($i < $length) {
                    $ch = $sql[$i];

                    // 백슬래시 이스케이프
                    if ($ch === '\\' && $i + 1 < $length) {
                        $current .= $ch . $sql[$i + 1];
                        $i += 2;
                        continue;
                    }

                    // 닫는 따옴표 — 단, 바로 뒤에 같은 따옴표 하나가 더 있으면
                    // SQL 표준 따옴표 이스케이프(`''`) 로 간주해 계속 읽음
                    if ($ch === $quote) {
                        if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                            $current .= $ch . $sql[$i + 1];
                            $i += 2;
                            continue;
                        }
                        $current .= $ch;
                        $i++;
                        break;
                    }

                    $current .= $ch;
                    $i++;
                }
                continue;
            }

            // 문 구분자
            if ($c === ';') {
                $trimmed = trim($current);
                if ($trimmed !== '') {
                    $statements[] = $trimmed;
                }
                $current = '';
                $i++;
                continue;
            }

            // 일반 문자
            $current .= $c;
            $i++;
        }

        // 마지막 문(세미콜론 없이 끝나는 경우)
        $trimmed = trim($current);
        if ($trimmed !== '') {
            $statements[] = $trimmed;
        }

        return $statements;
    }
}
