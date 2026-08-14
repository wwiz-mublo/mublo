<?php
declare(strict_types=1);
namespace Mublo\Controller\Api;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Helper\Security\HtmlSanitizer;
use Mublo\Infrastructure\Security\RateLimiter;
use Mublo\Service\Editor\DocumentConversionException;
use Mublo\Service\Editor\DocumentConverter;

/**
 * 에디터 문서 가져오기 API
 *
 * POST /api/v1/editor/convert
 *
 * 책임:
 * - 업로드한 DOCX/XLSX 를 에디터 본문 HTML 로 변환해 돌려준다
 * - 파일은 저장하지 않는다. 요청이 끝나면 임시 파일과 함께 사라진다
 * - 프레임워크 미들웨어(SessionMiddleware, CsrfMiddleware)를 경유한다
 *
 * PDF 는 받지 않는다. 텍스트 추출에 외부 바이너리(poppler/pdftotext)가 필요해
 * 설치된 서버에서만 되는 기능이 되기 때문이다. 확장자 단계에서 거르고 왜
 * 안 되는지 알려 준다.
 *
 * 응답:
 * - success: { success: true, html }
 * - error:   { success: false, error, message }
 */
class EditorConvertController
{
    // 변환은 업로드보다 비싸다(압축 해제 + XML 파싱). 정상 사용은 글 하나에
    // 몇 번이므로 넉넉하되, 반복 호출로 CPU 를 태우는 것은 막는 선으로 잡는다.
    private const RATE_LIMIT_MAX = 20;
    private const RATE_LIMIT_WINDOW = 600;   // 10분

    /** 업로드 상한 — 문서는 이미지보다 크지만 본문에 들어갈 분량은 한정적이다 */
    private const MAX_FILE_SIZE = 10 * 1024 * 1024;

    public function __construct(
        private DocumentConverter $converter,
        private ?RateLimiter $rateLimiter = null,
    ) {
    }

    public function convert(Request $request, Context $context): JsonResponse
    {
        if ($this->rateLimiter !== null) {
            $key = 'editor-convert:' . ($context->getDomainId() ?? 0) . ':' . $request->getClientIp();
            if (!$this->rateLimiter->attempt($key, self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
                return JsonResponse::error('변환 요청이 너무 많습니다. 잠시 후 다시 시도해주세요.', null, 429);
            }
        }

        if (!$request->hasFile('file')) {
            return JsonResponse::error('파일이 업로드되지 않았습니다.', null, 400);
        }

        $file = $request->getRawFile('file');
        if (!is_array($file) || is_array($file['name'] ?? null)) {
            return JsonResponse::error('단일 파일만 변환할 수 있습니다.', null, 400);
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            if ($error === UPLOAD_ERR_INI_SIZE) {
                return JsonResponse::error(
                    '파일이 서버가 허용하는 업로드 크기를 초과했습니다. (php.ini 의 upload_max_filesize)',
                    null,
                    400
                );
            }

            return JsonResponse::error('파일 업로드에 실패했습니다. (error ' . $error . ')', null, 400);
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_FILE_SIZE) {
            $maxMb = round(self::MAX_FILE_SIZE / 1024 / 1024, 1);
            return JsonResponse::error('파일 크기가 허용 범위를 초과했습니다. (최대 ' . $maxMb . 'MB)', null, 400);
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return JsonResponse::error('업로드 파일을 찾을 수 없습니다.', null, 400);
        }

        $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

        if ($extension === 'pdf') {
            return JsonResponse::error(
                'PDF 가져오기는 아직 지원하지 않습니다. DOCX 나 XLSX 로 저장한 뒤 가져오세요.',
                ['error' => 'PDF_UNSUPPORTED'],
                422
            );
        }

        if (!in_array($extension, DocumentConverter::SUPPORTED_EXTENSIONS, true)) {
            return JsonResponse::error(
                '가져올 수 없는 파일 형식입니다. (DOCX · XLSX)',
                ['error' => 'UNSUPPORTED'],
                400
            );
        }

        try {
            $html = $this->converter->convert($tmpName, $extension);
        } catch (DocumentConversionException $e) {
            return JsonResponse::error($e->getMessage(), ['error' => $e->errorCode()], 422);
        }

        // 변환기는 문서 텍스트를 이스케이프하고 태그를 직접 조립하지만, 본문에
        // 들어갈 HTML 은 예외 없이 코어 정화기를 지난다 — 삽입 경로마다 신뢰
        // 판단이 갈리지 않게 한다.
        return JsonResponse::success(['html' => HtmlSanitizer::sanitizeEditorContent($html)]);
    }
}
