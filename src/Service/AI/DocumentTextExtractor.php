<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use PhpOffice\PhpSpreadsheet\IOFactory;

class DocumentTextExtractor
{
    public function extract(string $path, string $extension): ?string
    {
        $text = match ($extension) {
            'txt', 'md', 'csv', 'json' => file_get_contents($path) ?: '',
            'docx' => $this->extractDocx($path),
            'pptx' => $this->extractPptx($path),
            'xlsx' => $this->extractXlsx($path),
            default => null,
        };
        if ($text === null) return null;
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', ['UTF-8', 'CP949', 'EUC-KR', 'ISO-8859-1']);
        }
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
        $text = preg_replace("/\r\n?|\n/u", "\n", $text) ?? '';
        $max = (int) AiConfig::assets()['max_extracted_chars_per_asset'];
        return mb_substr(trim($text), 0, $max);
    }

    private function extractDocx(string $path): string
    {
        $zip = $this->openZip($path);
        try {
            return $this->xmlText((string) $zip->getFromName('word/document.xml'), ['w:p', 'w:br', 'w:tab']);
        } finally { $zip->close(); }
    }

    private function extractPptx(string $path): string
    {
        $zip = $this->openZip($path);
        try {
            $slides = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && preg_match('~^ppt/slides/slide(\d+)\.xml$~', $name, $m)) {
                    $slides[(int) $m[1]] = $this->xmlText((string) $zip->getFromIndex($i), ['a:p', 'a:br']);
                }
            }
            ksort($slides);
            return implode("\n\n", array_map(fn ($n, $text) => "[슬라이드 {$n}]\n{$text}", array_keys($slides), $slides));
        } finally { $zip->close(); }
    }

    private function extractXlsx(string $path): string
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $out = [];
        try {
            foreach ($book->getWorksheetIterator() as $sheet) {
                $out[] = '[시트: ' . $sheet->getTitle() . ']';
                $rows = 0;
                foreach ($sheet->toArray(null, true, true, false) as $row) {
                    if (++$rows > 1000) break;
                    $values = array_map(fn ($v) => trim((string) $v), $row);
                    if (implode('', $values) !== '') $out[] = implode("\t", $values);
                }
            }
        } finally { $book->disconnectWorksheets(); }
        return implode("\n", $out);
    }

    private function openZip(string $path): \ZipArchive
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \InvalidArgumentException('문서를 열 수 없습니다.');
        return $zip;
    }

    private function xmlText(string $xml, array $breakTags): string
    {
        foreach ($breakTags as $tag) {
            $quoted = preg_quote($tag, '/');
            $xml = preg_replace('/<\/?' . $quoted . '\b[^>]*>/i', "\n", $xml) ?? $xml;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return '';
            return html_entity_decode($dom->textContent, ENT_QUOTES | ENT_XML1, 'UTF-8');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
