<?php
namespace Mublo\Entity;

/**
 * BaseEntity
 *
 * 모든 Entity의 기본 클래스
 *
 * 책임:
 * - 공통 타임스탐프 필드 관리
 * - 기본 메서드 (toArray, fromArray, validate 등)
 * - 속성 동적 접근
 *
 * 사용:
 * class Post extends BaseEntity {
 *     protected int $postId;
 *     protected string $title;
 *     
 *     protected function getPrimaryKeyField(): string {
 *         return 'post_id';
 *     }
 * }
 */
abstract class BaseEntity implements EntityInterface
{
    protected string $createdAt = '';
    protected ?string $updatedAt = null;

    /**
     * 기본키 필드명 (오버라이드 필수)
     */
    abstract protected function getPrimaryKeyField(): string;

    /**
     * 기본키 값 반환
     */
    public function getPrimaryKey(): int|string
    {
        $field = $this->getPrimaryKeyField();
        return $this->{$field} ?? null;
    }

    /**
     * 생성 타임스탬프 반환
     */
    public function getCreatedAt(): ?string
    {
        return $this->createdAt ?? null;
    }

    /**
     * 수정 타임스탬프 반환
     */
    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    /**
     * 타임스탬프 설정
     */
    protected function setTimestamps(string $createdAt = '', ?string $updatedAt = null): void
    {
        if (!empty($createdAt)) {
            $this->createdAt = $createdAt;
        }
        if ($updatedAt !== null) {
            $this->updatedAt = $updatedAt;
        }
    }

    /**
     * Entity 검증 (기본: 항상 성공)
     * 자식 클래스에서 오버라이드
     */
    public function validate(): array
    {
        return [];
    }

    /**
     * 검증 수행 및 예외 발생
     */
    public function validateOrFail(): void
    {
        $errors = $this->validate();
        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                'Entity validation failed: ' . json_encode($errors)
            );
        }
    }

    /**
     * 마술 메서드: 속성 동적 접근
     */
    public function __get(string $name): mixed
    {
        if (property_exists($this, $name)) {
            return $this->{$name};
        }
        return null;
    }

    /**
     * 마술 메서드: 속성 동적 설정
     */
    public function __set(string $name, mixed $value): void
    {
        if (property_exists($this, $name)) {
            $this->{$name} = $value;
        }
    }

    /**
     * 마술 메서드: 속성 존재 확인
     */
    public function __isset(string $name): bool
    {
        return property_exists($this, $name) && isset($this->{$name});
    }

    /**
     * JSON 직렬화 지원
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
