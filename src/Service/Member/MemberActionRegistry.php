<?php
declare(strict_types=1);

namespace Mublo\Service\Member;

use Mublo\Contract\Member\MemberActionDefinition;
use Mublo\Contract\Member\MemberActionStateResolverInterface;
use Mublo\Contract\Member\MemberActionTargetTransport;
use Mublo\Contract\Member\MemberActionVariant;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Event\Member\MemberActionBuildingEvent;

/** 회원 액션 정의를 도메인별로 한 번 수집하고 검증한다. */
final class MemberActionRegistry
{
    /** @var array<int, array{definitions:array<string,array>, resolvers:array<string,MemberActionStateResolverInterface>, diagnostics:list<array>}> */
    private array $cache = [];

    /** @var callable(string,array<string,mixed>):void|null */
    private $logger;

    public function __construct(
        private EventDispatcher $events,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    /**
     * @return array{
     *   definitions:array<string,array{id:string,sourceId:string,sourceType:string,sourceName:string,definition:MemberActionDefinition}>,
     *   resolvers:array<string,MemberActionStateResolverInterface>,
     *   diagnostics:list<array<string,mixed>>
     * }
     */
    public function collect(int $domainId): array
    {
        if ($domainId <= 0) {
            return ['definitions' => [], 'resolvers' => [], 'diagnostics' => []];
        }
        if (isset($this->cache[$domainId])) {
            return $this->cache[$domainId];
        }

        $event = $this->events->dispatch(new MemberActionBuildingEvent($domainId));
        if (!$event instanceof MemberActionBuildingEvent) {
            return ['definitions' => [], 'resolvers' => [], 'diagnostics' => []];
        }

        $definitions = [];
        $resolvers = [];
        $diagnostics = [];

        foreach ($event->getDefinitions() as $registration) {
            $definition = $registration['definition'];
            $sourceId = $this->sourceId($registration['sourceType'], $registration['sourceName']);
            $id = $sourceId === null ? null : $this->actionId($sourceId, $definition->key);
            $failure = $this->validateDefinition($registration['sourceType'], $registration['sourceName'], $definition);

            if ($sourceId === null || $id === null) {
                $failure ??= 'invalid source or action key';
            } elseif (isset($definitions[$id])) {
                $failure = 'duplicate action id';
            }

            if ($failure !== null) {
                $diagnostics[] = $this->diagnostic($registration, $definition->key, $failure);
                continue;
            }

            $definitions[$id] = [
                'id' => $id,
                'sourceId' => $sourceId,
                'sourceType' => $registration['sourceType'],
                'sourceName' => $registration['sourceName'],
                'definition' => $definition,
            ];
        }

        foreach ($event->getStateResolvers() as $registration) {
            $sourceId = $this->sourceId($registration['sourceType'], $registration['sourceName']);
            if ($sourceId === null) {
                $diagnostics[] = $this->diagnostic($registration, '', 'invalid resolver source');
                continue;
            }
            if (isset($resolvers[$sourceId])) {
                $diagnostics[] = $this->diagnostic($registration, '', 'duplicate state resolver');
                continue;
            }
            $resolvers[$sourceId] = $registration['resolver'];
        }

        foreach ($definitions as $entry) {
            if ($entry['definition']->stateful && !isset($resolvers[$entry['sourceId']])) {
                $diagnostics[] = $this->diagnostic($entry, $entry['definition']->key, 'missing state resolver');
            }
        }

        uasort($definitions, static function (array $left, array $right): int {
            return [$left['definition']->priority, $left['id']]
                <=> [$right['definition']->priority, $right['id']];
        });

        foreach ($diagnostics as $diagnostic) {
            $this->log('member_action.definition_rejected', $diagnostic);
        }

        return $this->cache[$domainId] = [
            'definitions' => $definitions,
            'resolvers' => $resolvers,
            'diagnostics' => $diagnostics,
        ];
    }

    /** @param array<string,mixed> $context */
    public function diagnoseRuntime(string $reason, array $context = []): void
    {
        unset($context['targetMemberId'], $context['targetMemberIds']);
        $this->log('member_action.resolve_failed', ['reason' => $reason, ...$context]);
    }

    /** @return list<array<string,mixed>> */
    public function diagnostics(int $domainId): array
    {
        return $this->collect($domainId)['diagnostics'];
    }

    private function sourceId(string $type, string $name): ?string
    {
        if (!in_array($type, ['core', 'plugin', 'package'], true)) {
            return null;
        }
        if ($type === 'core') {
            return $name === '' || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/', $name) === 1
                ? 'core'
                : null;
        }
        if (preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/', $name) !== 1) {
            return null;
        }
        return $type . ':' . $name;
    }

    private function actionId(string $sourceId, string $key): ?string
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/', $key) === 1
            ? $sourceId . ':' . $key
            : null;
    }

