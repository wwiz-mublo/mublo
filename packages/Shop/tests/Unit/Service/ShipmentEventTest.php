<?php

namespace Tests\Shop\Unit\Service;

use Mublo\Core\Event\EventDispatcher;
use Mublo\Packages\Shop\Event\ShipmentRegisteredEvent;
use Mublo\Packages\Shop\Event\ShipmentStatusChangedEvent;
use Mublo\Packages\Shop\Repository\ShipmentRepository;
use Mublo\Packages\Shop\Service\ShipmentService;
use Tests\Shop\TestCase;

class ShipmentEventTest extends TestCase
{
    public function testRegistrationAndStatusChangePublishExtensionEvents(): void
    {
        $repository = $this->createMock(ShipmentRepository::class);
        $repository->method('create')->willReturn(7);
        $repository->method('find')->willReturn([
            'shipment_id' => 7, 'order_no' => 'ORD1', 'shipment_status' => 'READY',
        ]);
        $repository->method('updateStatus')->willReturn(1);
        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ShipmentRegisteredEvent::class, function ($event) use (&$events): void { $events[] = $event; });
        $dispatcher->addListener(ShipmentStatusChangedEvent::class, function ($event) use (&$events): void { $events[] = $event; });
        $service = new ShipmentService($repository, null, $dispatcher);

        $this->assertTrue($service->registerShipment('ORD1', ['invoice_no' => '1234'])->isSuccess());
        $this->assertTrue($service->updateStatus(7, 'PICKED_UP')->isSuccess());
        $this->assertCount(2, $events);
        $this->assertSame('ORD1', $events[0]->getOrderNo());
        $this->assertSame('PICKED_UP', $events[1]->getNewStatus());
    }

    public function testShipmentMutationRejectsMismatchedRouteOrder(): void
    {
        $repository = $this->createMock(ShipmentRepository::class);
        $repository->method('find')->willReturn([
            'shipment_id' => 7, 'order_no' => 'ORD-OTHER', 'shipment_status' => 'READY',
        ]);
        $repository->expects($this->never())->method('update');
        $repository->expects($this->never())->method('delete');
        $service = new ShipmentService($repository);

        $this->assertTrue($service->updateShipment(7, ['invoice_no' => '1234'], 'ORD-MINE')->isFailure());
        $this->assertTrue($service->deleteShipment(7, 'ORD-MINE')->isFailure());
    }

    public function testOrderRouteCannotMutateClaimShipmentButMatchingClaimCanUpdateStatus(): void
    {
        $repository = $this->createMock(ShipmentRepository::class);
        $repository->method('find')->willReturn([
            'shipment_id' => 7,
            'order_no' => 'ORD1',
            'claim_id' => 33,
            'shipment_status' => 'READY',
        ]);
        $repository->expects($this->once())->method('updateStatus')->with(7, 'PICKED_UP')->willReturn(1);
        $repository->expects($this->never())->method('update');
        $repository->expects($this->never())->method('delete');
        $service = new ShipmentService($repository);

        $this->assertTrue($service->updateShipment(7, ['invoice_no' => '9999'], 'ORD1')->isFailure());
        $this->assertTrue($service->deleteShipment(7, 'ORD1')->isFailure());
        $this->assertTrue($service->updateStatus(7, 'PICKED_UP', 'ORD1')->isFailure());
        $this->assertTrue($service->updateStatus(7, 'PICKED_UP', 'ORD1', 33)->isSuccess());
    }
}
