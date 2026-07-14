<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Packages\Shop\Repository\ProductInfoTemplateRepository;
use Mublo\Packages\Shop\Service\ProductInfoTemplateService;
use PHPUnit\Framework\TestCase;

class ProductInfoTemplateServiceDomainTest extends TestCase
{
    public function testUpdateRejectsTemplateOutsideCurrentDomain(): void
    {
        $repository = $this->createMock(ProductInfoTemplateRepository::class);
        $repository->expects($this->once())
            ->method('findInDomain')
            ->with(7, 99)
            ->willReturn(null);
        $repository->expects($this->never())->method('updateInDomain');

        $result = (new ProductInfoTemplateService($repository))->save(7, [
            'subject' => 'Subject',
            'tab_name' => 'Tab',
        ], 99);

        $this->assertTrue($result->isFailure());
    }

    public function testDeleteUsesCurrentDomain(): void
    {
        $repository = $this->createMock(ProductInfoTemplateRepository::class);
        $repository->method('findInDomain')->with(7, 3)->willReturn(['template_id' => 3]);
        $repository->expects($this->once())->method('deleteInDomain')->with(7, 3)->willReturn(1);

        $result = (new ProductInfoTemplateService($repository))->delete(7, 3);

        $this->assertTrue($result->isSuccess());
    }
}
