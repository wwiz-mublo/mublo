<?php

namespace Tests\Unit\Core\Error;

use Mublo\Core\Error\ErrorHandler;
use Mublo\Exception\ApplicationException;
use Mublo\Exception\ForbiddenException;
use Mublo\Exception\HttpNotFoundException;
use Mublo\Exception\UnauthorizedException;
use Mublo\Exception\ValidationException;
use Mublo\Infrastructure\Log\LogLevel;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * ErrorHandler 의 HTTP 상태코드 결정 회귀 테스트.
 *
 * 배경: 이전에는 determineStatusCode 가 예외 메시지 정규식과 getCode() 만 봐서,
 * httpStatusCode 를 보유한 ApplicationException 계층(ValidationException=400,
 * HttpNotFoundException=404 등)이 전부 500 으로 렌더되는 버그가 있었다.
 * 이 테스트가 그 회귀를 막는다.
 */
class ErrorHandlerTest extends TestCase
{
    private function statusFor(\Throwable $e): int
    {
        $handler = new ErrorHandler();
        $method = new ReflectionMethod($handler, 'determineStatusCode');
        $method->setAccessible(true);

        return (int) $method->invoke($handler, $e);
    }

    public function testValidationExceptionMapsTo400(): void
    {
        $this->assertSame(400, $this->statusFor(new ValidationException('검증 실패')));
    }

    public function testHttpNotFoundExceptionMapsTo404(): void
    {
        $this->assertSame(404, $this->statusFor(new HttpNotFoundException('Not Found')));
    }

    public function testForbiddenExceptionMapsTo403(): void
    {
        $this->assertSame(403, $this->statusFor(new ForbiddenException()));
    }

    public function testUnauthorizedExceptionMapsTo401(): void
    {
        $this->assertSame(401, $this->statusFor(new UnauthorizedException()));
    }

    public function testGenericThrowableMapsTo500(): void
    {
        $this->assertSame(500, $this->statusFor(new \RuntimeException('unexpected failure')));
    }

    public function testInternalNotFoundMessageIsNotMisreadAs404(): void
    {
        // "Service not found: Context" 같은 내부 에러가 404 로 오판되면 안 된다
        $this->assertSame(500, $this->statusFor(new \RuntimeException('Service not found: Context')));
    }

    public function testRouterStringErrorStillMapsViaFallback(): void
    {
        // 405 는 전용 예외 클래스가 없어 메시지 정규식 폴백("405 ...")으로 처리된다
        $this->assertSame(405, $this->statusFor(new \RuntimeException('405 Method Not Allowed')));
    }

    // =========================================================================
    // determineLogLevel — 상태코드 분기와 함께 바뀐 로그레벨 판정의 회귀 방지.
    // ApplicationException 은 getCode() 가 0 이라 코드 폴백을 못 타므로
    // httpStatusCode 로 4xx=WARNING / 5xx=ERROR 를 판정해야 한다.
    // =========================================================================

    private function levelFor(\Throwable $e): string
    {
        $handler = new ErrorHandler();
        $method = new ReflectionMethod($handler, 'determineLogLevel');
        $method->setAccessible(true);

        return (string) $method->invoke($handler, $e);
    }

    public function testClientErrorApplicationExceptionLogsAsWarning(): void
    {
        $this->assertSame(LogLevel::WARNING, $this->levelFor(new ValidationException('검증 실패')));
        $this->assertSame(LogLevel::WARNING, $this->levelFor(new HttpNotFoundException('Not Found')));
    }

    public function testServerErrorApplicationExceptionLogsAsError(): void
    {
        // httpStatusCode 기본값 500 → ERROR
        $this->assertSame(LogLevel::ERROR, $this->levelFor(new ApplicationException('내부 오류')));
    }

    public function testErrorExceptionSeverityMappingIsUnchanged(): void
    {
        $this->assertSame(LogLevel::WARNING, $this->levelFor(new \ErrorException('warn', 0, E_WARNING)));
        $this->assertSame(LogLevel::CRITICAL, $this->levelFor(new \ErrorException('fatal', 0, E_ERROR)));
    }

    public function testGenericExceptionWithClientCodeLogsAsWarning(): void
    {
        // ApplicationException 계층이 아니어도 getCode() 폴백은 기존대로 동작
        $this->assertSame(LogLevel::WARNING, $this->levelFor(new \RuntimeException('거부', 404)));
    }

    public function testGenericExceptionLogsAsError(): void
    {
        $this->assertSame(LogLevel::ERROR, $this->levelFor(new \RuntimeException('boom')));
    }
}
