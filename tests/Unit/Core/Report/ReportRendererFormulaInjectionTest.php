<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Contract\RowProviderInterface;
use Mublo\Core\Report\Document\ColumnDefinition;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\KeyValueSection;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Document\Section\TextSection;
use Mublo\Core\Report\Renderer\CsvReportRenderer;
use Mublo\Core\Report\Renderer\XlsxReportRenderer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\TestCase;

final class ReportRendererFormulaInjectionTest extends TestCase
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

    public function testCsvSanitizesEverySectionAndKeepsNumericValuesNumeric(): void
    {
        $file = $this->tempFile('csv');
        (new CsvReportRenderer())->renderToFile($this->document(), $file);

        $rows = $this->readCsv($file);

        $this->assertSame("'=Header", $rows[0][0]);
        $this->assertSame("'\t=ROW", $rows[1][0]);
        $this->assertSame("'-100", $rows[1][1]);
        $this->assertSame('-100', $rows[1][2]);
        $this->assertSame("'+Summary", $rows[3][0]);
        $this->assertSame("'@Label", $rows[4][0]);
        $this->assertSame("' -Value", $rows[4][1]);
        $this->assertSame("' =Text title", $rows[6][0]);
        $this->assertSame("'\xEF\xBB\xBF=Text", $rows[7][0]);
    }

    public function testXlsxStoresFormulaLikeValuesExplicitlyAsStrings(): void
    {
        $file = $this->tempFile('xlsx');
        (new XlsxReportRenderer())->renderToFile($this->document(), $file);

        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();

        foreach (['A1', 'A2', 'B2', 'A4', 'A5', 'B5', 'A7', 'A8'] as $cell) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), $cell);
            $this->assertFalse($sheet->getCell($cell)->isFormula(), $cell);
        }

        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('C2')->getDataType());
        $this->assertSame(-100, $sheet->getCell('C2')->getValue());
        $spreadsheet->disconnectWorksheets();
    }

    private function document(): ReportDocument
    {
        $provider = new class implements RowProviderInterface {
            public function rows(): iterable
            {
                yield ['danger' => "\t=ROW", 'signed_string' => '-100', 'number' => -100];
            }

            public function totalCount(): ?int { return 1; }
            public function isRewindable(): bool { return true; }
            public function getChunk(int $offset, int $limit): array { return []; }
        };

        return ReportDocument::create('Formula safety')
            ->addSection(new TableSection([
                new ColumnDefinition('danger', '=Header'),
                new ColumnDefinition('signed_string', 'Safe'),
                new ColumnDefinition('number', 'Number', 'number'),
            ], $provider))
            ->addSection(new KeyValueSection('+Summary', [
                ['label' => '@Label', 'value' => ' -Value'],
            ]))
            ->addSection(new TextSection("\xEF\xBB\xBF=Text", ' =Text title'));
    }

    private function tempFile(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'mublo-report-');
        if ($base === false) {
            $this->fail('임시 파일을 만들 수 없습니다.');
        }
        $file = $base . '.' . $extension;
        rename($base, $file);
        $this->files[] = $file;
        return $file;
    }

    private function readCsv(string $file): array
    {
        $fp = fopen($file, 'rb');
        $this->assertNotFalse($fp);
        $this->assertSame("\xEF\xBB\xBF", fread($fp, 3));

        $rows = [];
        while (($row = fgetcsv($fp, null, ',', '"', '\\')) !== false) {
            $rows[] = $row;
        }
        fclose($fp);
        return $rows;
    }
}
