<?php
declare(strict_types=1);

namespace Mublo\Service\Editor;

use DOMDocument;
use DOMElement;
use DOMXPath;
use ZipArchive;

/**
 * DocumentConverter
 *
 * 업로드한 오피스 문서를 에디터 본문 HTML 로 바꾼다. PHP 내장 기능(ZipArchive ·
 * DOM)만 쓰고 외부 라이브러리나 외부 바이너리에 기대지 않는다 — 공유호스팅에서도
 * 설치 없이 동작해야 하는 기능이기 때문이다.
 *
 * 지원 형식:
 * - DOCX: word/document.xml → 문단·제목·굵게/기울임·목록·표
 * - XLSX: 첫 시트 → 표
 *
 * PDF 는 지원하지 않는다. 텍스트 추출에 외부 바이너리(poppler/pdftotext)가
 * 필요해서, 있는 서버에서만 되는 기능이 된다. 컨트롤러가 확장자 단계에서
 * 거르므로 여기까지 오지 않는다.
 *
 * 산출물은 신뢰 대상이 아니다 — 문서 안의 텍스트는 전부 이스케이프하고,
 * 태그는 이 클래스가 고정 템플릿으로 조립한다. 저장 시에는 호출부가 코어
 * 정화기를 한 번 더 태운다.
 */
final class DocumentConverter
{
    /** 이 변환기가 다루는 확장자 */
    public const SUPPORTED_EXTENSIONS = ['docx', 'xlsx'];

    /** XLSX 표로 옮길 셀 상한 — 시트 하나가 통째로 본문이 되는 것을 막는다 */
    private const MAX_CELLS = 5000;

    /**
     * 압축 해제 후 크기 상한.
     *
     * zip 폭탄 방어다. 압축된 업로드 크기만 재면 수 MB 짜리 파일이 수 GB 로
     * 풀리며 메모리를 삼킬 수 있으므로, 읽기 **전에** 목록의 원본 크기를 본다.
     */
    private const MAX_ENTRY_BYTES = 20 * 1024 * 1024;

    private const DOCX_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * @param string $path         업로드된 파일의 실제 경로
     * @param string $extension    원본 파일명에서 얻은 확장자(소문자)
     * @return string 에디터에 삽입할 HTML
     *
     * @throws DocumentConversionException 변환할 수 없을 때
     */
    public function convert(string $path, string $extension): string
    {
        if (!is_file($path)) {
            throw new DocumentConversionException('INVALID_FILE', '업로드 파일을 찾을 수 없습니다.');
        }

        if (!class_exists(ZipArchive::class)) {
            throw new DocumentConversionException(
                'NO_ZIP',
                '서버에 zip 확장(ZipArchive)이 없어 문서를 열 수 없습니다.'
            );
        }

        return match ($extension) {
            'docx' => $this->convertDocx($path),
            'xlsx' => $this->convertXlsx($path),
            default => throw new DocumentConversionException(
                'UNSUPPORTED',
                '지원하지 않는 문서 형식입니다: ' . $extension
            ),
        };
    }

    // ---------------------------------------------------------
    // DOCX
    // ---------------------------------------------------------

    private function convertDocx(string $path): string
    {
        $xml = $this->readEntry($path, 'word/document.xml', 'DOCX');

        $xpath = $this->loadXml($xml);
        $xpath->registerNamespace('w', self::DOCX_NS);

        $body = $xpath->query('//w:body')->item(0);
        if (!$body instanceof DOMElement) {
            throw new DocumentConversionException('BAD_DOCX', '문서 본문을 찾을 수 없습니다.');
        }

        $out = [];
        $listItems = [];

        // 연속한 목록 문단은 하나의 <ul> 로 묶는다. 목록이 아닌 것을 만나는
        // 순간(또는 본문 끝)이 그 묶음의 경계다.
        $flushList = static function () use (&$listItems, &$out): void {
            if ($listItems !== []) {
                $out[] = '<ul>' . implode('', $listItems) . '</ul>';
                $listItems = [];
            }
        };

        foreach ($body->childNodes as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'p') {
                $inner = $this->docxRuns($xpath, $node);
                if (trim(strip_tags($inner)) === '') {
                    $flushList();
                    continue;
                }

                if ($xpath->query('w:pPr/w:numPr', $node)->length > 0) {
                    $listItems[] = '<li>' . $inner . '</li>';
                    continue;
                }

                $flushList();
                $out[] = $this->docxParagraph($xpath, $node, $inner);
                continue;
            }

            if ($node->localName === 'tbl') {
                $flushList();
                $table = $this->docxTable($xpath, $node);
                if ($table !== '') {
                    $out[] = $table;
                }
            }
        }

