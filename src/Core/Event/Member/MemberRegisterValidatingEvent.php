<?php

namespace Mublo\Core\Event\Member;

use Mublo\Core\Context\Context;
use Mublo\Core\Event\AbstractEvent;
use Mublo\Core\Event\FailFastEventInterface;

/**
 * 회원가입 검증 확장 이벤트
 *
 * Plugin이 가입 데이터를 검증할 수 있는 확장점.
 * Front/MemberController::register()에서 Core 기본 검증 후 발행.
 *
 * 설계 원칙:
 * - addError()는 에러만 쌓고, 나머지 플러그인도 계속 실행됨
 * - 사용자에게 에러를 한 번에 표시하기 위함
 * - 치명적 상황(봇 탐지 등)에서만 stopPropagation() 명시 호출
 *
 * 사용 예 (ReferralPlugin):
 * ```php
 * public function validateReferral(MemberRegisterValidatingEvent $event): void
 * {
 *     $code = $event->get('plugin_referral_code');
 *     if (!empty($code) && !$this->referralService->exists($code)) {
 *         $event->addError('추천인 코드가 올바르지 않습니다.');
 *     }
 * }
 * ```
 */
class MemberRegisterValidatingEvent extends AbstractEvent implements FailFastEventInterface
{
    private array $data;
    private Context $context;

    /** @var string[] */
    private array $errors = [];

    /**
     * @param array $data 가입 폼 데이터
     * @param Context $context 현재 컨텍스트
     */
    public function __construct(array $data, Context $context)
    {
        $this->data = $data;
        $this->context = $context;
    }

    public function getData(): array
    {
        return $this->data;
    }

    /**
     * 특정 키의 값 반환
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getContext(): Context
    {
        return $this->context;
    }

    /**
     * 검증 에러 추가
     *
     * 전파는 중단되지 않음 — 다른 플러그인의 검증도 계속 실행됨.
     * 치명적 상황에서만 별도로 $this->stopPropagation() 호출.
     */
    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
