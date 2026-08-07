<?php

namespace Tests\Board\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ControllerDependencyBoundaryTest extends TestCase
{
    public function testBoardControllersDoNotDependDirectlyOnRepositories(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2) . '/Controller';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/^use .*\\\\Repository\\\\.*Repository;/m', $source) === 1) {
                $violations[] = str_replace('\\', '/', $file->getPathname());
            }
        }

        $this->assertSame([], $violations, 'Board Controller는 Service를 통해 데이터에 접근해야 합니다.');
    }

    public function testBoardMemberActionsDoNotDependOnDirectMessageImplementation(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (!$file->isFile()
                || $file->getExtension() !== 'php'
                || str_contains($path, '/tests/')
            ) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());
            if (stripos($source, 'DirectMessage') !== false
                || stripos($source, '/direct-message') !== false
            ) {
                $violations[] = $path;
            }
        }

        $this->assertSame(
            [],
            $violations,
            'Board는 Core 회원 액션 Contract만 사용하고 DirectMessage 구현을 참조하지 않아야 합니다.'
        );
    }

    public function testBundledDetailSkinsRenderCoreMemberActionMenu(): void
    {
        $skinRoot = dirname(__DIR__, 2) . '/views/Front/Board';

        foreach (['basic', 'gallery'] as $skin) {
            $source = (string) file_get_contents("{$skinRoot}/{$skin}/View.php");
            $this->assertSame(2, substr_count($source, 'memberActionMenu('), "{$skin} 스킨 연동 누락");
            $this->assertStringNotContainsString("['member_id']", $source, "{$skin} 스킨 내부 ID 참조 금지");
        }
    }

    public function testDetailControllerBatchesPublicMemberIdentifiersOncePerPage(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/Controller/Front/BoardController.php'
        );

        $this->assertSame(1, substr_count($source, '->publicIdsFor('));
        $this->assertSame(1, substr_count($source, '->forMembers('));
        $this->assertStringContainsString("['board.article_author', 'member.author']", $source);
        $this->assertStringContainsString("['board.comment_author', 'member.author']", $source);
    }
}
