<?php
namespace Tests\Survey\Unit;
use Mublo\Infrastructure\Database\Database;
use Mublo\Plugin\Survey\Service\SurveyDataResetter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
final class SurveyDataResetterTest extends TestCase
{
    #[Test]
    public function fullResetDeletesAnswersResponsesQuestionsAndSurveysForDomain(): void
    {
        $queries = [];
        $db = $this->createMock(Database::class);
        $db->method('tableExists')->willReturn(true);
        $db->method('execute')->willReturnCallback(function (string $sql, array $params) use (&$queries): int { $queries[] = [$sql, $params]; return 1; });
        $result = (new SurveyDataResetter($db))->reset('surveys', 88);
        $this->assertSame(4, $result->tablesCleared);
        foreach ($queries as [$sql, $params]) { $this->assertStringContainsString('domain_id', $sql); $this->assertContains(88, $params); }
    }
}
