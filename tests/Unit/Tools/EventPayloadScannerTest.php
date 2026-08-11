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

    public function testItAllowsOwnPackagePublicSurfaceButNotOwnEntities(): void
    {
        $source = $this->eventSource(
            "use Mublo\Packages\Board\Api\DTO\ArticleSummary;\nuse Mublo\Packages\Board\Entity\BoardArticle;",
            "public function getSummary(): ArticleSummary { return \$this->summary; }\n"
            . '    public function getArticle(): BoardArticle { return $this->article; }',
            'Mublo\Packages\Board\Event'
        );

        $symbols = array_column(
            EventPayloadScanner::leaksIn('packages/Board/Event/SampleEvent.php', $source),
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

    public function testItDerivesOwnPublicSurfaceFromPath(): void
    {
        $this->assertSame([
            'Mublo\Packages\Shop\Contract\Extension\\',
            'Mublo\Packages\Shop\Api\DTO\\',
            'Mublo\Packages\Shop\Event\\',
        ], EventPayloadScanner::ownPublicPrefixes('packages/Shop/Event/SampleEvent.php'));

        $this->assertSame([
            'Mublo\Plugin\Qna\Contract\Extension\\',
            'Mublo\Plugin\Qna\Api\DTO\\',
            'Mublo\Plugin\Qna\Event\\',
        ], EventPayloadScanner::ownPublicPrefixes('plugins/Qna/Event/SampleEvent.php'));

        // 코어에는 "자기 확장 표면" 이라는 개념이 없다
        $this->assertSame([], EventPayloadScanner::ownPublicPrefixes('src/Core/Event/SampleEvent.php'));
    }

    private function eventSource(string $uses, string $body, string $namespace = 'Mublo\Core\Event'): string
    {
        return "<?php\ndeclare(strict_types=1);\nnamespace {$namespace};\n\n{$uses}\n\n"
            . "class SampleEvent\n{\n    {$body}\n}\n";
    }
}
