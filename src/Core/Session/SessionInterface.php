<?php

namespace Mublo\Core\Session;

/**
 * SessionInterface
 *
 * 세션 관리자 인터페이스
 * - DIP(의존성 역전 원칙) 준수
 * - 구체적 구현(파일, DB, Redis 등)에 의존하지 않음
 * - 멀티테넌트 지원 (도메인별 세션 분리)
 */
interface SessionInterface
{
    /**
     * 세션 시작
     *
     * @param int|null $domainId 도메인 ID (멀티테넌트 분리용)
     */
    public function start(?int $domainId = null): void;

    /**
     * 세션 종료 (로그아웃 시)
     */
    public function destroy(): void;

    /**
     * 세션 ID 재생성 (로그인 후 보안)
     */
    public function regenerate(bool $deleteOldSession = true): bool;

    /**
     * 세션 값 저장
     */
    public function set(string $key, mixed $value): void;

    /**
     * 세션 값 조회
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * 세션 값 존재 여부
     */
    public function has(string $key): bool;

    /**
     * 세션 값 삭제
     */
    public function remove(string $key): void;

    /**
     * 모든 세션 데이터 조회
     */
    public function all(): array;

    /**
     * 플래시 메시지 저장 (다음 요청에서만 유효)
     */
    public function flash(string $key, mixed $value): void;

    /**
     * 플래시 메시지 조회
     */
    public function getFlash(string $key, mixed $default = null): mixed;

    /**
     * 세션 ID 조회
     */
    public function getId(): string;

    /**
     * 도메인 ID 설정
     */
    public function setDomainId(?int $domainId): void;

    /**
     * 현재 도메인 ID 반환
     */
    public function getDomainId(): ?int;
}
