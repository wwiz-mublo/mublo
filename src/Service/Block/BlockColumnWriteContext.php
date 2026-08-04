<?php
declare(strict_types=1);

namespace Mublo\Service\Block;

use InvalidArgumentException;

final readonly class BlockColumnWriteContext
{
    public const SOURCE_INTERACTIVE = 'interactive';
    public const SOURCE_PREVIEW = 'preview';
    public const SOURCE_KIT_IMPORT = 'kit_import';
    public const SOURCE_INTERNAL_SEED = 'internal_seed';

    private const SOURCES = [
        self::SOURCE_INTERACTIVE,
        self::SOURCE_PREVIEW,
        self::SOURCE_KIT_IMPORT,
        self::SOURCE_INTERNAL_SEED,
    ];

    public function __construct(
        public int $domainId,
        public string $source,
        public bool $allowRawJs = false,
        public bool $allowInclude = false,
        public bool $allowUnresolvedExtension = false
    ) {
        if ($domainId < 0) {
            throw new InvalidArgumentException('domainId는 0 이상이어야 합니다.');
        }

        if (!in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("지원하지 않는 칸 쓰기 source입니다: {$source}");
        }
    }

    public static function interactive(
        int $domainId,
        bool $allowRawJs = false,
        bool $allowInclude = false
    ): self {
        return new self($domainId, self::SOURCE_INTERACTIVE, $allowRawJs, $allowInclude);
    }

    public static function preview(
        int $domainId,
        bool $allowRawJs = false,
        bool $allowInclude = false
    ): self {
        return new self($domainId, self::SOURCE_PREVIEW, $allowRawJs, $allowInclude);
    }

    public static function kitImport(int $domainId = 0): self
    {
        return new self(
            $domainId,
            self::SOURCE_KIT_IMPORT,
            allowRawJs: true,
            allowInclude: false,
            allowUnresolvedExtension: true
        );
    }

    public static function internalSeed(int $domainId): self
    {
        return new self(
            $domainId,
            self::SOURCE_INTERNAL_SEED,
            allowRawJs: true,
            allowInclude: true,
            allowUnresolvedExtension: true
        );
    }

    public static function revisionRestore(int $domainId, bool $allowInclude = false): self
    {
        return new self(
            $domainId,
            self::SOURCE_INTERACTIVE,
            // raw JS 는 편집 신뢰 전원 허용 정책 (Factory 와 동일) — 복구도 예외 아님
            allowRawJs: true,
            allowInclude: $allowInclude,
            allowUnresolvedExtension: true
        );
    }
}
