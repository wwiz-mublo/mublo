<?php
declare(strict_types=1);

namespace Mublo\Contract\Site;

/**
 * 한 요청 안에서 "지금 누가 사이트를 바꾸는가"(source·correlationId)를 정하는 범위.
 *
 * 코어 서비스는 호출자가 문맥을 넘기지 않으면 스스로 문맥을 만들어 기록한다. 그때 source를 'admin'으로
 * 고정하면 확장 설치 훅, 새 도메인 시드, 다른 변경이 끝난 뒤 이어지는 후속 작업(게시판 생성 → 메뉴 자동 등록)까지
 * 관리자 변경으로 남는다. 이 범위 안에서 만든 문맥은 범위의 source와 correlationId를 쓴다.
 * 범위가 없으면 지금처럼 관리자 변경이다.
 *
 * 범위는 중첩되며(가장 안쪽이 이김) 예외가 나도 반드시 닫힌다.
 */
final class SiteMutationScope
{
    /** @var list<array{source:string,correlationId:?string}> */
    private static array $stack = [];

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function run(string $source, ?string $correlationId, callable $work): mixed
    {
        if (!in_array($source, SiteMutationContext::sources(), true)) {
            throw new \InvalidArgumentException('Unsupported site mutation source: ' . $source);
        }
        self::$stack[] = ['source' => $source, 'correlationId' => $correlationId];
        try {
            return $work();
        } finally {
            array_pop(self::$stack);
        }
    }

    /**
     * 이미 기록한 변경의 주체를 그 뒤에 이어지는 작업에 물려준다.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function inherit(?SiteMutationContext $context, callable $work): mixed
    {
        return $context === null ? $work() : self::run($context->source, $context->correlationId, $work);
    }

    /** 범위의 주체로 새 문맥을 만든다. 범위가 없으면 관리자 변경. */
    public static function context(int $domainId): SiteMutationContext
    {
        $top = self::$stack === [] ? null : self::$stack[array_key_last(self::$stack)];
        return SiteMutationContext::create(
            $domainId,
            $top['source'] ?? SiteMutationContext::SOURCE_ADMIN,
            $top['correlationId'] ?? null
        );
    }

    public static function source(): string
    {
        return self::$stack === [] ? SiteMutationContext::SOURCE_ADMIN : self::$stack[array_key_last(self::$stack)]['source'];
    }
}
