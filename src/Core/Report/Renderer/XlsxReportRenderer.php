<?php
declare(strict_types=1);

namespace Mublo\Core\Report\Renderer;

use Mublo\Core\Report\Contract\ReportRendererInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\KeyValueSection;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Document\Section\TextSection;
use Mublo\Core\Report\Exception\ReportRenderException;
use Mublo\Core\Report\Security\ReportCellValueSanitizer;

/**
 * XLSX 렌더러 — 행을 받는 대로 파일에 흘려 쓴다.
 *
 * 예전에는 PhpSpreadsheet 로 시트 전체를 메모리에 만든 뒤 저장했다. 셀 하나에 1KB 안팎을
 * 쓰므로 회원 수만 명 × 열 수십 개면 메모리 한도를 넘어 다운로드가 아무 응답 없이 실패했다.
 * 지금은 시트 XML 과 공유 문자열을 임시 파일에 한 줄씩 쓰고 마지막에 zip 으로 묶는다.
 * 메모리 사용량은 행 수와 상관없이 일정하다.
 *
 * 셀 값 판정은 PhpSpreadsheet DefaultValueBinder 와 맞춘다: 숫자형 문자열은 숫자로,
 * 앞자리 0(전화번호 등)·15자리 초과·수식처럼 보이는 값은 문자열로 둔다. 수식은 쓰지 않는다.
 */
class XlsxReportRenderer implements ReportRendererInterface
{
    private const SHEET_NAME = 'Report';
    private const FIFTEEN_NINES = 999999999999999;
    private const MAX_CELL_LENGTH = 32767;
    private const MIN_WIDTH = 8;
    private const MAX_WIDTH = 60;
    /** 열 폭은 앞쪽 이만큼의 행으로 정한다 — 모든 셀을 재면 대용량에서 느려진다 */
    private const WIDTH_SAMPLE_ROWS = 2000;

    /** cellXfs 인덱스: 0 기본, 1 굵게(제목), 2 머리글(굵게+가운데), 그 뒤 정렬×서식 조합 */
    private const XF_DEFAULT = 0;
    private const XF_BOLD = 1;
    private const XF_HEADER = 2;
    private const ALIGNS = ['left', 'center', 'right'];
    private const FORMATS = ['general' => 0, 'money' => 164, 'number' => 1, 'date' => 165];

    /** @var resource|null */
    private $sheetFp = null;
    /** @var resource|null */
    private $stringsFp = null;
    private int $stringCount = 0;
    private string $stringBuffer = '';
    private int $rowsWritten = 0;
    /** @var array<int, string> */
    private array $letters = [];
    /** @var array<int, int> 열 번호 => 표시 폭 */
    private array $widths = [];
    private int $maxCol = 1;
    private ?int $headerRow = null;
    private int $headerCols = 0;
    private int $headerLastRow = 0;

    public function supports(string $format): bool
    {
        $format = strtolower($format);
        return $format === 'xlsx' || $format === 'excel';
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    }

    public function extension(): string
    {
        return 'xlsx';
    }

