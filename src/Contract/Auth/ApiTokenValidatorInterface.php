<?php
declare(strict_types=1);

namespace Mublo\Contract\Auth;

/**
 * API 토큰(Bearer) 검증 계약.
 *
 * 토큰 발급·저장소를 가진 확장이 구현해 `ContractRegistry` 에 1:1 로 바인딩하고(`bind()`),
 * 앱 API 를 받는 다른 확장은 발급 확장을 직접 참조하지 않고 이 계약으로 토큰을 검증한다.
 * 예: 모바일 업무 앱이 부르는 API 를 받는 플러그인이, 토큰을 발급한 패키지를 모른 채
 * `Authorization: Bearer ...` 를 검증한다.
 *
 * 구현 확장이 설치되지 않았을 수 있으므로 소비자는 `ContractRegistry::has()` 로 먼저 확인하고,
 * 없으면 인증 실패(401)로 처리한다.
 *
 * 세션 로그인 사용자는 이 계약이 아니라 `AuthContextInterface` 로 확인한다.
 */
interface ApiTokenValidatorInterface
{
    /**
     * 원문 토큰을 검증한다.
     *
     * 만료·폐기·형식 오류는 모두 null 이다. 구현체는 어떤 경우에도 예외를 밖으로 던지지 않는다
     * — 소비자는 null 하나로 401 을 판단한다.
     *
     * @param string $rawToken `Bearer ` 접두사를 뗀 원문 토큰
     * @return array{member_id: int, domain_id: int}|null 유효하면 인증 정보(구현체가 키를 더 실을 수 있다), 아니면 null
     */
    public function validate(string $rawToken): ?array;
}
