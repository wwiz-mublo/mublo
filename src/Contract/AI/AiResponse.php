<?php
namespace Mublo\Contract\AI;

/** 공급자 비종속 구조화 AI 응답. */
final readonly class AiResponse
{
    public function __construct(
        private array $data,
        private string $provider,
        private string $model,
    ) {
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function getModel(): string
    {
        return $this->model;
    }
}
