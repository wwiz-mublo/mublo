<?php

namespace Mublo\Core\Report\Renderer;

use Dompdf\Dompdf;
use Dompdf\Options;
use Mublo\Core\Report\Contract\ReportRendererInterface;
use Mublo\Core\Report\Document\ReportDocument;
use Mublo\Core\Report\Document\Section\KeyValueSection;
use Mublo\Core\Report\Document\Section\TableSection;
use Mublo\Core\Report\Document\Section\TextSection;
use Mublo\Core\Report\Exception\ReportRenderException;

class PdfReportRenderer implements ReportRendererInterface
{
    public function supports(string $format): bool
    {
        return strtolower($format) === 'pdf';
    }

    public function mimeType(): string
    {
        return 'application/pdf';
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function renderToFile(ReportDocument $document, string $filePath): void
    {
        if (!class_exists(Dompdf::class)) {
            throw new ReportRenderException('dompdf 의존성이 필요합니다.');
        }

        $html = $this->buildHtml($document);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $pdf = $dompdf->output();
        if (file_put_contents($filePath, $pdf) === false) {
            throw new ReportRenderException('PDF 파일 저장에 실패했습니다.');
        }
    }

    private function buildHtml(ReportDocument $document): string
    {
        $body = '<h1>' . $this->escape($document->title()) . '</h1>';

        foreach ($document->sections() as $section) {
            if ($section instanceof KeyValueSection) {
                $body .= $this->renderKeyValueSection($section);
            } elseif ($section instanceof TextSection) {
                $body .= $this->renderTextSection($section);
            } elseif ($section instanceof TableSection) {
                $body .= $this->renderTableSection($section);
            }
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:12px;color:#222;}'
            . 'h1{font-size:18px;margin:0 0 12px 0;}'
            . 'h3{font-size:14px;margin:16px 0 6px 0;}'
            . 'table{width:100%;border-collapse:collapse;margin-bottom:12px;}'
            . 'th,td{border:1px solid #ddd;padding:6px 8px;vertical-align:top;word-break:break-all;}'
            . 'th{background:#f3f4f6;text-align:center;font-weight:700;}'
            . '.align-right{text-align:right;}'
            . '.align-center{text-align:center;}'
            . '.align-left{text-align:left;}'
            . '.kv-table{width:auto;margin-bottom:12px;}'
            . '.kv-table td{border:1px solid #e5e7eb;}'
            . '.kv-label{font-weight:700;background:#f9fafb;white-space:nowrap;}'
            . '.text-block{margin:8px 0 12px 0;line-height:1.6;}'
            . '</style></head><body>' . $body . '</body></html>';
    }

    private function renderTableSection(TableSection $table): string
    {
        $columns = $table->columns();
        if (empty($columns)) {
            throw new ReportRenderException('컬럼 정의가 비어 있습니다.');
        }

        $head = '';
        foreach ($columns as $column) {
            $head .= '<th>' . $this->escape((string) $column->label) . '</th>';
        }

        $body = '';
        foreach ($table->rows() as $row) {
            $cells = '';
            foreach ($columns as $column) {
                $value = $row[$column->key] ?? '';
                if (is_callable($column->formatter)) {
                    $value = ($column->formatter)($value, $row);
                }
                $cells .= '<td class="align-' . $this->escape($column->align) . '">'
                    . $this->escape($this->normalizeValue($value))
                    . '</td>';
            }
            $body .= '<tr>' . $cells . '</tr>';
        }

        return '<table><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
    }

    private function renderKeyValueSection(KeyValueSection $section): string
    {
        $html = '';
        if ($section->title() !== '') {
            $html .= '<h3>' . $this->escape($section->title()) . '</h3>';
        }

        $html .= '<table class="kv-table">';
        foreach ($section->items() as $item) {
            $html .= '<tr>'
                . '<td class="kv-label">' . $this->escape($item['label'] ?? '') . '</td>'
                . '<td>' . $this->escape($item['value'] ?? '') . '</td>'
                . '</tr>';
        }
        $html .= '</table>';

        return $html;
    }

    private function renderTextSection(TextSection $section): string
    {
        $html = '';
        if ($section->title() !== '') {
            $html .= '<h3>' . $this->escape($section->title()) . '</h3>';
        }
        $html .= '<div class="text-block">' . nl2br($this->escape($section->content())) . '</div>';

        return $html;
    }

    private function normalizeValue($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
