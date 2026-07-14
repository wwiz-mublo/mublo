<?php
namespace Mublo\Service\AI;

use Mublo\Contract\AI\AiGatewayInterface;
use Mublo\Contract\AI\AiRequest;
use Mublo\Contract\AI\AiResponse;
use Mublo\Repository\AI\AiUsageRepository;
use Mublo\Service\AI\Provider\AiProviderFactory;

/** @internal AiGatewayInterface의 Core 구현. */
class CoreAiGateway implements AiGatewayInterface
{
    public function __construct(
        private DomainAiConfigService $configService,
        private AiUsageRepository $usage,
        private AiProviderFactory $providers,
        private AiAssetService $assets,
    ) {
    }

    public function isAvailable(int $domainId): bool
    {
        if ($domainId < 1) return false;
        try {
            $config = $this->configService->publicConfig($domainId);
            return !empty($config['is_enabled']) && !empty($config['api_key_configured']);
        } catch (\Throwable) {
            return false;
        }
    }

    public function generate(int $domainId, AiRequest $request): AiResponse
    {
        if ($domainId < 1) throw new \InvalidArgumentException('유효한 도메인 ID가 필요합니다.');
        $system = trim($request->getSystemPrompt());
        $user = trim($request->getUserPrompt());
        if ($system === '' || $user === '' || mb_strlen($system . $user) > 20000) {
            throw new \InvalidArgumentException('AI 프롬프트는 비어 있지 않아야 하며 합계 20,000자 이하여야 합니다.');
        }
        $schema = $request->getResponseSchema();
        $schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (($schema['type'] ?? null) !== 'object' || !is_array($schema['properties'] ?? null) || strlen($schemaJson) > 20000) {
            throw new \InvalidArgumentException('응답 스키마는 properties를 가진 20KB 이하의 JSON object 스키마여야 합니다.');
        }

        $runtime = $this->configService->runtimeConfig($domainId);
        $attachments = $this->assets->resolveForPrompt($domainId, $request->getAssetIds());
        $inputChars = mb_strlen($system . $user . $schemaJson)
            + array_sum(array_map(static fn (array $asset): int => mb_strlen((string) ($asset['text'] ?? '')), $attachments));
        if (!$this->usage->consume($domainId, $runtime['daily_request_limit'], $inputChars)) {
            throw new \RuntimeException('이 도메인의 오늘 AI 요청 한도를 모두 사용했습니다.');
        }

        $data = $this->providers->make($runtime['provider'])->generateStructured(
            $runtime['api_key'],
            $runtime['model'],
            $system,
            $user,
            $schema,
            $attachments,
        );
        $output = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->usage->addOutput($domainId, mb_strlen($output));

        return new AiResponse($data, $runtime['provider'], $runtime['model']);
    }
}
