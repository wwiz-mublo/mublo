<?php

namespace Tests\Unit\Infrastructure;

use Mublo\Infrastructure\Database\Database;
use PHPUnit\Framework\TestCase;

class DatabaseLoggingTest extends TestCase
{
    public function testQueryLogMasksPositionalStringBindings(): void
    {
        $database = new class extends Database {
            public function __construct() {}

            public function record(array $params): array
            {
                $this->enableQueryLog();
                $this->logQuery('SELECT * FROM members WHERE password = ? AND member_id = ?', $params, 0.01);
                return $this->getQueryLog();
            }
        };

        $log = $database->record(['short-secret', 17]);

        $this->assertSame(['***MASKED***', 17], $log[0]['params']);
    }

    public function testQueryLogMasksSensitiveNamedBindingsOnly(): void
    {
        $database = new class extends Database {
            public function __construct() {}

            public function record(array $params): array
            {
                $this->enableQueryLog();
                $this->logQuery('UPDATE members SET password = :password WHERE status = :status', $params, 0.01);
                return $this->getQueryLog();
            }
        };

        $log = $database->record(['password' => 'short-secret', 'status' => 'active']);

        $this->assertSame('***MASKED***', $log[0]['params']['password']);
        $this->assertSame('active', $log[0]['params']['status']);
    }
}
