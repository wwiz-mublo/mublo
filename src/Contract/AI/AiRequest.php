<?php
namespace Mublo\Contract\AI;

/** 구조화된 AI 요청 값 객체. */
final readonly class AiRequest
{
    public function __construct(
        private string $systemPrompt,
        private string $userPrompt,
        private array $responseSchema,
        private array $assetIds = [],
    ) {
    }

    public function getSystemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function getUserPrompt(): string
    {
        return $this->userPrompt;
    }

    public function getResponseSchema(): array
    {
        return $this->responseSchema;
    }

    /** @return int[] */
    public function getAssetIds(): array
    {
        return $this->assetIds;
    }
}
