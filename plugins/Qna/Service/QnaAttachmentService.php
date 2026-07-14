<?php
namespace Mublo\Plugin\Qna\Service;

use Mublo\Infrastructure\Storage\SecureFileService;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Infrastructure\Storage\UploadResult;

/**
 * QnaAttachmentService
 *
 * QnA 글(질문·답변) 첨부 파일 처리. 코어 SecureFileService 를 감싸,
 * 첨부를 보안 저장소(storage/files/, 웹 접근 불가)에 저장하고 다운로드는
 * HMAC 서명 토큰으로만 허용한다.
 *
 * 저장 위치: storage/files/D{domainId}/qna/{questionId}/{hash.ext}
 *   - entityId 는 항상 "스레드 헤드(질문) post_id" 다. 질문·답변 첨부 모두
 *     같은 질문 폴더에 모여, 다운로드 권한을 스레드 단위로 판정할 수 있다
 *     ([[QnaFileAccessSubscriber]] 가 category='qna' 를 처리).
 *
 * 메타 저장: qna_posts.meta JSON 의 'attachments' 키에 배열로 담는다.
 *   [{disk, relative_path, stored_name, original_name, size, mime_type, extension}, ...]
 *
 * 파일 흐름:
 *   1. 사용자가 파일 선택 → AJAX uploadTemp() → 임시 경로 반환
 *   2. 글 저장 시 finalize() → 임시→최종 이동 + 메타 배열 반환 → meta 컬럼 저장
 *   3. 글 삭제 시 deletePostFiles() → 디스크 파일 삭제
 */
class QnaAttachmentService
{
    private const CATEGORY = 'qna';

    public function __construct(private SecureFileService $secureFile)
    {
    }

    /**
     * 임시 업로드 (AJAX 파일 선택 시).
     *
     * @param array $policy {max_mb:int, allowed_ext:string}
     */
    public function uploadTemp(UploadedFile $file, int $domainId, array $policy = []): UploadResult
    {
        return $this->secureFile->uploadTemp($file, $domainId, [
            'max_size'    => (int) ($policy['max_mb'] ?? 5),
            'allowed_ext' => (string) ($policy['allowed_ext'] ?? ''),
        ]);
    }

    /**
     * 임시 업로드 결과 → 프론트 응답용 배열.
     * 이 배열을 클라이언트가 보관했다가 글 저장 시 그대로 되돌려 준다.
     */
    public function tempResponse(UploadResult $result): array
    {
        return [
            'temp_path' => $result->getRelativePath() . '/' . $result->getStoredName(),
            'filename'  => $result->getOriginalName(),
            'size'      => $result->getSize(),
            'extension' => $result->getExtension(),
            'mime_type' => $result->getMimeType(),
        ];
    }

    /**
     * 클라이언트가 보낸 임시 첨부 목록을 최종 경로로 이동하고 메타 배열을 만든다.
     *
     * @param array $tempList   [{temp_path, filename, size, ...}, ...] (클라이언트 입력)
     * @param int   $questionId 스레드 헤드(질문) post_id — 첨부 저장 폴더 겸 권한 단위
     * @param int   $maxCount   글당 첨부 개수 상한
     * @return array<int, array> 저장된 첨부 메타 배열
     */
    public function finalize(array $tempList, int $domainId, int $questionId, int $maxCount = 3): array
    {
        $metas = [];

        foreach ($tempList as $item) {
            if (count($metas) >= max(1, $maxCount)) {
                break;
            }

            $temp = is_string($item) ? json_decode($item, true) : $item;
            if (!is_array($temp) || empty($temp['temp_path'])) {
                continue;
            }

            try {
                $stored = $this->secureFile->moveFinal(
                    $temp['temp_path'],
                    $domainId,
                    self::CATEGORY,
                    (string) $questionId,
                );
            } catch (\Throwable) {
                // 임시 파일 만료/위조 경로 등은 조용히 건너뛴다.
                continue;
            }

            $metas[] = [
                'disk'          => 'secure',
                'relative_path' => $stored->relativePath,
                'stored_name'   => $stored->storedName,
                'original_name' => (string) ($temp['filename'] ?? $stored->storedName),
                'size'          => (int) ($temp['size'] ?? $stored->size),
                'mime_type'     => (string) ($temp['mime_type'] ?? $stored->mimeType),
                'extension'     => (string) ($temp['extension'] ?? $stored->extension),
            ];
        }

        return $metas;
    }

    /**
     * 첨부 메타 배열 → meta 컬럼 JSON 문자열.
     * 기존 meta 의 다른 키는 보존한다.
     */
    public function buildMetaColumn(array $attachmentMetas, ?string $existingMetaJson = null): string
    {
        $meta = [];
        if (!empty($existingMetaJson)) {
            $decoded = json_decode($existingMetaJson, true);
            if (is_array($decoded)) {
                $meta = $decoded;
            }
        }

        $meta['attachments'] = array_values($attachmentMetas);

        return json_encode($meta, JSON_UNESCAPED_UNICODE);
    }

    /**
     * meta 컬럼 JSON → 첨부 메타 배열(원본).
     *
     * @return array<int, array>
     */
    public function extract(?string $metaJson): array
    {
        if (empty($metaJson)) {
            return [];
        }

        $meta = json_decode($metaJson, true);
        if (!is_array($meta) || empty($meta['attachments']) || !is_array($meta['attachments'])) {
            return [];
        }

        return $meta['attachments'];
    }

    /**
     * meta 컬럼 JSON → 뷰 렌더용 배열(다운로드 URL 포함).
     * 열람 권한이 있는 뷰에서만 호출한다 — 여기서 나온 서명 토큰이 곧 접근 허가다.
     *
     * @return array<int, array{filename:string, size:int, extension:string, url:string}>
     */
    public function withDownloadUrls(?string $metaJson, int $ttl = 3600): array
    {
        $out = [];

        foreach ($this->extract($metaJson) as $att) {
            if (empty($att['relative_path'])) {
                continue;
            }

            $out[] = [
                'filename'  => (string) ($att['original_name'] ?? $att['stored_name'] ?? ''),
                'size'      => (int) ($att['size'] ?? 0),
                'extension' => (string) ($att['extension'] ?? ''),
                'url'       => $this->secureFile->generateDownloadUrl($att['relative_path'], $ttl, [
                    'filename' => $att['original_name'] ?? $att['stored_name'] ?? null,
                ]),
            ];
        }

        return $out;
    }

    /** 글의 첨부 파일을 디스크에서 모두 삭제 (글 삭제 시). */
    public function deletePostFiles(?string $metaJson): void
    {
        foreach ($this->extract($metaJson) as $att) {
            if (!empty($att['relative_path'])) {
                $this->secureFile->delete($att['relative_path']);
            }
        }
    }
}
