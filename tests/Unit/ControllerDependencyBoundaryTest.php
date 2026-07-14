<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ControllerDependencyBoundaryTest extends TestCase
{
    public function testCoreControllersDoNotDependDirectlyOnRepositories(): void
    {
        $violations = [];
        $root = dirname(__DIR__, 2) . '/src/Controller';
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

        $this->assertSame([], $violations, 'Core Controller는 Service를 통해 데이터에 접근해야 합니다.');
    }
}
