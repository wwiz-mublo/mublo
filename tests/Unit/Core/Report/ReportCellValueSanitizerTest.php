<?php

namespace Tests\Unit\Core\Report;

use Mublo\Core\Report\Security\ReportCellValueSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReportCellValueSanitizerTest extends TestCase
{
    #[DataProvider('formulaLikeValues')]
    public function testDetectsFormulaLikeStringsAfterOptionalWhitespace(string $value): void
    {
        $this->assertTrue(ReportCellValueSanitizer::isFormulaLike($value));
        $this->assertSame("'" . $value, ReportCellValueSanitizer::forCsv($value));
    }

    public static function formulaLikeValues(): array
    {
        return [
            'equals' => ['=1+1'],
            'plus' => ['+SUM(A1:A2)'],
            'minus' => ['-1+2'],
            'at' => ['@SUM(A1:A2)'],
            'space' => ['  =1+1'],
            'tab' => ["\t=1+1"],
            'newline' => ["\r\n=1+1"],
            'utf8 bom' => ["\xEF\xBB\xBF=1+1"],
            'nbsp' => ["\xC2\xA0=1+1"],
        ];
    }

    #[DataProvider('safeValues')]
    public function testLeavesSafeValuesUnchanged(mixed $value): void
    {
        $this->assertFalse(ReportCellValueSanitizer::isFormulaLike($value));
        $this->assertSame($value, ReportCellValueSanitizer::forCsv($value));
    }

    public static function safeValues(): array
    {
        return [
            'plain' => ['hello'],
            'embedded formula marker' => ['value=1'],
            'apostrophe protected' => ["'=1+1"],
            'empty' => [''],
            'null' => [null],
            'integer' => [-100],
            'float' => [1.5],
            'boolean' => [true],
        ];
    }
}
