<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

use Mublo\Core\AiConfig;
use Mublo\Infrastructure\Storage\UploadPolicy;
use Mublo\Infrastructure\Storage\UploadedFile;

final class AiAssetPolicy
{
    public function assertUpload(UploadedFile $file): array
    {
        $config = AiConfig::assets();
        if (!$file->isValid()) throw new \InvalidArgumentException($file->getErrorMessage());

        $extension = strtolower($file->getExtension());
        if (!in_array($extension, $config['allowed_extensions'], true)
            || !UploadPolicy::matches($extension, $file->getMimeType())) {
            throw new \InvalidArgumentException('허용되지 않거나 실제 형식이 일치하지 않는 파일입니다: ' . $extension);
        }

        $isImage = in_array($extension, $config['image_extensions'], true);
        $max = $isImage ? $config['max_image_bytes']
            : ($extension === 'pdf' ? $config['max_pdf_bytes'] : $config['max_document_bytes']);
        if ($file->getSize() > $max) {
            throw new \InvalidArgumentException('파일 크기가 허용 한도를 초과했습니다.');
        }

        $metadata = [];
        if ($isImage) {
            $info = @getimagesize($file->getTmpName());
            if ($info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1) {
                throw new \InvalidArgumentException('손상되었거나 올바르지 않은 이미지입니다.');
            }
            $metadata = ['width' => $info[0], 'height' => $info[1]];
        } elseif ($extension === 'pdf') {
            $contents = file_get_contents($file->getTmpName()) ?: '';
            if (!str_starts_with($contents, '%PDF-') || str_contains($contents, '/Encrypt')) {
                throw new \InvalidArgumentException('암호화되었거나 올바르지 않은 PDF는 사용할 수 없습니다.');
            }
        } elseif (in_array($extension, ['docx', 'xlsx', 'pptx'], true)) {
            $metadata = $this->inspectOfficeArchive($file->getTmpName(), $extension, $config);
        }

        return ['extension' => $extension, 'is_image' => $isImage, 'metadata' => $metadata];
    }

    private function inspectOfficeArchive(string $path, string $extension, array $config): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Office 문서를 검사하려면 PHP zip 확장이 필요합니다.');
        }
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new \InvalidArgumentException('손상된 Office 문서입니다.');
        try {
            if ($zip->numFiles > $config['max_zip_entries']) {
                throw new \InvalidArgumentException('문서 내부 파일 수가 허용 한도를 초과했습니다.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) continue;
                $size = (int) ($stat['size'] ?? 0);
                $compressed = max(1, (int) ($stat['comp_size'] ?? 0));
                $total += $size;
                if ($total > $config['max_zip_uncompressed_bytes'] || $size / $compressed > $config['max_zip_ratio']) {
                    throw new \InvalidArgumentException('압축 해제 크기가 비정상적인 문서는 사용할 수 없습니다.');
                }
            }
            $required = ['docx' => 'word/document.xml', 'xlsx' => 'xl/workbook.xml', 'pptx' => 'ppt/presentation.xml'][$extension];
            if ($zip->locateName($required) === false) throw new \InvalidArgumentException('확장자와 Office 문서 종류가 일치하지 않습니다.');
            return ['archive_entries' => $zip->numFiles, 'uncompressed_bytes' => $total];
        } finally {
            $zip->close();
        }
    }
}