    public function renderToFile(ReportDocument $document, string $filePath): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new ReportRenderException('XLSX 생성에는 PHP zip 확장이 필요합니다.');
        }

        $this->reset();
        $sheetDataPath = $this->tempPath('sheet');
        $stringsPath = $this->tempPath('strings');
        $sheetPath = null;
        $sstPath = null;

        try {
            $this->sheetFp = $this->open($sheetDataPath);
            $this->stringsFp = $this->open($stringsPath);

            $rowIndex = 1;
            $sectionCount = 0;
            foreach ($document->sections() as $section) {
                if ($sectionCount > 0) {
                    $rowIndex++;
                }

                if ($section instanceof KeyValueSection) {
                    $rowIndex = $this->renderKeyValueSection($section, $rowIndex);
                } elseif ($section instanceof TextSection) {
                    $rowIndex = $this->renderTextSection($section, $rowIndex);
                } elseif ($section instanceof TableSection) {
                    $rowIndex = $this->renderTableSection($section, $rowIndex);
                }

                $sectionCount++;
            }

            fclose($this->sheetFp);
            fclose($this->stringsFp);
            $this->sheetFp = $this->stringsFp = null;

            $sheetPath = $this->tempPath('sheet-full');
            $this->assembleSheet($sheetPath, $sheetDataPath);
            $sstPath = $this->tempPath('sst');
            $this->assembleSharedStrings($sstPath, $stringsPath);

            $this->writeZip($filePath, $sheetPath, $sstPath);
        } catch (ReportRenderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ReportRenderException('XLSX 생성에 실패했습니다: ' . $e->getMessage(), 0, $e);
        } finally {
            foreach ([$this->sheetFp, $this->stringsFp] as $fp) {
                if (is_resource($fp)) {
                    fclose($fp);
                }
            }
            $this->sheetFp = $this->stringsFp = null;
            foreach ([$sheetDataPath, $stringsPath, $sheetPath, $sstPath] as $path) {
                if (is_string($path) && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    // ------------------------------------------------------------------
    // 섹션
    // ------------------------------------------------------------------

    private function renderTableSection(TableSection $table, int $rowIndex): int
    {
        $columns = array_values($table->columns());
        if (empty($columns)) {
            throw new ReportRenderException('컬럼 정의가 비어 있습니다.');
        }

        $count = count($columns);
        $this->maxCol = max($this->maxCol, $count);

        $styles = [];
        foreach ($columns as $index => $column) {
            $styles[$index] = $this->columnXf((string) $column->type, (string) $column->align);
        }

        if ($table->withHeader()) {
            $cells = [];
            foreach ($columns as $index => $column) {
                $cells[$index + 1] = [(string) $column->label, self::XF_HEADER, 'general'];
            }
            $this->writeRow($rowIndex, $cells);
            $this->headerRow = $rowIndex;
            $this->headerCols = $count;
            $this->headerLastRow = $rowIndex;
            $rowIndex++;
        }

        foreach ($table->rows() as $row) {
            $cells = [];
            foreach ($columns as $index => $column) {
                $value = $row[$column->key] ?? null;
                if (is_callable($column->formatter)) {
                    $value = ($column->formatter)($value, $row);
                }
                $cells[$index + 1] = [$this->normalizeValue($value), $styles[$index], strtolower((string) $column->type)];
            }
            $this->writeRow($rowIndex, $cells);
            if ($table->withHeader()) {
                $this->headerLastRow = $rowIndex;
            }
            $rowIndex++;
        }

        return $rowIndex;
    }

    private function renderKeyValueSection(KeyValueSection $section, int $rowIndex): int
    {
        $this->maxCol = max($this->maxCol, 2);

        if ($section->title() !== '') {
            $this->writeRow($rowIndex, [1 => [$section->title(), self::XF_BOLD, 'general']]);
            $rowIndex++;
        }

        foreach ($section->items() as $item) {
            $this->writeRow($rowIndex, [
                1 => [$this->normalizeValue($item['label'] ?? ''), self::XF_BOLD, 'general'],
                2 => [$this->normalizeValue($item['value'] ?? ''), self::XF_DEFAULT, 'general'],
            ]);
            $rowIndex++;
        }

        return $rowIndex;
    }

    private function renderTextSection(TextSection $section, int $rowIndex): int
    {
        if ($section->title() !== '') {
            $this->writeRow($rowIndex, [1 => [$section->title(), self::XF_BOLD, 'general']]);
            $rowIndex++;
        }

        $this->writeRow($rowIndex, [1 => [$this->normalizeValue($section->content()), self::XF_DEFAULT, 'general']]);
        $rowIndex++;

        return $rowIndex;
    }

    // ------------------------------------------------------------------
    // 셀 쓰기
    // ------------------------------------------------------------------

    /**
     * @param array<int, array{0: mixed, 1: int, 2: string}> $cells 열 번호 => [값, xf, 서식]
     */
    private function writeRow(int $rowIndex, array $cells): void
    {
        $this->rowsWritten++;
        $xml = '<row r="' . $rowIndex . '">';
        foreach ($cells as $col => [$value, $xf, $format]) {
            $letter = $this->letters[$col] ??= $this->columnLetter($col);
            $xml .= $this->cellXml($letter . $rowIndex, $value, $xf, $col, $format);
        }
        $xml .= '</row>';
        fwrite($this->sheetFp, $xml);

        if ($this->stringBuffer !== '') {
            fwrite($this->stringsFp, $this->stringBuffer);
            $this->stringBuffer = '';
        }
    }

    private function cellXml(string $ref, mixed $value, int $xf, int $col, string $format): string
    {
        $style = $xf !== self::XF_DEFAULT ? ' s="' . $xf . '"' : '';

        if ($value === '' || $value === null) {
            return $xf !== self::XF_DEFAULT ? '<c r="' . $ref . '"' . $style . '/>' : '';
        }

        $measure = $this->rowsWritten <= self::WIDTH_SAMPLE_ROWS;

        $number = $this->numericValue($value);
        if ($number !== null) {
            if ($measure) {
                $this->trackWidth($col, $format === 'money' && is_numeric($number)
                    ? number_format((float) $number)
                    : $number);
            }
            return '<c r="' . $ref . '"' . $style . '><v>' . $number . '</v></c>';
        }

        $text = $this->cleanText((string) $value);
        if ($measure) {
            $this->trackWidth($col, $text);
        }

        $this->stringBuffer .= '<si><t xml:space="preserve">' . $this->escape($text) . '</t></si>';
        $index = $this->stringCount++;

        return '<c r="' . $ref . '"' . $style . ' t="s"><v>' . $index . '</v></c>';
    }

    /**
     * 숫자로 쓸 값이면 <v> 에 넣을 문자열을, 아니면 null 을 돌려준다.
     * PhpSpreadsheet DefaultValueBinder::dataTypeForValue() 와 같은 기준이다.
     */
    private function numericValue(mixed $value): ?string
    {
        if (is_int($value)) {
            return abs($value) > self::FIFTEEN_NINES ? null : (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $this->formatFloat($value) : null;
        }
        if (!is_string($value) || ReportCellValueSanitizer::isFormulaLike($value)) {
            return null;
        }
        // 부호로 시작하는 값은 위에서 문자열로 걸러지므로, 숫자나 소수점으로 시작해야 숫자 후보다
        if ($value === '' || (!ctype_digit($value[0]) && $value[0] !== '.')) {
            return null;
        }
        if (preg_match('/^[\+\-]?(\d+\.?\d*|\d*\.?\d+)([Ee][\-\+]?[0-2]?\d{1,3})?$/', $value) !== 1) {
            return null;
        }

        $unsigned = ltrim($value, '+-');
        if (strlen($unsigned) > 1 && $unsigned[0] === '0' && $unsigned[1] !== '.') {
            return null; // 전화번호·우편번호처럼 앞자리 0 은 문자열
        }
        if (preg_match('/[eE.]/', $value) !== 1 && abs((float) $value) > self::FIFTEEN_NINES) {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            return null;
        }

        if (preg_match('/^-?\d+$/', $value) === 1) {
            return $value;
        }

        return $this->formatFloat((float) $value);
    }

    private function formatFloat(float $value): string
    {
        $text = sprintf('%.15G', $value);
        return str_contains($text, 'E') ? $text : (str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text);
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /** XML 1.0 에 쓸 수 없는 제어 문자를 지우고 엑셀 셀 길이 한도로 자른다. */
    private function cleanText(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $text) === 1) {
            $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text) ?? '';
        }
        if (strlen($text) > self::MAX_CELL_LENGTH && mb_strlen($text, 'UTF-8') > self::MAX_CELL_LENGTH) {
            $text = mb_substr($text, 0, self::MAX_CELL_LENGTH, 'UTF-8');
        }
        return $text;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function trackWidth(int $col, string $text): void
    {
        $longest = 0;
        foreach (explode("\n", $text) as $line) {
            $longest = max($longest, mb_strwidth($line, 'UTF-8'));
        }
        if ($longest > ($this->widths[$col] ?? 0)) {
            $this->widths[$col] = $longest;
        }
    }

    private function columnXf(string $type, string $align): int
    {
        $alignIndex = array_search(strtolower($align), self::ALIGNS, true);
        if ($alignIndex === false) {
            $alignIndex = 0;
        }
        $formatIndex = array_search(strtolower($type), array_keys(self::FORMATS), true);
        if ($formatIndex === false) {
            $formatIndex = 0;
        }

        return 3 + $alignIndex * count(self::FORMATS) + $formatIndex;
    }

    private function columnLetter(int $col): string
    {
        $letter = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $col = intdiv($col - 1, 26);
        }
        return $letter;
    }

    // ------------------------------------------------------------------
    // 파일 조립
    // ------------------------------------------------------------------

    private function assembleSheet(string $target, string $sheetDataPath): void
    {
        $out = $this->open($target);
        try {
            $views = '<sheetViews><sheetView workbookViewId="0"';
            if ($this->headerRow !== null) {
                $top = $this->headerRow + 1;
                $views .= '><pane ySplit="' . $this->headerRow . '" topLeftCell="A' . $top
                    . '" activePane="bottomLeft" state="frozen"/>'
                    . '<selection pane="bottomLeft" activeCell="A' . $top . '" sqref="A' . $top . '"/></sheetView>';
            } else {
                $views .= '/>';
            }
            $views .= '</sheetViews>';

            $cols = '<cols>';
            for ($i = 1; $i <= $this->maxCol; $i++) {
                $width = min(self::MAX_WIDTH, max(self::MIN_WIDTH, ($this->widths[$i] ?? 0) + 2));
                $cols .= '<col min="' . $i . '" max="' . $i . '" width="' . $width . '" customWidth="1"/>';
            }
            $cols .= '</cols>';

            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . $views . '<sheetFormatPr defaultRowHeight="15"/>' . $cols . '<sheetData>');

            $in = fopen($sheetDataPath, 'rb');
            if ($in === false) {
                throw new ReportRenderException('임시 시트 파일을 읽을 수 없습니다.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);

            $tail = '</sheetData>';
            if ($this->autoFilterRange() !== null) {
                $tail .= '<autoFilter ref="' . $this->autoFilterRange() . '"/>';
            }
            $tail .= '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
            fwrite($out, $tail);
        } finally {
            fclose($out);
        }
    }

    private function assembleSharedStrings(string $target, string $stringsPath): void
    {
        $out = $this->open($target);
        try {
            fwrite($out, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
                . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' count="' . $this->stringCount . '" uniqueCount="' . $this->stringCount . '">');
            $in = fopen($stringsPath, 'rb');
            if ($in === false) {
                throw new ReportRenderException('임시 문자열 파일을 읽을 수 없습니다.');
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
            fwrite($out, '</sst>');
        } finally {
            fclose($out);
        }
    }

    private function autoFilterRange(): ?string
    {
        if ($this->headerRow === null || $this->headerCols < 1) {
            return null;
        }
        return 'A' . $this->headerRow . ':' . $this->columnLetter($this->headerCols) . $this->headerLastRow;
    }

    private function writeZip(string $filePath, string $sheetPath, string $sstPath): void
    {
        if (is_file($filePath)) {
            @unlink($filePath);
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new ReportRenderException('XLSX 파일을 만들 수 없습니다.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypesXml());
        $zip->addFromString('_rels/.rels', $this->rootRelsXml());
        $zip->addFromString('xl/workbook.xml', $this->workbookXml());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelsXml());
        $zip->addFromString('xl/styles.xml', $this->stylesXml());
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->addFile($sstPath, 'xl/sharedStrings.xml');

        if ($zip->close() !== true) {
            throw new ReportRenderException('XLSX 파일 저장에 실패했습니다.');
        }
    }

    private function contentTypesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private function rootRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbookXml(): string
    {
        $defined = '';
        $range = $this->autoFilterRange();
        if ($range !== null) {
            [$start, $end] = explode(':', $range);
            $defined = '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">'
                . "'" . self::SHEET_NAME . "'!" . $this->absoluteRef($start) . ':' . $this->absoluteRef($end)
                . '</definedName></definedNames>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView/></bookViews>'
            . '<sheets><sheet name="' . self::SHEET_NAME . '" sheetId="1" r:id="rId1"/></sheets>'
            . $defined
            . '</workbook>';
    }

    private function absoluteRef(string $ref): string
    {
        preg_match('/^([A-Z]+)(\d+)$/', $ref, $m);
        return '$' . $m[1] . '$' . $m[2];
    }

    private function workbookRelsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    private function stylesXml(): string
    {
        $xfs = [
            '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>',
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>',
            '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1">'
                . '<alignment horizontal="center"/></xf>',
        ];
        foreach (self::ALIGNS as $align) {
            foreach (self::FORMATS as $numFmtId) {
                $xfs[] = '<xf numFmtId="' . $numFmtId . '" fontId="0" fillId="0" borderId="0" xfId="0"'
                    . ($numFmtId !== 0 ? ' applyNumberFormat="1"' : '')
                    . ' applyAlignment="1"><alignment horizontal="' . $align . '"/></xf>';
            }
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n"
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2"><numFmt numFmtId="164" formatCode="#,##0"/><numFmt numFmtId="165" formatCode="yyyy-mm-dd"/></numFmts>'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/><family val="2"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    // ------------------------------------------------------------------
    // 보조
    // ------------------------------------------------------------------

    private function reset(): void
    {
        $this->sheetFp = $this->stringsFp = null;
        $this->stringCount = 0;
        $this->stringBuffer = '';
        $this->rowsWritten = 0;
        $this->widths = [];
        $this->maxCol = 1;
        $this->headerRow = null;
        $this->headerCols = 0;
        $this->headerLastRow = 0;
    }

    private function tempPath(string $suffix): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo-xlsx-' . $suffix . '-');
        if ($path === false) {
            throw new ReportRenderException('임시 파일을 만들 수 없습니다.');
        }
        return $path;
    }

    /** @return resource */
    private function open(string $path)
    {
        $fp = fopen($path, 'wb');
        if ($fp === false) {
            throw new ReportRenderException('임시 파일을 열 수 없습니다.');
        }
        return $fp;
    }
}
