<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Renderer\XlsxReportRenderer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class XlsxReportRendererStreamingTest extends TestCase
{
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    public function testCellValuesFollowSpreadsheetValueRules(): void
    {
        $file = $this->tempFile();
        (new XlsxReportRenderer())->renderToFile($this->document([
            ['phone' => '01012345678', 'amount' => '12500', 'note' => '혜택 "안내" <b>&'],
            ['phone' => 1234567890123456, 'amount' => 1.5, 'note' => "줄\x01바꿈\n둘째 줄"],
            ['phone' => true, 'amount' => null, 'note' => ['a' => 1]],
        ]), $file);

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();

        $this->assertSame('Report', $sheet->getTitle());
        $this->assertSame('전화', $sheet->getCell('A1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());

        // 앞자리 0 은 문자열, 숫자형 문자열은 숫자
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A2')->getDataType());
        $this->assertSame('01012345678', $sheet->getCell('A2')->getValue());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame(12500, $sheet->getCell('B2')->getValue());
        $this->assertSame('혜택 "안내" <b>&', $sheet->getCell('C2')->getValue());

        // 15자리를 넘는 정수는 문자열, 실수는 숫자, 제어 문자는 지움
        $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('A3')->getDataType());
        $this->assertSame('1234567890123456', $sheet->getCell('A3')->getValue());
        $this->assertSame(1.5, $sheet->getCell('B3')->getValue());
        $this->assertSame("줄바꿈\n둘째 줄", $sheet->getCell('C3')->getValue());

        // bool 은 1, null 은 빈칸, 배열은 JSON
        $this->assertSame(1, $sheet->getCell('A4')->getValue());
        $this->assertNull($sheet->getCell('B4')->getValue());
        $this->assertSame('{"a":1}', $sheet->getCell('C4')->getValue());

        // 금액 서식·정렬, 머리글 고정, 자동 필터
        $this->assertSame('#,##0', $sheet->getStyle('B2')->getNumberFormat()->getFormatCode());
        $this->assertSame('right', $sheet->getStyle('B2')->getAlignment()->getHorizontal());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:C4', $sheet->getAutoFilter()->getRange());

        $spreadsheet->disconnectWorksheets();
    }

    public function testLargeReportKeepsMemoryFlat(): void
    {
        $rows = 30000;
        $provider = new class ($rows) implements RowProviderInterface {
            public function __construct(private int $count)
            {
            }

            public function rows(): iterable
            {
                for ($i = 1; $i <= $this->count; $i++) {
                    yield [
                        'id' => 'user' . $i,
                        'name' => '회원' . $i,
                        'phone' => '010' . str_pad((string) $i, 8, '0', STR_PAD_LEFT),
                        'email' => 'user' . $i . '@example.com',
                        'point' => $i * 10,
                        'joined' => '2026-01-01 00:00:00',
                        'level' => '일반회원',
                        'status' => '정상',
                        'memo' => str_repeat('메모', 5),
                        'addr' => '서울시 강남구 테헤란로 ' . $i,
                    ];
                }
            }

            public function totalCount(): ?int { return $this->count; }
            public function isRewindable(): bool { return true; }
            public function getChunk(int $offset, int $limit): array { return []; }
        };

        $columns = [];
        foreach (['id', 'name', 'phone', 'email', 'point', 'joined', 'level', 'status', 'memo', 'addr'] as $key) {
            $columns[] = new ColumnDefinition($key, $key, $key === 'point' ? 'money' : 'string');
        }
        $document = ReportDocument::create('Large')->addSection(new TableSection($columns, $provider));

        $file = $this->tempFile();
        $before = memory_get_usage(true);
        (new XlsxReportRenderer())->renderToFile($document, $file);
        $grown = memory_get_peak_usage(true) - $before;

        // 30만 셀을 메모리에 올리면 수백 MB 가 든다. 흘려 쓰면 행 수와 상관없이 몇 MB 안이다.
        $this->assertLessThan(16 * 1024 * 1024, $grown);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $sheetXml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('<row r="' . ($rows + 1) . '">', $sheetXml);
        $this->assertStringContainsString('<autoFilter ref="A1:J' . ($rows + 1) . '"/>', $sheetXml);
    }

    private function document(array $rows): ReportDocument
    {
        $provider = new class ($rows) implements RowProviderInterface {
            public function __construct(private array $data)
            {
            }

            public function rows(): iterable
            {
                yield from $this->data;
            }

            public function totalCount(): ?int { return count($this->data); }
            public function isRewindable(): bool { return true; }
            public function getChunk(int $offset, int $limit): array { return []; }
        };

        return ReportDocument::create('Values')->addSection(new TableSection([
            new ColumnDefinition('phone', '전화'),
            new ColumnDefinition('amount', '금액', 'money', 'right'),
            new ColumnDefinition('note', '메모'),
        ], $provider));
    }

    private function tempFile(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mublo-xlsx-test-');
        if ($base === false) {
            $this->fail('임시 파일을 만들 수 없습니다.');
        }
        $file = $base . '.xlsx';
        rename($base, $file);
        $this->files[] = $file;
        return $file;
    }
}
