<?php
declare(strict_types=1);

namespace Tests\Unit\Service\Editor;

use Mublo\Service\Editor\DocumentConversionException;
use Mublo\Service\Editor\DocumentConverter;
use PHPUnit\Framework\TestCase;
use ZipArchive;

/**
 * DocumentConverter
 *
 * DOCX/XLSX 는 zip 이므로 고정 파일을 두지 않고 매번 최소 문서를 만들어 넣는다.
 */
class DocumentConverterTest extends TestCase
{
    private const DOCX_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private DocumentConverter $converter;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        $this->converter = new DocumentConverter();
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $file) {
            @unlink($file);
        }
        $this->tempFiles = [];
    }

    public function testConvertsDocxParagraphsHeadingsAndRuns(): void
    {
        $body = $this->paragraph('큰 제목', style: 'Heading1')
            . $this->paragraph('본문 문단')
            . $this->paragraph('강조', bold: true)
            . $this->paragraph('기울임', italic: true);

        $html = $this->converter->convert($this->docx($body), 'docx');

        $this->assertStringContainsString('<h1>큰 제목</h1>', $html);
        $this->assertStringContainsString('<p>본문 문단</p>', $html);
        $this->assertStringContainsString('<strong>강조</strong>', $html);
        $this->assertStringContainsString('<em>기울임</em>', $html);
    }

    /** 한글 제목 스타일(제목 1)도 heading 으로 읽는다 */
    public function testConvertsKoreanHeadingStyle(): void
    {
        $html = $this->converter->convert($this->docx($this->paragraph('한글 제목', style: '제목 2')), 'docx');

        $this->assertStringContainsString('<h2>한글 제목</h2>', $html);
    }

    /** 연달아 오는 목록 문단은 하나의 ul 로 묶이고, 사이의 문단이 묶음을 끊는다 */
    public function testGroupsConsecutiveListParagraphs(): void
    {
        $body = $this->paragraph('첫째', list: true)
            . $this->paragraph('둘째', list: true)
            . $this->paragraph('사이 문단')
            . $this->paragraph('셋째', list: true);

        $html = $this->converter->convert($this->docx($body), 'docx');

        $this->assertStringContainsString('<ul><li>첫째</li><li>둘째</li></ul>', $html);
        $this->assertStringContainsString('<ul><li>셋째</li></ul>', $html);
    }

    public function testConvertsDocxTableWithHeaderRow(): void
    {
        $body = '<w:tbl>'
            . '<w:tr><w:tc>' . $this->paragraph('머리') . '</w:tc><w:tc>' . $this->paragraph('말') . '</w:tc></w:tr>'
            . '<w:tr><w:tc>' . $this->paragraph('값1') . '</w:tc><w:tc>' . $this->paragraph('값2') . '</w:tc></w:tr>'
            . '</w:tbl>';

        $html = $this->converter->convert($this->docx($body), 'docx');

        $this->assertStringContainsString('<th style="border:1px solid #dee2e6;padding:8px;">머리</th>', $html);
        $this->assertStringContainsString('<td style="border:1px solid #dee2e6;padding:8px;">값1</td>', $html);
    }

    /** 문서 안 텍스트는 태그가 아니라 글자다 — 그대로 두면 본문에 마크업이 실린다 */
    public function testEscapesTextFromDocument(): void
    {
        $html = $this->converter->convert(
            $this->docx($this->paragraph('<script>alert(1)</script>')),
            'docx'
        );

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testConvertsXlsxFirstSheetToTable(): void
    {
        $path = $this->xlsx(
            shared: ['이름', '값'],
            rows: [
                ['A1' => ['s', '0'], 'B1' => ['s', '1']],
                ['A2' => ['', '10'], 'B2' => ['', '20']],
            ]
        );

        $html = $this->converter->convert($path, 'xlsx');

        $this->assertStringContainsString('<th style="border:1px solid #dee2e6;padding:8px;">이름</th>', $html);
        $this->assertStringContainsString('<td style="border:1px solid #dee2e6;padding:8px;">10</td>', $html);
    }

    /** 빈 셀은 XML 에 없다 — 참조(C1)를 보고 자리를 채워야 열이 밀리지 않는다 */
    public function testKeepsColumnPositionsForSkippedCells(): void
    {
        $path = $this->xlsx(shared: [], rows: [['A1' => ['', '1'], 'C1' => ['', '3']]]);

        $html = $this->converter->convert($path, 'xlsx');

        $this->assertSame(3, substr_count($html, '<th'), '건너뛴 B 열이 빈 칸으로 남아야 한다');
        $this->assertStringContainsString('padding:8px;">1</th>', $html);
        $this->assertStringContainsString('padding:8px;">3</th>', $html);
    }

    public function testRejectsUnsupportedExtension(): void
    {
        $this->expectException(DocumentConversionException::class);

        $this->converter->convert($this->docx($this->paragraph('x')), 'pdf');
    }

    public function testReportsMissingFile(): void
    {
        try {
            $this->converter->convert('/no/such/file.docx', 'docx');
            $this->fail('예외가 발생해야 한다');
        } catch (DocumentConversionException $e) {
            $this->assertSame('INVALID_FILE', $e->errorCode());
        }
    }

    public function testReportsBrokenArchive(): void
    {
        $path = $this->tempPath('broken.docx');
        file_put_contents($path, 'not a zip');

        try {
            $this->converter->convert($path, 'docx');
            $this->fail('예외가 발생해야 한다');
        } catch (DocumentConversionException $e) {
            $this->assertSame('BAD_DOCX', $e->errorCode());
        }
    }

    public function testReportsEmptyDocument(): void
    {
        try {
            $this->converter->convert($this->docx(''), 'docx');
            $this->fail('예외가 발생해야 한다');
        } catch (DocumentConversionException $e) {
            $this->assertSame('EMPTY', $e->errorCode());
        }
    }

    /**
     * 외부 엔티티를 실어 보내는 문서(XXE)를 넣어도 서버 파일이 새어 나오지 않는다.
     */
    public function testDoesNotResolveExternalEntities(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<!DOCTYPE root [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<w:document xmlns:w="' . self::DOCX_NS . '"><w:body>'
            . '<w:p><w:r><w:t>&xxe;</w:t></w:r></w:p>'
            . '</w:body></w:document>';

        $path = $this->tempPath('xxe.docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        // 엔티티가 빈 값으로 남든(내용 없음) 해석을 거부하든 상관없다 —
        // 서버 파일 내용이 결과에 실리지 않는 것이 이 검사의 요점이다.
        try {
            $html = $this->converter->convert($path, 'docx');
        } catch (DocumentConversionException $e) {
            $html = '';
            $this->assertContains($e->errorCode(), ['EMPTY', 'BAD_XML']);
        }

        $this->assertStringNotContainsString('root:', $html);
        $this->assertStringNotContainsString('/bin/', $html);
    }

    // ---------------------------------------------------------
    // 픽스처
    // ---------------------------------------------------------

    private function paragraph(
        string $text,
        string $style = '',
        bool $bold = false,
        bool $italic = false,
        bool $list = false,
    ): string {
        $properties = '';
        if ($style !== '') {
            $properties .= '<w:pStyle w:val="' . htmlspecialchars($style, ENT_QUOTES) . '"/>';
        }
        if ($list) {
            $properties .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr>';
        }

        $runProperties = '';
        if ($bold) {
            $runProperties .= '<w:b/>';
        }
        if ($italic) {
            $runProperties .= '<w:i/>';
        }

        return '<w:p>'
            . ($properties !== '' ? '<w:pPr>' . $properties . '</w:pPr>' : '')
            . '<w:r>'
            . ($runProperties !== '' ? '<w:rPr>' . $runProperties . '</w:rPr>' : '')
            . '<w:t>' . htmlspecialchars($text, ENT_QUOTES) . '</w:t>'
            . '</w:r></w:p>';
    }

    private function docx(string $body): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document xmlns:w="' . self::DOCX_NS . '"><w:body>' . $body . '</w:body></w:document>';

        $path = $this->tempPath('doc.docx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $path;
    }

    /**
     * @param list<string> $shared 공유 문자열
     * @param list<array<string, array{0: string, 1: string}>> $rows 셀참조 => [타입, 값]
     */
    private function xlsx(array $shared, array $rows): string
    {
        $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet><sheetData>';
        foreach ($rows as $index => $cells) {
            $sheet .= '<row r="' . ($index + 1) . '">';
            foreach ($cells as $reference => [$type, $value]) {
                $sheet .= '<c r="' . $reference . '"' . ($type !== '' ? ' t="' . $type . '"' : '') . '>'
                    . '<v>' . $value . '</v></c>';
            }
            $sheet .= '</row>';
        }
        $sheet .= '</sheetData></worksheet>';

        $path = $this->tempPath('book.xlsx');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        if ($shared !== []) {
            $strings = '<?xml version="1.0" encoding="UTF-8"?><sst>';
            foreach ($shared as $string) {
                $strings .= '<si><t>' . htmlspecialchars($string, ENT_QUOTES) . '</t></si>';
            }
            $zip->addFromString('xl/sharedStrings.xml', $strings . '</sst>');
        }

        $zip->close();

        return $path;
    }

    private function tempPath(string $name): string
    {
        $path = sys_get_temp_dir() . '/mublo-converter-' . bin2hex(random_bytes(6)) . '-' . $name;
        $this->tempFiles[] = $path;

        return $path;
    }
}