        $flushList();

        if ($out === []) {
            throw new DocumentConversionException('EMPTY', '문서에서 가져올 내용이 없습니다.');
        }

        return implode('', $out);
    }

    /** 제목 스타일이면 h1~h6, 아니면 문단 */
    private function docxParagraph(DOMXPath $xpath, DOMElement $paragraph, string $inner): string
    {
        $style = (string) $xpath->evaluate('string(w:pPr/w:pStyle/@w:val)', $paragraph);

        if (preg_match('/^Heading([1-6])$/i', $style, $m) === 1
            || preg_match('/^제목\s*([1-6])$/u', $style, $m) === 1
        ) {
            $level = min(6, max(1, (int) $m[1]));

            return "<h{$level}>{$inner}</h{$level}>";
        }

        return '<p>' . $inner . '</p>';
    }

    /** 문단 안의 run 을 굵게/기울임만 유지하며 HTML 로 */
    private function docxRuns(DOMXPath $xpath, DOMElement $paragraph): string
    {
        $html = '';

        foreach ($xpath->query('.//w:r', $paragraph) as $run) {
            if (!$run instanceof DOMElement) {
                continue;
            }

            $text = '';
            foreach ($xpath->query('w:t', $run) as $textNode) {
                $text .= $textNode->textContent;
            }

            if ($xpath->query('w:br', $run)->length > 0) {
                $html .= '<br>';
            }

            if ($text === '') {
                continue;
            }

            $text = $this->escape($text);

            // <w:b/> 는 값 없이 켜짐이고, <w:b w:val="0"/> 은 꺼짐이다.
            if ($xpath->evaluate('count(w:rPr/w:b[not(@w:val="0")][not(@w:val="false")])', $run) > 0) {
                $text = '<strong>' . $text . '</strong>';
            }
            if ($xpath->evaluate('count(w:rPr/w:i[not(@w:val="0")][not(@w:val="false")])', $run) > 0) {
                $text = '<em>' . $text . '</em>';
            }

            $html .= $text;
        }

        return $html;
    }

    private function docxTable(DOMXPath $xpath, DOMElement $table): string
    {
        $rows = [];

        foreach ($xpath->query('w:tr', $table) as $index => $row) {
            $cells = [];
            $tag = $index === 0 ? 'th' : 'td';

            foreach ($xpath->query('w:tc', $row) as $cell) {
                $lines = [];
                foreach ($xpath->query('w:p', $cell) as $paragraph) {
                    if ($paragraph instanceof DOMElement) {
                        $lines[] = $this->docxRuns($xpath, $paragraph);
                    }
                }
                $cells[] = $this->tableCell($tag, implode('<br>', $lines));
            }

            if ($cells !== []) {
                $rows[] = '<tr>' . implode('', $cells) . '</tr>';
            }
        }

        return $rows === [] ? '' : $this->tableWrap(implode('', $rows));
    }

    // ---------------------------------------------------------
    // XLSX (첫 시트)
    // ---------------------------------------------------------

    private function convertXlsx(string $path): string
    {
        $shared = $this->xlsxSharedStrings($path);
        $sheetXml = $this->readEntry($path, $this->firstSheetName($path), 'XLSX');

        $document = new DOMDocument();
        if (!@$document->loadXML($sheetXml, LIBXML_NONET)) {
            throw new DocumentConversionException('BAD_XML', '시트를 해석할 수 없습니다.');
        }

        $rows = [];
        $cellCount = 0;

        foreach ($document->getElementsByTagName('row') as $row) {
            $values = [];
            $column = 0;

            foreach ($row->getElementsByTagName('c') as $cell) {
                // 빈 셀은 XML 에 없으므로 참조(A1·C1…)로 자리를 맞춘다.
                $reference = $cell->getAttribute('r');
                if ($reference !== '' && preg_match('/^([A-Z]+)/', $reference, $m) === 1) {
                    $target = $this->columnToIndex($m[1]);
                    while ($column < $target) {
                        $values[] = '';
                        $column++;
                    }
                }

                $values[] = $this->xlsxCellValue($cell, $shared);
                $column++;

                if (++$cellCount > self::MAX_CELLS) {
                    break 2;
                }
            }

            $rows[] = $values;
        }

        // 뒤쪽 빈 행은 표에 담지 않는다
        while ($rows !== [] && !array_filter(end($rows), static fn (string $v): bool => trim($v) !== '')) {
            array_pop($rows);
        }

        if ($rows === []) {
            throw new DocumentConversionException('EMPTY', '시트에서 가져올 내용이 없습니다.');
        }

        $columns = max(array_map('count', $rows));
        $html = '';

        foreach ($rows as $index => $values) {
            $tag = $index === 0 ? 'th' : 'td';
            $cells = '';
            for ($i = 0; $i < $columns; $i++) {
                $cells .= $this->tableCell($tag, $this->escape($values[$i] ?? ''));
            }
            $html .= '<tr>' . $cells . '</tr>';
        }

        return $this->tableWrap($html);
    }

    /** @return list<string> */
    private function xlsxSharedStrings(string $path): array
    {
        $xml = $this->readEntry($path, 'xl/sharedStrings.xml', 'XLSX', optional: true);
        if ($xml === null) {
            return [];
        }

        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            return [];
        }

        $strings = [];
        foreach ($document->getElementsByTagName('si') as $item) {
            $strings[] = $item->textContent;
        }

        return $strings;
    }

    /** @param list<string> $shared */
    private function xlsxCellValue(DOMElement $cell, array $shared): string
    {
        $type = $cell->getAttribute('t');

        if ($type === 'inlineStr') {
            return $cell->textContent;
        }

        $value = $cell->getElementsByTagName('v')->item(0)?->textContent ?? '';

        return $type === 's' ? ($shared[(int) $value] ?? '') : $value;
    }

    private function firstSheetName(string $path): string
    {
        $zip = $this->open($path, 'XLSX');

        try {
            if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
                return 'xl/worksheets/sheet1.xml';
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name) === 1) {
                    return $name;
                }
            }
        } finally {
            $zip->close();
        }

        throw new DocumentConversionException('BAD_XLSX', '시트를 찾을 수 없습니다.');
    }

    private function columnToIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    // ---------------------------------------------------------
    // 공통
    // ---------------------------------------------------------

    /**
     * zip 항목 하나를 읽는다. 읽기 전에 압축 해제 후 크기를 확인한다.
     *
     * @param bool $optional 없어도 되는 항목이면 true (없으면 null 반환)
     */
    private function readEntry(string $path, string $entry, string $kind, bool $optional = false): ?string
    {
        $zip = $this->open($path, $kind);

        try {
            $stat = $zip->statName($entry);
            if ($stat === false) {
                if ($optional) {
                    return null;
                }

                throw new DocumentConversionException(
                    'BAD_' . $kind,
                    '문서 구조가 올바르지 않습니다. (' . $entry . ' 없음)'
                );
            }

            if ((int) ($stat['size'] ?? 0) > self::MAX_ENTRY_BYTES) {
                throw new DocumentConversionException('TOO_LARGE', '문서 내용이 너무 큽니다.');
            }

            $contents = $zip->getFromName($entry);
            if ($contents === false) {
                throw new DocumentConversionException('BAD_' . $kind, '문서를 읽을 수 없습니다.');
            }

            return $contents;
        } finally {
            $zip->close();
        }
    }

    private function open(string $path, string $kind): ZipArchive
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new DocumentConversionException('BAD_' . $kind, '문서를 열 수 없습니다. 파일이 손상되었을 수 있습니다.');
        }

        return $zip;
    }

    /**
     * XML 을 읽어 XPath 를 돌려준다.
     *
     * LIBXML_NONET 만 준다. 엔티티 치환(LIBXML_NOENT)은 켜지 않는다 — 켜면
     * 업로드한 문서가 서버 파일을 실어 나르는 통로(XXE)가 될 수 있다.
     */
    private function loadXml(string $xml): DOMXPath
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET)) {
            throw new DocumentConversionException('BAD_XML', '문서를 해석할 수 없습니다.');
        }

        return new DOMXPath($document);
    }

    private function tableWrap(string $rows): string
    {
        return '<table style="width:100%;border-collapse:collapse;"><tbody>' . $rows . '</tbody></table>';
    }

    private function tableCell(string $tag, string $content): string
    {
        return "<{$tag} style=\"border:1px solid #dee2e6;padding:8px;\">" . $content . "</{$tag}>";
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
