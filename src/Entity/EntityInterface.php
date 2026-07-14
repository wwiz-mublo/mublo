<?php
namespace Mublo\Entity;

/**
 * EntityInterface
 *
 * 모든 Entity가 구현해야 하는 표준 인터페이스
 *
 * 책임:
 * - 일관된 Entity 생성 및 변환 메서드
 * - 타임스탬프 관리
 * - 직렬화 지원
 */
interface EntityInterface
{
    /**
     * 배열 데이터에서 Entity 객체 생성
     */
    public static function fromArray(array $data): self;

    /**
     * Entity를 배열로 변환
     */
    public function toArray(): array;

    /**
     * 기본키 값 반환
     */
    public function getPrimaryKey(): int|string;

    /**
     * 생성 타임스탬프 반환
     */
    public function getCreatedAt(): ?string;

    /**
     * 수정 타임스탬프 반환
     */
    public function getUpdatedAt(): ?string;

    /**
     * Entity 검증
     */
    public function validate(): array; // 에러 배열 반환
}
