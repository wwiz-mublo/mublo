<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\SiteEditor;

use Mublo\Contract\SiteEditor\SiteEditorOperationResult;
use PHPUnit\Framework\TestCase;

final class SiteEditorOperationResultTest extends TestCase
{
    public function testItSeparatesPublicResultFromResourceChanges(): void
    {
        $result = new SiteEditorOperationResult(
            ['resource_id' => 'board:7', 'created' => true],
            [[
                'resource_id' => 'board:7',
                'resource_type' => 'board',
                'before' => null,
                'after' => ['name' => '문의'],
                'restore_guard' => ['resource_id' => 'board:7'],
            ]]
        );

        self::assertTrue($result->result['created']);
        self::assertSame('board:7', $result->resourceChanges[0]['resource_id']);
    }
}
