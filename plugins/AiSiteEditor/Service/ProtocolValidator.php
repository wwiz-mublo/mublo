<?php
declare(strict_types=1);

namespace Mublo\Plugin\AiSiteEditor\Service;

use InvalidArgumentException;

final class ProtocolValidator
{
    /** @param array<string,mixed> $catalog */
    public function __construct(private readonly array $catalog)
    {
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function validatePlan(array $plan): array
    {
        if (($plan['schema_version'] ?? null) !== 1
            || !is_string($plan['plan_id'] ?? null)
            || preg_match('/^pln_[A-Za-z0-9_-]+$/D', $plan['plan_id']) !== 1
            || !is_int($plan['observed_site_revision'] ?? null)
            || !is_string($plan['summary'] ?? null)
            || $plan['summary'] === ''
            || !is_array($plan['operations'] ?? null)
            || $plan['operations'] === []
            || count($plan['operations']) > 50) {
            throw new ProtocolValidationException('PLAN_SCHEMA_INVALID', 'Invalid change plan.');
        }

        $seen = [];
        foreach ($plan['operations'] as $index => $operation) {
            if (!is_array($operation)
                || !is_string($operation['op_id'] ?? null)
                || preg_match('/^op_[A-Za-z0-9_-]+$/D', $operation['op_id']) !== 1
                || isset($seen[$operation['op_id']])) {
                throw new ProtocolValidationException('PLAN_SCHEMA_INVALID', 'Operation ids must be unique.', "operations.$index.op_id");
            }
            $tool = $operation['tool'] ?? null;
            if (!is_string($tool) || !isset($this->catalog[$tool])) {
                throw new ProtocolValidationException('PLAN_OPERATION_UNSUPPORTED', 'Unsupported tool.', "operations.$index.tool");
            }
            foreach ($this->references($operation['target'] ?? null) as [$path, $reference]) {
                if (preg_match('/^(op_[A-Za-z0-9_-]+)\.result(?:\.[A-Za-z_][A-Za-z0-9_]*)+$/D', $reference, $match) !== 1
                    || !isset($seen[$match[1]])) {
                    throw new ProtocolValidationException('PLAN_REFERENCE_INVALID', 'Reference must target a prior operation.', "operations.$index.target.$path");
                }
            }
            foreach ($this->references($operation['input'] ?? null) as [$path, $reference]) {
                if (preg_match('/^(op_[A-Za-z0-9_-]+)\.result\.([A-Za-z_][A-Za-z0-9_]*)$/D', $reference, $match) !== 1
                    || !isset($seen[$match[1]])) {
                    throw new ProtocolValidationException('PLAN_REFERENCE_INVALID', 'Reference must target a prior operation.', "operations.$index.input.$path");
                }
                $resultType = $seen[$match[1]]['result_schema']['properties'][$match[2]]['type'] ?? null;
                $inputField = explode('.', $path)[0];
                $expectedType = $this->catalog[$tool]['input_schema']['properties'][$inputField]['x-reference-type'] ?? null;
                if (is_string($expectedType) && $resultType !== $expectedType) {
                    throw new ProtocolValidationException('PLAN_REFERENCE_INVALID', 'Reference result type does not match input.', "operations.$index.input.$path");
                }
            }
            $seen[$operation['op_id']] = $this->catalog[$tool];
        }

        return $plan;
    }

    /** @param array<string,mixed> $plan @param array<string,int> $currentRevisions */
    public function validateGuards(array $plan, array $currentRevisions): void
    {
        foreach (($plan['guard_set'] ?? []) as $index => $guard) {
            $resourceId = is_array($guard) ? ($guard['resource_id'] ?? null) : null;
            $expected = is_array($guard) ? ($guard['expected_revision'] ?? null) : null;
            if (!is_string($resourceId) || !is_int($expected) || ($currentRevisions[$resourceId] ?? null) !== $expected) {
                throw new ProtocolValidationException('RESOURCE_REVISION_CONFLICT', 'Resource revision changed.', "guard_set.$index");
            }
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function validateSnapshot(array $snapshot): array
    {
        if (($snapshot['schema_version'] ?? null) !== 1
            || !is_int($snapshot['site_revision'] ?? null)
            || !is_array($snapshot['blocks'] ?? null)
            || !is_array($snapshot['menus'] ?? null)) {
            throw new ProtocolValidationException('PLAN_SCHEMA_INVALID', 'Invalid site snapshot.');
        }
        return $snapshot;
    }

    /** @return list<array{0:string,1:string}> */
    private function references(mixed $value, string $path = ''): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (array_keys($value) === ['$ref'] && is_string($value['$ref'])) {
            return [[$path, $value['$ref']]];
        }
        $references = [];
        foreach ($value as $key => $item) {
            $child = $path === '' ? (string) $key : "$path.$key";
            array_push($references, ...$this->references($item, $child));
        }
        return $references;
    }
}

final class ProtocolValidationException extends InvalidArgumentException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $errorPath = ''
    ) {
        parent::__construct($message);
    }
}

