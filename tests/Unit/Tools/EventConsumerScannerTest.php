<?php

namespace Tests\Unit\Tools;

use Mublo\Tools\EventConsumerScanner;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/ExtensionApiPath.php';
require_once dirname(__DIR__, 3) . '/tools/ExtensionApiSurface.php';
require_once dirname(__DIR__, 3) . '/tools/EventPayloadScanner.php';
require_once dirname(__DIR__, 3) . '/tools/EventConsumerScanner.php';

final class EventConsumerScannerTest extends TestCase
{
    /** 이벤트 클래스 → 게터 → 반환 타입. 실제로는 이벤트 소스를 읽어 만든다. */
    private const GETTERS = [
        'Mublo\Packages\Board\Event\ArticleDeletedEvent' => [
            'getArticle' => 'Mublo\Packages\Board\Entity\BoardArticle',
            'getArticleId' => 'int',
        ],
        'Mublo\Core\Event\Rendering\SiteContextReadyEvent' => [
            'getContext' => 'Mublo\Core\Context\Context',
        ],
    ];

    public function testItReportsAForeignEntityTakenFromAnEventGetter(): void
    {
        $source = $this->subscriberSource(
            'use Mublo\Packages\Board\Event\ArticleDeletedEvent;',
            '$article = $event->getArticle();'
        );

        $violations = EventConsumerScanner::violationsIn(
            'packages/Board/Plugins/BoardReport/Subscriber/ArticleSubscriber.php',
            $source,
            self::GETTERS
        );

        $this->assertCount(1, $violations);
        $this->assertSame(
            'payload:ArticleDeletedEvent::getArticle(): Mublo\Packages\Board\Entity\BoardArticle',
            $violations[0]['symbol']
        );
    }

    public function testItReportsTheChainedFormToo(): void
    {
        $source = $this->subscriberSource(
            'use Mublo\Packages\Board\Event\ArticleDeletedEvent;',
            '$domainId = $event->getArticle()->getDomainId();'
        );

        $violations = EventConsumerScanner::violationsIn(
            'plugins/Widget/Subscriber/SampleSubscriber.php',
            $source,
            self::GETTERS
        );

        $this->assertCount(1, $violations);
    }

    public function testItAllowsAPackageToUseItsOwnEntityFromItsOwnEvent(): void
    {
        $source = $this->subscriberSource(
            'use Mublo\Packages\Board\Event\ArticleDeletedEvent;',
            '$article = $event->getArticle();'
        );

        $this->assertSame([], EventConsumerScanner::violationsIn(
            'packages/Board/Subscriber/BoardPointSubscriber.php',
            $source,
            self::GETTERS
        ));
    }

    public function testItAllowsStableReturnTypesAndScalars(): void
    {
        $source = $this->subscriberSource(
            'use Mublo\Core\Event\Rendering\SiteContextReadyEvent;',
            '$request = $event->getContext()->getRequest();',
            'SiteContextReadyEvent'
        );

        $this->assertSame([], EventConsumerScanner::violationsIn(
            'plugins/SnsLogin/Subscriber/LoginFormSubscriber.php',
            $source,
            self::GETTERS
        ));

        $scalar = $this->subscriberSource(
            'use Mublo\Packages\Board\Event\ArticleDeletedEvent;',
            '$id = $event->getArticleId();'
        );

        $this->assertSame([], EventConsumerScanner::violationsIn(
            'packages/Board/Plugins/BoardReport/Subscriber/ArticleSubscriber.php',
            $scalar,
            self::GETTERS
        ));
    }

    public function testItIgnoresUnknownGetters(): void
    {
        $source = $this->subscriberSource(
            'use Mublo\Packages\Board\Event\ArticleDeletedEvent;',
            '$whatever = $event->getSomethingUndeclared();'
        );

        $this->assertSame([], EventConsumerScanner::violationsIn(
            'plugins/Widget/Subscriber/SampleSubscriber.php',
            $source,
            self::GETTERS
        ));
    }

    public function testItFollowsTheParameterNameRatherThanAConvention(): void
    {
        // 핸들러 인자를 $event 로 부르지 않아도 잡아야 한다
        $source = <<<'PHP'
        <?php
        namespace Mublo\Plugin\Widget\Subscriber;

        use Mublo\Packages\Board\Event\ArticleDeletedEvent;

        class SampleSubscriber
        {
            public function onDeleted(ArticleDeletedEvent $deleted): void
            {
                $article = $deleted->getArticle();
            }
        }
        PHP;

        $violations = EventConsumerScanner::violationsIn(
            'plugins/Widget/Subscriber/SampleSubscriber.php',
            $source,
            self::GETTERS
        );

        $this->assertCount(1, $violations);
    }

    public function testItCollectsGetterTypesFromEventSources(): void
    {
        $map = EventConsumerScanner::collectEventGetters(
            [dirname(__DIR__, 3) . '/src/Core/Event/Rendering'],
            dirname(__DIR__, 3)
        );

        $event = 'Mublo\Core\Event\Rendering\SiteContextReadyEvent';

        $this->assertArrayHasKey($event, $map);
        $this->assertSame('Mublo\Core\Context\Context', $map[$event]['getContext'] ?? null);
        // 스칼라·비 Mublo 타입은 표에 담지 않는다 — 판정할 것이 없다
        $this->assertArrayNotHasKey('getPath', $map[$event]);
    }

    private function subscriberSource(
        string $uses,
        string $body,
        string $eventClass = 'ArticleDeletedEvent'
    ): string {
        return "<?php\ndeclare(strict_types=1);\nnamespace Mublo\\Plugin\\Sample\\Subscriber;\n\n{$uses}\n\n"
            . "class SampleSubscriber\n{\n"
            . "    public function onEvent({$eventClass} \$event): void\n    {\n        {$body}\n    }\n}\n";
    }
}
