<?php

namespace Tests\Board\Unit\Event;

use Mublo\Core\Event\FailFastEventInterface;
use Mublo\Packages\Board\Event\ArticleCreatingEvent;
use Mublo\Packages\Board\Event\ArticleDeletingEvent;
use Mublo\Packages\Board\Event\ArticleUpdatingEvent;
use Mublo\Packages\Board\Event\ArticleViewingEvent;
use Mublo\Packages\Board\Event\CommentCreatingEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Tests\Board\TestCase;

class BlockableEventsFailFastTest extends TestCase
{
    public function testEveryBlockableBoardEventFailsFastOnListenerFailure(): void
    {
        $classes = [
            ArticleCreatingEvent::class,
            ArticleUpdatingEvent::class,
            ArticleDeletingEvent::class,
            ArticleViewingEvent::class,
            CommentCreatingEvent::class,
            FileDownloadingEvent::class,
        ];

        foreach ($classes as $class) {
            $this->assertContains(FailFastEventInterface::class, class_implements($class), $class);
        }
    }
}
