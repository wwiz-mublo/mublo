<?php

namespace Mublo\Core\Report\Renderer;

use Mublo\Core\Report\Contract\ReportRendererInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\KeyValueSection;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Document\Section\TextSection;
use Mublo\Core\Report\Exception\ReportRenderException;
use Mublo\Core\Report\Security\ReportCellValueSanitizer;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class XlsxReportRenderer implements ReportRendererInterface
{
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
        if (!class_exists(Spreadsheet::class)) {
            throw new ReportRenderException('PhpSpreadsheet 의존성이 필요합니다.');
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $rowIndex = 1;
        $maxCol = 1;
        $sectionCount = 0;

        foreach ($document->sections() as $section) {
            if ($sectionCount > 0) {
                $rowIndex++;
            }

            if ($section instanceof KeyValueSection) {
                $rowIndex = $this->renderKeyValueSection($sheet, $section, $rowIndex);
            } elseif ($section instanceof TextSection) {
                $rowIndex = $this->renderTextSection($sheet, $section, $rowIndex);
            } elseif ($section instanceof TableSection) {
                $cols = count($section->columns());
                if ($cols > $maxCol) {
                    $maxCol = $cols;
                }
                $rowIndex = $this->renderTableSection($sheet, $section, $rowIndex);
            }

            $sectionCount++;
        }

        for ($i = 1; $i <= $maxCol; $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet);
    }

    private function renderTableSection($sheet, TableSection $table, int $rowIndex): int
    {
        $columns = $table->columns();
        if (empty($columns)) {
            throw new ReportRenderException('컬럼 정의가 비어 있습니다.');
        }

        if ($table->withHeader()) {
            foreach ($columns as $index => $column) {
                $this->setCellValue($sheet, Coordinate::stringFromColumnIndex($index + 1) . $rowIndex, (string) $column->label);
            }

            $start = 'A' . $rowIndex;
            $end = Coordinate::stringFromColumnIndex(count($columns)) . $rowIndex;
            $sheet->getStyle($start . ':' . $end)->getFont()->setBold(true);
            $sheet->getStyle($start . ':' . $end)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->freezePane('A' . ($rowIndex + 1));
            $sheet->setAutoFilter($start . ':' . $end);
            $rowIndex++;
        }

        foreach ($table->rows() as $row) {
            foreach ($columns as $index => $column) {
                $col = $index + 1;
                $cell = Coordinate::stringFromColumnIndex($col) . $rowIndex;
                $value = $row[$column->key] ?? null;

                if (is_callable($column->formatter)) {
                    $value = ($column->formatter)($value, $row);
                }

                $this->setCellValue($sheet, $cell, $this->normalizeValue($value));
                $this->applyColumnStyle($sheet, $column->type, $column->align, $col, $rowIndex);
            }

            $rowIndex++;
        }

        return $rowIndex;
    }

    private function renderKeyValueSection($sheet, KeyValueSection $section, int $rowIndex): int
    {
        if ($section->title() !== '') {
            $this->setCellValue($sheet, 'A' . $rowIndex, $section->title());
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
            $rowIndex++;
        }

        foreach ($section->items() as $item) {
            $this->setCellValue($sheet, 'A' . $rowIndex, $item['label'] ?? '');
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
            $this->setCellValue($sheet, 'B' . $rowIndex, $item['value'] ?? '');
            $rowIndex++;
        }

        return $rowIndex;
    }

    private function renderTextSection($sheet, TextSection $section, int $rowIndex): int
    {
        if ($section->title() !== '') {
            $this->setCellValue($sheet, 'A' . $rowIndex, $section->title());
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
            $rowIndex++;
        }

        $this->setCellValue($sheet, 'A' . $rowIndex, $section->content());
        $rowIndex++;

        return $rowIndex;
    }

    private function normalizeValue($value)
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

    private function setCellValue($sheet, string $cell, mixed $value): void
    {
        if (ReportCellValueSanitizer::isFormulaLike($value)) {
            $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    private function applyColumnStyle($sheet, string $type, string $align, int $col, int $row): void
    {
        $cell = Coordinate::stringFromColumnIndex($col) . $row;
        $style = $sheet->getStyle($cell);

        switch (strtolower($align)) {
            case 'right':
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
            case 'center':
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                break;
            default:
                $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
        }

        switch (strtolower($type)) {
            case 'money':
                $style->getNumberFormat()->setFormatCode('#,##0');
                break;
            case 'number':
                $style->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER);
                break;
            case 'date':
                $style->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_DATE_YYYYMMDD);
                break;
            default:
                break;
        }
    }
}
