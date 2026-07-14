<?php

namespace Tests\Board\Unit\Event;

use Mublo\Packages\Board\Entity\BoardArticle;
use Mublo\Packages\Board\Entity\BoardAttachment;
use Mublo\Packages\Board\Event\ArticleViewingEvent;
use Mublo\Packages\Board\Event\FileDownloadingEvent;
use Tests\Board\TestCase;

class GlobalAccessDomainEventTest extends TestCase
{
    public function testArticleViewingUsesAccessDomainForPointConsumption(): void
    {
        $article = BoardArticle::fromArray($this->makeArticleData(['domain_id' => 1]));
        $event = new ArticleViewingEvent($article, 7, '127.0.0.1', 2);

        $this->assertSame(2, $event->getDomainId());
        $this->assertSame(1, $event->getArticle()->getDomainId());
    }

    public function testFileDownloadingUsesAccessDomainForPointConsumption(): void
    {
        $attachment = BoardAttachment::fromArray([
            'attachment_id' => 50,
            'domain_id' => 1,
            'board_id' => 20,
            'article_id' => 10,
            'original_name' => 'test.pdf',
            'stored_name' => 'stored.pdf',
            'file_path' => 'D1/board/2026/07',
            'file_size' => 100,
            'file_extension' => 'pdf',
            'mime_type' => 'application/pdf',
        ]);
        $event = new FileDownloadingEvent($attachment, 7, '127.0.0.1', 2);

        $this->assertSame(2, $event->getDomainId());
        $this->assertSame(1, $event->getAttachment()->getDomainId());
    }
}
