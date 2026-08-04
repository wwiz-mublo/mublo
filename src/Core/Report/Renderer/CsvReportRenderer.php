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

class CsvReportRenderer implements ReportRendererInterface
{
    public function supports(string $format): bool
    {
        return strtolower($format) === 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv; charset=UTF-8';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function renderToFile(ReportDocument $document, string $filePath): void
    {
        $fp = fopen($filePath, 'wb');
        if ($fp === false) {
            throw new ReportRenderException('CSV 파일을 생성할 수 없습니다.');
        }

        try {
            fwrite($fp, "\xEF\xBB\xBF");

            $sectionCount = 0;
            foreach ($document->sections() as $section) {
                if ($sectionCount > 0) {
                    $this->writeRow($fp, []);
                }

                if ($section instanceof KeyValueSection) {
                    $this->renderKeyValueSection($fp, $section);
                } elseif ($section instanceof TextSection) {
                    $this->renderTextSection($fp, $section);
                } elseif ($section instanceof TableSection) {
                    $this->renderTableSection($fp, $section);
                }

                $sectionCount++;
            }
        } finally {
            fclose($fp);
        }
    }

    private function renderTableSection($fp, TableSection $table): void
    {
        $columns = $table->columns();
        if ($table->withHeader()) {
            $headers = array_map(static fn($col) => $col->label, $columns);
            $this->writeRow($fp, $headers);
        }

        foreach ($table->rows() as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column->key] ?? null;
                if (is_callable($column->formatter)) {
                    $value = ($column->formatter)($value, $row);
                }
                $line[] = $value;
            }
            $this->writeRow($fp, $line);
        }
    }

    private function renderKeyValueSection($fp, KeyValueSection $section): void
    {
        if ($section->title() !== '') {
            $this->writeRow($fp, [$section->title()]);
        }
        foreach ($section->items() as $item) {
            $this->writeRow($fp, [$item['label'] ?? '', $item['value'] ?? '']);
        }
    }

    private function renderTextSection($fp, TextSection $section): void
    {
        if ($section->title() !== '') {
            $this->writeRow($fp, [$section->title()]);
        }
        $this->writeRow($fp, [$section->content()]);
    }

    /**
     * @param resource $fp
     * @param array<int,mixed> $row
     */
    private function writeRow($fp, array $row): void
    {
        fputcsv($fp, ReportCellValueSanitizer::csvRow($row), ',', '"', '\\');
    }
}
