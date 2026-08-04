<?php
declare(strict_types=1);

namespace Mublo\Core\Response;

/**
 * FileResponse
 *
 * 파일 서빙을 위한 Response 클래스
 * - 정적 파일 응답 (CSS, JS, 이미지 등)
 * - 304 Not Modified 응답
 * - 캐싱 헤더 지원
 * - Range 요청 지원 (대용량 비디오/오디오 스트리밍)
 */
class FileResponse extends AbstractResponse
{
    private ?string $filePath;
    private ?string $content;
    private int $rangeStart;
    private ?int $rangeLength;
    private bool $deleteAfterSend;

    /**
     * 청크 크기 (8KB)
     */
    private const CHUNK_SIZE = 8192;

    /**
     * @param string|null $filePath 파일 경로 (304 응답 시 null)
     * @param int $statusCode HTTP 상태 코드
     * @param array $headers HTTP 헤더
     * @param string|null $content 직접 전송할 내용 (에러 메시지 등)
     * @param int $rangeStart Range 시작 위치 (기본 0)
     * @param int|null $rangeLength Range 길이 (null이면 전체)
     * @param bool $deleteAfterSend 전송 완료 후 원본 파일 삭제 여부
     */
    public function __construct(
        ?string $filePath = null,
        int $statusCode = 200,
        array $headers = [],
        ?string $content = null,
        int $rangeStart = 0,
        ?int $rangeLength = null,
        bool $deleteAfterSend = false
    ) {
        $this->filePath = $filePath;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        $this->content = $content;
        $this->rangeStart = $rangeStart;
        $this->rangeLength = $rangeLength;
        $this->deleteAfterSend = $deleteAfterSend;
    }

    /**
     * 파일 경로 반환
     */
    public function getFilePath(): ?string
    {
        return $this->filePath;
    }

    /**
     * 직접 전송할 내용 반환
     */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * 응답 전송
     */
    public function send(): void
    {
        try {
            // 상태 코드 전송
            http_response_code($this->statusCode);

            // 헤더 전송
            foreach ($this->headers as $name => $value) {
                header("$name: $value");
            }

            // 304 응답이면 바디 없음
            if ($this->statusCode === 304) {
                return;
            }

            // 직접 내용이 있으면 출력
            if ($this->content !== null) {
                echo $this->content;
                return;
            }

            // 파일 내용 출력
            if ($this->filePath !== null && is_file($this->filePath)) {
                $this->sendFile();
            }
        } finally {
            if ($this->deleteAfterSend && $this->filePath !== null && is_file($this->filePath)) {
                @unlink($this->filePath);
            }
        }
    }

    /**
     * 파일 전송 (청크 단위, Range 지원)
     * 메모리 효율적인 대용량 파일 전송
     */
    private function sendFile(): void
    {
        // 출력 버퍼 정리 (메모리 확보)
        while (ob_get_level()) {
            ob_end_clean();
        }

        $fp = fopen($this->filePath, 'rb');
        if ($fp === false) {
            return;
        }

        // Range 시작 위치로 이동
        if ($this->rangeStart > 0) {
            fseek($fp, $this->rangeStart);
        }

        // 전송할 바이트 수 결정
        $remaining = $this->rangeLength ?? (filesize($this->filePath) - $this->rangeStart);

        // 청크 단위로 읽어서 전송
        while (!feof($fp) && $remaining > 0) {
            $readSize = min(self::CHUNK_SIZE, $remaining);
            $data = fread($fp, $readSize);

            if ($data === false) {
                break;
            }

            echo $data;
            flush();

            $remaining -= strlen($data);

            // 클라이언트 연결 끊김 확인
            if (connection_aborted()) {
                break;
            }
        }

        fclose($fp);
    }
}
