<?php
declare(strict_types=1);
namespace Mublo\Service\AI\Provider;

interface AiProviderInterface
{
    /** @return array{html:string,css:string,notes:string,behavior:array{types:string[],autoplay_seconds:int,slider_preset:string}} */
    public function generate(string $apiKey, string $model, string $systemPrompt, string $userPrompt, array $attachments = []): array;

    /** @return array<string,mixed> */
    public function generateStructured(
        string $apiKey,
        string $model,
        string $systemPrompt,
        string $userPrompt,
        array $responseSchema,
        array $attachments = [],
    ): array;
}
