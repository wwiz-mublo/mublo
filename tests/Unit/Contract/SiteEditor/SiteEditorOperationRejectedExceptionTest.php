<?php
declare(strict_types=1);

namespace Tests\Unit\Contract\SiteEditor;

use Mublo\Contract\SiteEditor\SiteEditorOperationRejectedException;
use PHPUnit\Framework\TestCase;

final class SiteEditorOperationRejectedExceptionTest extends TestCase
{
    public function testItCarriesAProtocolCodeMessageAndResource(): void
    {
        $error = new SiteEditorOperationRejectedException(
            'RESTORE_RESOURCE_CHANGED',
            '테마를 적용한 뒤 메인 화면이 바뀌어 복원할 수 없습니다.',
            'affiliate_storefront:theme'
        );

        self::assertSame('RESTORE_RESOURCE_CHANGED', $error->errorCode);
        self::assertSame('affiliate_storefront:theme', $error->resourceId);
        self::assertSame('테마를 적용한 뒤 메인 화면이 바뀌어 복원할 수 없습니다.', $error->getMessage());
        self::assertInstanceOf(\InvalidArgumentException::class, $error, '기존 InvalidArgumentException 처리와 호환된다');
    }

    public function testItRejectsCodesThatAreNotProtocolErrorCodes(): void
    {
        foreach (['', 'bad', 'Plan_Schema', 'X', str_repeat('A', 65)] as $code) {
            try {
                new SiteEditorOperationRejectedException($code, 'x');
                self::fail('잘못된 오류 코드를 받아들였다: ' . $code);
            } catch (\InvalidArgumentException $error) {
                self::assertNotInstanceOf(SiteEditorOperationRejectedException::class, $error);
            }
        }
    }
}
