<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Service\OrderFieldService;
use Mublo\Service\CustomField\CustomFieldFileHandler;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 주문서 파일 필드 처리기 접근
 *
 * CartController 는 과거 Application::getInstance()->getContainer() 로 핸들러를 만들려 했다.
 * 두 메서드 모두 존재하지 않아 catch(\Throwable) 에 삼켜졌고, getFileHandler() 가 항상 null 을
 * 반환해 주문서 파일 업로드가 "사용할 수 없습니다" 로만 응답했다.
 *
 * 이제 OrderFieldService 가 이미 주입받은 인스턴스를 그대로 노출한다.
 */
class OrderFieldServiceFileHandlerTest extends TestCase
{
    public function testExposesInjectedFileHandler(): void
    {
        $handler = (new ReflectionClass(CustomFieldFileHandler::class))->newInstanceWithoutConstructor();

        $this->assertSame($handler, $this->makeService($handler)->getFileHandler());
    }

    /**
     * SecureFileService 가 없는 설치에서는 핸들러가 null 이다.
     * 그 경우 컨트롤러가 "파일 업로드 기능을 사용할 수 없습니다" 로 응답하는 것이 정상이다.
     */
    public function testReturnsNullWhenSecureFileServiceIsAbsent(): void
    {
        $this->assertNull($this->makeService(null)->getFileHandler());
    }

    private function makeService(?CustomFieldFileHandler $handler): OrderFieldService
    {
        $reflection = new ReflectionClass(OrderFieldService::class);
        $service = $reflection->newInstanceWithoutConstructor();

        $property = $reflection->getProperty('fileHandler');
        $property->setAccessible(true);
        $property->setValue($service, $handler);

        return $service;
    }
}
