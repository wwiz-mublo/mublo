<?php
namespace Mublo\Core\Extension;

/**
 * Request-scoped extension loading diagnostics.
 *
 * 확장 로딩 실패를 구조화해서 로그와 관리자 화면에서 함께 사용할 수 있게 한다.
 */
class ExtensionLoadDiagnostics
{
    /**
     * @var array<int, array{
     *     type: string,
     *     name: string,
     *     stage: string,
     *     message: string,
     *     exception: string,
     *     file: string,
     *     line: int
     * }>
     */
    private array $failures = [];

    public function clear(): void
    {
        $this->failures = [];
    }

    public function record(string $type, string $name, string $stage, \Throwable $e): void
    {
        $this->failures[] = [
            'type' => $type,
            'name' => $name,
            'stage' => $stage,
            'message' => $e->getMessage(),
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    public function hasFailures(): bool
    {
        return $this->failures !== [];
    }

    public function count(): int
    {
        return count($this->failures);
    }

    /**
     * @return array<int, array{type: string, name: string, stage: string, message: string, exception: string, file: string, line: int}>
     */
    public function all(): array
    {
        return $this->failures;
    }
}