    private function validateDefinition(string $sourceType, string $sourceName, MemberActionDefinition $definition): ?string
    {
        if ($this->sourceId($sourceType, $sourceName) === null || $this->actionId('source', $definition->key) === null) {
            return 'invalid source or action key';
        }
        if ($definition->key === MemberActionVariant::DEFAULT || $definition->key === MemberActionVariant::HIDDEN) {
            return 'reserved action key';
        }
        if (trim($definition->label) === '' || mb_strlen($definition->label) > 100) {
            return 'invalid label';
        }
        if ($definition->priority < -10000 || $definition->priority > 10000) {
            return 'priority out of range';
        }
        if ($definition->minViewerLevel < 0) {
            return 'minViewerLevel must not be negative';
        }
        if (count($definition->placements) > 20) {
            return 'too many placements';
        }
        foreach ($definition->placements as $placement) {
            if (!is_string($placement)
                || strlen($placement) > 64
                || preg_match('/\A[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*\z/', $placement) !== 1
            ) {
                return 'invalid placement';
            }
        }
        if (!$this->validIcon($definition->icon)) {
            return 'invalid icon';
        }
        if (!$this->validEndpoint($definition->endpoint, $definition->targetTransport)) {
            return 'invalid endpoint';
        }
        if (!$definition->stateful && $definition->variants !== []) {
            return 'static action cannot declare variants';
        }
        if (!in_array($definition->onResolveFailure, [MemberActionVariant::DEFAULT, MemberActionVariant::HIDDEN], true)) {
            return 'invalid resolve failure policy';
        }
        if (!$definition->stateful && $definition->onResolveFailure === MemberActionVariant::HIDDEN) {
            return 'static action cannot hide on resolve failure';
        }
        foreach ($definition->variants as $key => $variant) {
            if (!is_string($key)
                || in_array($key, [MemberActionVariant::DEFAULT, MemberActionVariant::HIDDEN], true)
                || preg_match('/\A[A-Za-z][A-Za-z0-9._-]{0,63}\z/', $key) !== 1
                || !$variant instanceof MemberActionVariant
            ) {
                return 'invalid variant';
            }
            if (trim($variant->label) === '' || mb_strlen($variant->label) > 100
                || !$this->validIcon($variant->icon)
                || !$this->validEndpoint($variant->endpoint, $definition->targetTransport)
            ) {
                return 'invalid variant values';
            }
        }

        return null;
    }

    private function validIcon(string $icon): bool
    {
        return $icon === '' || (strlen($icon) <= 100
            && preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]*(?: [a-zA-Z][a-zA-Z0-9_-]*)*\z/', $icon) === 1);
    }

    private function validEndpoint(string $endpoint, MemberActionTargetTransport $transport): bool
    {
        if ($endpoint === '' || strlen($endpoint) > 255 || $endpoint[0] !== '/' || str_starts_with($endpoint, '//')) {
            return false;
        }
        if (preg_match('/[\\\\?#{}\x00-\x1F\x7F]/', $endpoint) === 1
            || preg_match('/[0-9a-f]{22}/', $endpoint) === 1
            || preg_match('#(?:\A|/)\.{1,2}(?:/|\z)#', $endpoint) === 1
        ) {
            return false;
        }
        if ($transport === MemberActionTargetTransport::PublicPath && str_ends_with($endpoint, '/')) {
            return false;
        }
        return preg_match('#\A/[A-Za-z0-9._~!$&\'()*+,;=:@/-]+\z#', $endpoint) === 1;
    }

    /** @param array<string,mixed> $registration @return array<string,mixed> */
    private function diagnostic(array $registration, string $key, string $reason): array
    {
        return [
            'sourceType' => (string) ($registration['sourceType'] ?? ''),
            'sourceName' => (string) ($registration['sourceName'] ?? ''),
            'actionKey' => $key,
            'reason' => $reason,
        ];
    }

    /** @param array<string,mixed> $context */
    private function log(string $message, array $context): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $context);
        }
    }
}
