<?php
declare(strict_types=1);
namespace Mublo\Controller\Admin;

use Mublo\Core\Response\JsonResponse;
use Mublo\Infrastructure\Storage\UploadedFile;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Block\BlockKitApplier;
use Mublo\Service\Block\BlockKitScreenshot;

/**
 * 블록 킷을 다루는 관리자 컨트롤러가 공유하는 도우미.
 *
 * 블록 킷은 블록 행(BlockRowController), 블록 페이지(BlockPageController), 그리고 블록 킷 보관소
 * (BlockKitController) 세 화면에서 다뤄진다. 업로드 검증과 권한 가드는 셋 다 똑같아야 한다.
 * 한 곳에서 크기 검사 순서를 놓치면 그 화면만 조용히 뚫린다.
 *
 * 컨트롤러는 $this->container 를 갖는다고 가정한다(AdminController 기반).
 */
trait BlockKitControllerTrait
{
    /**
     * 블록 킷은 도메인 운영자 이상만 다룰 수 있다(설계 9.4).
     *
     * 운영자 게이트는 자동 라우팅 미들웨어로 걸리지 않으므로 메서드 안에서 직접 확인한다.
     *
     * @param string $subject 거부 메시지의 주어. 조사를 포함한다("블록 킷 내보내기는").
     *                        조사를 코드가 고르게 하면 "블록 킷 내보내기은(는)" 이 나온다.
     */
    private function denyUnlessDomainOperator(string $subject = '블록 킷 기능은'): ?JsonResponse
    {
        /** @var AuthService $authService */
        $authService = $this->container->get(AuthService::class);

        if (!$authService->canOperateDomain()) {
            return JsonResponse::forbidden("{$subject} 도메인 운영자 이상만 사용할 수 있습니다.");
        }

        return null;
    }

    /**
     * 업로드된 블록 킷 파일을 읽는다.
     *
     * UploadPolicy 를 수정하지 않는다 — allowlist 에 json 을 추가하면 게시판 첨부를 포함한
     * 모든 업로드 경로가 함께 열린다(설계 11.2 ⑤).
     *
     * 크기 검사는 json_decode 앞에 둔다 — 순서가 뒤집히면 거대한 JSON 이 메모리를 먼저
     * 소모한다(4.7 ②). 그래서 file_get_contents 조차 크기 확인 뒤에 부른다.
     *
     * @return string|JsonResponse 성공 시 JSON 문자열, 실패 시 오류 응답
     */
    private function readKitUpload(string $field = 'kit_file'): string|JsonResponse
    {
        $file = UploadedFile::fromGlobal($field);
        if ($file === null || !$file->isValid()) {
            return JsonResponse::error('블록 킷 파일을 업로드하세요.');
        }

        if (strtolower($file->getExtension()) !== 'json') {
            return JsonResponse::error('json 파일만 업로드할 수 있습니다.');
        }

        if ($file->getSize() > BlockKitApplier::MAX_KIT_BYTES) {
            return JsonResponse::error('블록 킷 파일은 2 MiB를 초과할 수 없습니다.');
        }

        $json = @file_get_contents($file->getTmpName());
        if ($json === false) {
            return JsonResponse::error('블록 킷 파일을 읽을 수 없습니다.');
        }

        return $json;
    }

    /**
     * 업로드된 스크린샷을 webp data URI 로 변환한다(설계 4.7).
     *
     * UploadPolicy 를 거치지 않는다 — 파일로 저장하지 않고 즉시 변환해 블록 킷 JSON 에 임베드한다.
     * 변환 실패 시 스크린샷만 버리고 블록 킷 내보내기는 진행한다.
     */
    private function collectKitScreenshot(): ?string
    {
        $file = UploadedFile::fromGlobal('kit_screenshot');
        if ($file === null || !$file->isValid() || !$file->isImage()) {
            return null;
        }

        /** @var BlockKitScreenshot $screenshot */
        $screenshot = $this->container->get(BlockKitScreenshot::class);

        return $screenshot->toDataUri($file->getTmpName());
    }

    /**
     * 블록 킷 내려받기의 Content-Disposition 헤더 값을 만든다.
     *
     * 블록 킷 · 블록 행 · 블록 페이지 세 화면이 같은 규칙으로 파일명을 붙이도록 한 곳에 모은다.
     * 파일명 규칙: 한글 등 유니코드 문자는 살리고, 공백류는 하이픈으로 바꾸며, 경로/제어
     * 위험 문자만 걷어낸다. 뒤에 -kit.json 을 붙인다.
     *
     * HTTP 의 filename= 파라미터는 ASCII(Latin-1)만 안전하다 — 한글을 그대로 넣으면
     * 브라우저마다 깨진다(모지바케). 그래서 RFC 5987 의 filename*=UTF-8'' 로 퍼센트 인코딩해
     * 실제 이름을 전하고, filename= 에는 ASCII 폴백을 함께 둔다(구형 클라이언트용).
     *
     * @param string $base 확장자/접미사 이전의 원본 이름(킷 이름 또는 위치/페이지 코드)
     */
    private function kitDownloadDisposition(string $base): string
    {
        $name = $this->sanitizeKitFileBase($base) . '-kit.json';

        // ASCII 폴백 — 비ASCII를 하이픈으로 접고 남는 게 없으면 block 으로.
        $asciiBase = trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $base), '-');
        $ascii = ($asciiBase !== '' ? $asciiBase : 'block') . '-kit.json';

        return 'attachment; filename="' . $ascii . '"; '
            . "filename*=UTF-8''" . rawurlencode($name);
    }

    /**
     * 파일명 base 를 정리한다(유니코드 보존, 공백→하이픈, 위험문자 제거).
     *
     * /u 플래그가 핵심이다 — 없으면 한글이 바이트 단위로 쪼개져 매 바이트가 하이픈이 된다.
     */
    private function sanitizeKitFileBase(string $base): string
    {
        // 공백류 → 하이픈
        $base = preg_replace('/\s+/u', '-', trim($base));
        // 경로/제어 위험 문자 제거: / \ : * ? " < > | 및 제어문자
        $base = preg_replace('~[\\\\/:*?"<>|\x00-\x1F]+~u', '', (string) $base);
        // 하이픈 연속 축약 후 앞뒤 하이픈·점 트림
        $base = trim((string) preg_replace('/-+/u', '-', (string) $base), "-.");

        return $base !== '' ? $base : 'block';
    }
}
