<?php
namespace Mublo\Infrastructure\Storage;

/**
 * StorageInterface
 *
 * 스토리지 추상화 인터페이스
 * - 로컬, S3, 기타 스토리지 통합 인터페이스
 */
interface StorageInterface
{
    /**
     * 파일 저장
     *
     * @param string $path 저장 경로 (상대 경로)
     * @param string $contents 파일 내용
     * @return bool
     */
    public function put(string $path, string $contents): bool;

    /**
     * 스트림으로 파일 저장
     *
     * @param string $path 저장 경로
     * @param resource $resource 파일 리소스
     * @return bool
     */
    public function putStream(string $path, $resource): bool;

    /**
     * 파일 읽기
     *
     * @param string $path 파일 경로
     * @return string|null
     */
    public function get(string $path): ?string;

    /**
     * 스트림으로 파일 읽기
     *
     * @param string $path 파일 경로
     * @return resource|null
     */
    public function getStream(string $path);

    /**
     * 파일 삭제
     *
     * @param string $path 파일 경로
     * @return bool
     */
    public function delete(string $path): bool;

    /**
     * 파일 존재 여부
     *
     * @param string $path 파일 경로
     * @return bool
     */
    public function exists(string $path): bool;

    /**
     * 파일 복사
     *
     * @param string $from 원본 경로
     * @param string $to 대상 경로
     * @return bool
     */
    public function copy(string $from, string $to): bool;

    /**
     * 파일 이동
     *
     * @param string $from 원본 경로
     * @param string $to 대상 경로
     * @return bool
     */
    public function move(string $from, string $to): bool;

    /**
     * 파일 크기
     *
     * @param string $path 파일 경로
     * @return int|null
     */
    public function size(string $path): ?int;

    /**
     * 파일 수정 시간
     *
     * @param string $path 파일 경로
     * @return int|null Unix timestamp
     */
    public function lastModified(string $path): ?int;

    /**
     * 파일 URL 반환
     *
     * @param string $path 파일 경로
     * @return string
     */
    public function url(string $path): string;

    /**
     * 디렉토리 내 파일 목록
     *
     * @param string $directory 디렉토리 경로
     * @param bool $recursive 재귀 검색 여부
     * @return array
     */
    public function files(string $directory, bool $recursive = false): array;

    /**
     * 디렉토리 생성
     *
     * @param string $path 디렉토리 경로
     * @return bool
     */
    public function makeDirectory(string $path): bool;

    /**
     * 디렉토리 삭제
     *
     * @param string $directory 디렉토리 경로
     * @return bool
     */
    public function deleteDirectory(string $directory): bool;
}
