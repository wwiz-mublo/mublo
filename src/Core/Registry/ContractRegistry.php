<?php

namespace Mublo\Core\Registry;

use Closure;  // instanceof 체크용
use Mublo\Core\Extension\ExtensionRegistrationScope;

/**
 * 범용 계약 레지스트리
 *
 * Plugin/Package가 표준 인터페이스의 구현체를 등록하고,
 * 다른 계층에서 인터페이스만으로 구현체를 조회할 수 있게 한다.
 *
 * - 1:1 바인딩 (bind/resolve): 단일 제공자 (본인인증, SMS 등)
 * - 1:N 등록 (register/get): 복수 제공자 (PG사, 소셜로그인 등)
 * - 메타데이터: 인스턴스 없이 목록/검색 가능 (lazy와 시너지)
 */
class ContractRegistry
{
    /** @var array<string, array<string, string>> 계약별 메타 필수 키/타입 */
    private const REQUIRED_META_SCHEMAS = [
        'Mublo\\Contract\\Notification\\NotificationGatewayInterface' => [
            'label' => 'string',
            'channels' => 'array',
        ],
        'Mublo\\Contract\\Payment\\PaymentGatewayInterface' => [
            'label' => 'string',
        ],
        'Mublo\\Contract\\Identity\\IdentityVerificationInterface' => [
            'label' => 'string',
        ],
    ];

    /** @var array<string, object> 1:1 바인딩 [계약 FQCN => 구현체|팩토리] */
    private array $bindings = [];

    /** @var array<string, string> */
    private array $bindingOwners = [];

    /** @var array<string, array<string, object>> 1:N 등록 [계약 FQCN => [키 => 구현체|팩토리]] */
    private array $registrations = [];

    /** @var array<string, array<string, string>> */
    private array $registrationOwners = [];

    /** @var array<string, array<string, array>> 메타데이터 [계약 FQCN => [키 => 메타 배열]] */
    private array $metadata = [];

    // ─────────────────────────────────────────
    // 1:1 바인딩 (단일 제공자)
    // ─────────────────────────────────────────

