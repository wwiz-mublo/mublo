<?php

namespace Mublo\Core\Cookie;

/**
 * CookieInterface
 *
 * 쿠키 관리자 인터페이스
 * - DIP(의존성 역전 원칙) 준수
 * - 구체적 구현에 의존하지 않음
 */
interface CookieInterface
{
    /**
     * 쿠키 설정
     *
     * @param string $name 쿠키 이름
     * @param string $value 쿠키 값
     * @param int $minutes 유효 시간 (분), 0이면 세션 쿠키
     * @param string $path 경로
     * @param string $domain 도메인
     * @param bool $secure HTTPS 전용
     * @param bool $httpOnly JavaScript 접근 차단
     * @param string $sameSite SameSite 속성 (Strict, Lax, None)
     */
    public function set(
        string $name,
        string $value,
        int $minutes = 0,
        string $path = '/',
        string $domain = '',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): void;

    /**
     * 쿠키 값 조회
     *
     * @param string $name 쿠키 이름
     * @param mixed $default 기본값
     * @return mixed
     */
    public function get(string $name, mixed $default = null): mixed;

    /**
     * 쿠키 존재 여부
     */
    public function has(string $name): bool;

    /**
     * 쿠키 삭제
     */
    public function delete(string $name, string $path = '/', string $domain = ''): void;

    /**
     * 영구 쿠키 설정 (1년)
     */
    public function forever(string $name, string $value): void;

    /**
     * 모든 쿠키 조회
     */
    public function all(): array;
}
