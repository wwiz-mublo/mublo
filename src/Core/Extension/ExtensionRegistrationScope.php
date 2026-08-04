<?php
declare(strict_types=1);
namespace Mublo\Core\Extension;

/**
 * Provider가 코어 런타임에 등록한 상태를 확장 단위로 추적한다.
 *
 * @internal ExtensionManager와 코어 registry/container에서만 사용한다.
 */
final class ExtensionRegistrationScope
{
    private static ?string $activeOwner = null;

    /** @var array<string, list<\Closure(): void>> */
    private static array $undoCallbacks = [];

    /** @var array<string, list<\Closure(): void>> */
    private static array $commitCallbacks = [];

    public static function begin(string $owner): void
    {
        if (self::$activeOwner !== null) {
            throw new \LogicException("Extension registration scope is already active: " . self::$activeOwner);
        }

        if (isset(self::$undoCallbacks[$owner])) {
            throw new \LogicException("Extension registration scope already exists: {$owner}");
        }

        self::$undoCallbacks[$owner] = [];
        self::$commitCallbacks[$owner] = [];
        self::$activeOwner = $owner;
    }

    public static function resume(string $owner): void
    {
        if (self::$activeOwner !== null) {
            throw new \LogicException("Extension registration scope is already active: " . self::$activeOwner);
        }

        if (!isset(self::$undoCallbacks[$owner])) {
            throw new \LogicException("Extension registration scope does not exist: {$owner}");
        }

        self::$activeOwner = $owner;
    }

    public static function suspend(string $owner): void
    {
        if (self::$activeOwner !== $owner) {
            throw new \LogicException("Extension registration scope is not active: {$owner}");
        }

        self::$activeOwner = null;
    }

    public static function currentOwner(): ?string
    {
        return self::$activeOwner;
    }

    public static function has(string $owner): bool
    {
        return isset(self::$undoCallbacks[$owner]);
    }

    public static function recordUndo(\Closure $callback): void
    {
        $owner = self::$activeOwner;
        if ($owner === null) {
            return;
        }

        self::$undoCallbacks[$owner][] = $callback;
    }

    public static function recordCommit(\Closure $callback): void
    {
        $owner = self::$activeOwner;
        if ($owner === null) {
            return;
        }

        self::$commitCallbacks[$owner][] = $callback;
    }

    public static function commit(string $owner): void
    {
        if (self::$activeOwner === $owner) {
            self::$activeOwner = null;
        }

        $callbacks = self::$commitCallbacks[$owner] ?? [];
        unset(self::$undoCallbacks[$owner], self::$commitCallbacks[$owner]);

        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    /**
     * @return list<\Throwable> rollback 콜백 자체의 실패 목록
     */
    public static function rollback(string $owner): array
    {
        if (self::$activeOwner === $owner) {
            self::$activeOwner = null;
        }

        $callbacks = self::$undoCallbacks[$owner] ?? [];
        unset(self::$undoCallbacks[$owner], self::$commitCallbacks[$owner]);

        $errors = [];
        foreach (array_reverse($callbacks) as $callback) {
            try {
                $callback();
            } catch (\Throwable $e) {
                $errors[] = $e;
            }
        }

        return $errors;
    }

    /** 테스트와 요청 경계의 방어적 초기화용. */
    public static function reset(): void
    {
        self::$activeOwner = null;
        self::$undoCallbacks = [];
        self::$commitCallbacks = [];
    }
}