    /**
     * 계약에 단일 구현체 바인딩
     *
     * Closure를 전달하면 resolve() 시점에 lazy 생성한다.
     * 이미 바인딩된 계약에 재바인딩 시 예외를 던진다.
     *
     * @param string $contract 인터페이스 FQCN
     * @param object $implementation 구현체 인스턴스 또는 팩토리
     * @throws \InvalidArgumentException Closure가 아닌데 인터페이스 미구현 시
     * @throws DuplicateRegistryException 이미 바인딩된 계약에 재바인딩 시
     */
    public function bind(string $contract, object $implementation): void
    {
        if (!($implementation instanceof Closure) && !($implementation instanceof $contract)) {
            throw new \InvalidArgumentException(
                get_class($implementation) . " must implement {$contract}"
            );
        }

        if (isset($this->bindings[$contract])) {
            throw new DuplicateRegistryException(
                "Contract '{$contract}' is already bound to " .
                ($this->bindings[$contract] instanceof Closure
                    ? 'Closure'
                    : get_class($this->bindings[$contract]))
            );
        }

        $this->bindings[$contract] = $implementation;

        $owner = ExtensionRegistrationScope::currentOwner();
        if ($owner !== null) {
            $this->bindingOwners[$contract] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($contract, $owner): void {
                if (($this->bindingOwners[$contract] ?? null) === $owner) {
                    unset($this->bindingOwners[$contract]);
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use ($contract, $owner): void {
                if (($this->bindingOwners[$contract] ?? null) === $owner) {
                    unset($this->bindings[$contract], $this->bindingOwners[$contract]);
                }
            });
        }
    }

    /**
     * 바인딩된 구현체 조회 (lazy resolve)
     *
     * Closure가 등록되어 있으면 실행하여 인스턴스화하고 캐싱한다.
     *
     * @throws RegistryNotFoundException 미등록 시
     * @throws \InvalidArgumentException Closure 반환값이 인터페이스 미구현 시
     */
    public function resolve(string $contract): object
    {
        if (!isset($this->bindings[$contract])) {
            throw new RegistryNotFoundException("No binding for contract: {$contract}");
        }

        if ($this->bindings[$contract] instanceof Closure) {
            $instance = ($this->bindings[$contract])();
            if (!($instance instanceof $contract)) {
                throw new \InvalidArgumentException(
                    get_class($instance) . " must implement {$contract}"
                );
            }
            $this->bindings[$contract] = $instance;
        }

        return $this->bindings[$contract];
    }

    /**
     * 바인딩 존재 여부
     */
    public function has(string $contract): bool
    {
        return isset($this->bindings[$contract]);
    }

    // ─────────────────────────────────────────
    // 1:N 등록 (복수 제공자)
    // ─────────────────────────────────────────

    /**
     * 계약에 키 기반 구현체 등록
     *
     * Closure를 전달하면 get() 시점에 lazy 생성한다.
     * $meta에 discovery용 정보를 담으면 인스턴스 없이 목록/검색 가능.
     *
     * @param string $contract 인터페이스 FQCN
     * @param string $key 고유 키 (예: 'tosspay', 'nicepay')
     * @param object $implementation 구현체 인스턴스 또는 팩토리
     * @param array $meta 인스턴스 없이 조회 가능한 메타 정보
     * @throws \InvalidArgumentException Closure가 아닌데 인터페이스 미구현 시
     * @throws DuplicateRegistryException 키 중복 시
     */
    public function register(
        string $contract,
        string $key,
        object $implementation,
        array $meta = [],
    ): void {
        if (!($implementation instanceof Closure) && !($implementation instanceof $contract)) {
            throw new \InvalidArgumentException(
                get_class($implementation) . " must implement {$contract}"
            );
        }

        if (isset($this->registrations[$contract][$key])) {
            throw new DuplicateRegistryException(
                "Contract '{$contract}' already has registration for key '{$key}'"
            );
        }

        if (!empty($meta)) {
            $this->warnInvalidMeta($contract, $key, $meta);
        }

        $this->registrations[$contract][$key] = $implementation;
        if (!empty($meta)) {
            $this->metadata[$contract][$key] = $meta;
        }

        $owner = ExtensionRegistrationScope::currentOwner();
        if ($owner !== null) {
            $this->registrationOwners[$contract][$key] = $owner;
            ExtensionRegistrationScope::recordCommit(function () use ($contract, $key, $owner): void {
                if (($this->registrationOwners[$contract][$key] ?? null) === $owner) {
                    unset($this->registrationOwners[$contract][$key]);
                    if (empty($this->registrationOwners[$contract])) {
                        unset($this->registrationOwners[$contract]);
                    }
                }
            });
            ExtensionRegistrationScope::recordUndo(function () use ($contract, $key, $owner): void {
                if (($this->registrationOwners[$contract][$key] ?? null) !== $owner) {
                    return;
                }

                unset(
                    $this->registrations[$contract][$key],
                    $this->metadata[$contract][$key],
                    $this->registrationOwners[$contract][$key]
                );
                if (empty($this->registrations[$contract])) {
                    unset($this->registrations[$contract]);
                }
                if (empty($this->metadata[$contract])) {
                    unset($this->metadata[$contract]);
                }
                if (empty($this->registrationOwners[$contract])) {
                    unset($this->registrationOwners[$contract]);
                }
            });
        }
    }

    /**
     * 특정 키의 구현체 조회 (lazy resolve)
     *
     * Closure가 등록되어 있으면 실행하여 인스턴스화하고 캐싱한다.
     *
     * @throws RegistryNotFoundException
     * @throws \InvalidArgumentException Closure 반환값이 인터페이스 미구현 시
     */
    public function get(string $contract, string $key): object
    {
        if (!isset($this->registrations[$contract][$key])) {
            throw new RegistryNotFoundException(
                "No registration for contract '{$contract}' with key '{$key}'"
            );
        }

        if ($this->registrations[$contract][$key] instanceof Closure) {
            $instance = ($this->registrations[$contract][$key])();
            if (!($instance instanceof $contract)) {
                throw new \InvalidArgumentException(
                    get_class($instance) . " must implement {$contract}"
                );
            }
            $this->registrations[$contract][$key] = $instance;
        }

        return $this->registrations[$contract][$key];
    }

    /**
     * 계약의 모든 등록 항목 조회 (미resolve 상태)
     *
     * 주의: Closure로 등록된 항목은 callable 그대로 반환된다.
     * 실제 인스턴스가 필요하면 get()으로 개별 조회할 것.
     * 반환 타입은 object|Closure 혼합이므로 instanceof 검사 후 사용.
     *
     * @return array<string, object|Closure> key → 등록 값 (미resolve)
     */
    public function all(string $contract): array
    {
        return $this->registrations[$contract] ?? [];
    }

    /**
     * 계약에 등록된 키 목록
     *
     * @return string[]
     */
    public function keys(string $contract): array
    {
        return array_keys($this->registrations[$contract] ?? []);
    }

    /**
     * 특정 키 등록 여부
     */
    public function hasKey(string $contract, string $key): bool
    {
        return isset($this->registrations[$contract][$key]);
    }

    // ─────────────────────────────────────────
    // 메타데이터 조회 (인스턴스 resolve 없이)
    // ─────────────────────────────────────────

    /**
     * 특정 키의 메타 정보 조회
     *
     * @return array 메타 배열 (미등록 시 빈 배열)
     */
    public function getMeta(string $contract, string $key): array
    {
        return $this->metadata[$contract][$key] ?? [];
    }

    /**
     * 계약의 모든 메타 정보 조회
     *
     * 관리자 목록 페이지에서 인스턴스 없이 라벨/아이콘 등을 표시할 때 사용.
     * Closure를 resolve하지 않으므로 lazy 등록의 이점을 유지한다.
     *
     * @return array<string, array>
     */
    public function allMeta(string $contract): array
    {
        return $this->metadata[$contract] ?? [];
    }

    /**
     * 계약별 메타 스키마 경고 검증
     *
     * 운영 중 즉시 장애를 만들지 않기 위해 예외 대신 warning 로그를 남긴다.
     */
    private function warnInvalidMeta(string $contract, string $key, array $meta): void
    {
        $required = self::REQUIRED_META_SCHEMAS[$contract] ?? null;
        if ($required === null) {
            return;
        }

        $issues = [];
        foreach ($required as $metaKey => $expectedType) {
            if (!array_key_exists($metaKey, $meta)) {
                $issues[] = "missing '{$metaKey}'";
                continue;
            }

            $value = $meta[$metaKey];
            $actualType = gettype($value);
            if ($actualType !== $expectedType) {
                $issues[] = "invalid type for '{$metaKey}' (expected {$expectedType}, got {$actualType})";
            }
        }

        if (!empty($issues)) {
            trigger_error(
                sprintf(
                    'ContractRegistry meta warning [%s:%s] %s',
                    $contract,
                    $key,
                    implode(', ', $issues)
                ),
                E_USER_WARNING
            );
        }
    }
}
