<?php

namespace Tests\Unit\Tools;

use Mublo\Tools\EventPayloadScanner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/ExtensionApiPath.php';
require_once dirname(__DIR__, 3) . '/tools/ExtensionApiSurface.php';
require_once dirname(__DIR__, 3) . '/tools/EventPayloadScanner.php';

final class EventPayloadScannerTest extends TestCase
{
    public function testItReportsInternalEntityReturnedByEvent(): void
    {
        $source = $this->eventSource(
            'use Mublo\Entity\Member\Member;',
            'public function getMember(): Member { return $this->member; }'
        );

        $leaks = EventPayloadScanner::leaksIn('src/Service/Member/Event/SampleEvent.php', $source);

        $this->assertCount(1, $leaks);
        $this->assertSame(
            'SampleEvent::getMember(): Mublo\Entity\Member\Member',
            $leaks[0]['symbol']
        );
    }

    public function testItAllowsStableTypes(): void
    {
        $source = $this->eventSource(
            "use Mublo\Core\Context\Context;\nuse Mublo\Contract\Member\MemberProfile;",
            "public function getContext(): Context { return \$this->context; }\n"
            . '    public function getProfile(): ?MemberProfile { return $this->profile; }'
        );

        $this->assertSame([], EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source));
    }

    public function testItIgnoresScalarAndVoidReturns(): void
    {
        $source = $this->eventSource(
            '',
            "public function getMemberId(): int { return 1; }\n"
            . "    public function getChanges(): array { return []; }\n"
            . "    public function isLevelChanged(): bool { return true; }\n"
            . '    public function setBlocked(bool $blocked): void {}'
        );

        $this->assertSame([], EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source));
    }

    public function testItIgnoresNonPublicMethods(): void
    {
        $source = $this->eventSource(
            'use Mublo\Entity\Member\Member;',
            "private function member(): Member { return \$this->member; }\n"
            . '    protected function other(): Member { return $this->member; }'
        );

        $this->assertSame([], EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source));
    }

    public function testItIgnoresClassesThatAreNotEvents(): void
    {
        $source = <<<'PHP'
        <?php
        namespace Mublo\Service\Member;

        use Mublo\Entity\Member\Member;

        class MemberService
        {
            public function getMember(): Member { return $this->member; }
        }
        PHP;

        $this->assertSame([], EventPayloadScanner::leaksIn('src/Service/Member/MemberService.php', $source));
    }

    public function testItInspectsEveryBranchOfAUnionType(): void
    {
        $source = $this->eventSource(
            "use Mublo\Core\Context\Context;\nuse Mublo\Entity\Domain\Domain;",
            'public function getTarget(): Context|Domain { return $this->target; }'
        );

        $leaks = EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source);

        $this->assertCount(1, $leaks);
        $this->assertSame('SampleEvent::getTarget(): Mublo\Entity\Domain\Domain', $leaks[0]['symbol']);
    }

    public function testItResolvesAliasedAndFullyQualifiedTypes(): void
    {
        $source = $this->eventSource(
            'use Mublo\Entity\Member\Member as MemberEntity;',
            "public function getAliased(): MemberEntity { return \$this->member; }\n"
            . '    public function getQualified(): \Mublo\Entity\Domain\Domain { return $this->domain; }'
        );

        $symbols = array_column(
            EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source),
            'symbol'
        );

        $this->assertSame([
            'SampleEvent::getAliased(): Mublo\Entity\Member\Member',
            'SampleEvent::getQualified(): Mublo\Entity\Domain\Domain',
        ], $symbols);
    }

    public function testItResolvesUnimportedTypesAgainstOwnNamespace(): void
    {
        $source = <<<'PHP'
        <?php
        namespace Mublo\Packages\Board\Event;

        class SampleEvent
        {
            public function getArticle(): Sibling { return $this->article; }
        }
        PHP;

        $leaks = EventPayloadScanner::leaksIn('packages/Board/Event/SampleEvent.php', $source);

        // 같은 네임스페이스(= 부모 Package 의 Event\*)는 종속 Plugin 에게도 공개 표면이다
        $this->assertSame([], $leaks);
    }

    public function testItAllowsAPackageEventToReturnItsOwnTypes(): void
    {
        // 소유자가 같으면 한쪽을 리팩터링할 때 다른 쪽도 같이 고친다 — 내부 응집이다.
        // 확장 밖 소비자가 이것을 꺼내 쓰는지는 EventConsumerScanner 가 본다.
        $source = $this->eventSource(
            'use Mublo\Packages\Board\Entity\BoardArticle;',
            'public function getArticle(): BoardArticle { return $this->article; }',
            'Mublo\Packages\Board\Event'
        );

        $this->assertSame([], EventPayloadScanner::leaksIn('packages/Board/Event/SampleEvent.php', $source));
    }

    public function testItReportsAParentPackageTypeLeakedByANestedPluginEvent(): void
    {
        // 종속 Plugin 에게 부모 Package 의 엔티티는 남의 타입이다.
        $source = $this->eventSource(
            'use Mublo\Packages\Board\Entity\BoardArticle;',
            'public function getArticle(): BoardArticle { return $this->article; }',
            'Mublo\Packages\Board\Plugins\BoardReport\Event'
        );

        $symbols = array_column(
            EventPayloadScanner::leaksIn('packages/Board/Plugins/BoardReport/Event/SampleEvent.php', $source),
            'symbol'
        );

        $this->assertSame(
            ['SampleEvent::getArticle(): Mublo\Packages\Board\Entity\BoardArticle'],
            $symbols
        );
    }

    public function testItReportsTheDeclarationLine(): void
    {
        $source = $this->eventSource(
            'use Mublo\Entity\Member\Member;',
            'public function getMember(): Member { return $this->member; }'
        );

        $expected = 1 + substr_count(
            substr($source, 0, (int) strpos($source, 'public function getMember')),
            "\n"
        );

        $leaks = EventPayloadScanner::leaksIn('src/Core/Event/SampleEvent.php', $source);

        $this->assertSame($expected, $leaks[0]['line']);
    }

    public function testItDerivesTheOwningNamespaceFromPath(): void
    {
        $this->assertSame(
            'Mublo\Packages\Shop\\',
            EventPayloadScanner::ownedTypePrefix('packages/Shop/Event/SampleEvent.php')
        );

        $this->assertSame(
            'Mublo\Plugin\Qna\\',
            EventPayloadScanner::ownedTypePrefix('plugins/Qna/Event/SampleEvent.php')
        );

        // 중첩 Plugin 의 소유 범위는 부모가 아니라 자기 자신이다
        $this->assertSame(
            'Mublo\Packages\Board\Plugins\BoardReport\\',
            EventPayloadScanner::ownedTypePrefix('packages/Board/Plugins/BoardReport/Event/SampleEvent.php')
        );

        // 코어에는 "자기 확장" 이라는 개념이 없다
        $this->assertNull(EventPayloadScanner::ownedTypePrefix('src/Core/Event/SampleEvent.php'));
    }

    private function eventSource(string $uses, string $body, string $namespace = 'Mublo\Core\Event'): string
    {
        return "<?php\ndeclare(strict_types=1);\nnamespace {$namespace};\n\n{$uses}\n\n"
            . "class SampleEvent\n{\n    {$body}\n}\n";
    }
}
