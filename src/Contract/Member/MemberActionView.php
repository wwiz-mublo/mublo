<?php
declare(strict_types=1);

namespace Mublo\Contract\Member;

/** 검증·필터·상태 선택을 마친 렌더링 전용 DTO. */
final readonly class MemberActionView
{
    public function __construct(
        private string $id,
        private string $label,
        private string $icon,
        private string $endpoint,
        private MemberActionTargetTransport $targetTransport,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getIcon(): string
    {
        return $this->icon;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function getTargetTransport(): MemberActionTargetTransport
    {
        return $this->targetTransport;
    }
}
