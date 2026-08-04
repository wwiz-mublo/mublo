<?php
/**
 * tests/TestCase.php
 *
 * 모든 테스트의 기본 클래스
 */

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\App\Application;

abstract class TestCase extends BaseTestCase
{
    protected ?DependencyContainer $container = null;
    protected ?Application $app = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->container = DependencyContainer::getInstance();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->container = null;
        $this->app = null;
        
        // Singleton 리셋 (테스트 격리)
        if (method_exists(DependencyContainer::class, 'resetInstance')) {
            DependencyContainer::resetInstance();
        }
    }

    /**
     * 컨테이너 반환
     */
    protected function getContainer(): DependencyContainer
    {
        return $this->container;
    }

    /**
     * 테스트용 임시 파일 경로 반환
     */
    protected function getTempPath(string $filename = ''): string
    {
        $tempDir = MUBLO_STORAGE_PATH . '/tests';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        if ($filename) {
            return $tempDir . '/' . $filename;
        }

        return $tempDir;
    }

    /**
     * 테스트 임시 파일 정리
     */
    protected function cleanupTemp(): void
    {
        $tempDir = $this->getTempPath();
        if (is_dir($tempDir)) {
            $files = scandir($tempDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    $path = $tempDir . '/' . $file;
                    if (is_file($path)) {
                        unlink($path);
                    }
                }
            }
        }
    }
}
