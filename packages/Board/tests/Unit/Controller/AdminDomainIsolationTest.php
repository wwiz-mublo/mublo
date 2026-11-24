<?php
declare(strict_types=1);

namespace Tests\Board\Unit\Controller;

use Mublo\Core\Context\Context;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Result\Result;
use Mublo\Entity\Domain\Domain;
use Mublo\Packages\Board\Controller\Admin\BoardCategoryController;
use Mublo\Packages\Board\Controller\Admin\BoardConfigController;
use Mublo\Packages\Board\Controller\Admin\BoardGroupController;
use Mublo\Packages\Board\Entity\BoardCategory;
use Mublo\Packages\Board\Entity\BoardConfig;
use Mublo\Packages\Board\Entity\BoardGroup;
use Mublo\Packages\Board\Service\BoardCategoryService;
use Mublo\Packages\Board\Service\BoardConfigService;
use Mublo\Packages\Board\Service\BoardGroupService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * 관리자 게시판·그룹·카테고리 화면은 요청한 ID가 현재 도메인 소유일 때만 처리한다.
 * 도메인 1의 관리자가 도메인 2의 ID를 보내도 수정·삭제되지 않아야 한다.
 */
final class AdminDomainIsolationTest extends TestCase
{
    public function testBoardDeleteRejectsAnotherDomainsBoard(): void
    {
        $service = $this->boardService();
        $service->expects($this->never())->method('deleteBoard');

        $response = $this->boardController($service)->delete([], $this->context(json: ['board_id' => 20]));

        $this->assertFalse($this->isSuccess($response));
    }

    public function testBoardListDeleteSkipsAnotherDomainsBoards(): void
    {
        $service = $this->boardService();
        $service->expects($this->once())->method('deleteBoard')->with(10)->willReturn(Result::success('ok'));

        $this->boardController($service)->listDelete([], $this->context(body: ['chk' => [10, 20]]));
    }

    public function testBoardListUpdateSkipsAnotherDomainsBoards(): void
    {
        $service = $this->boardService();
        $service->expects($this->once())
            ->method('batchUpdate')
            ->with([10 => ['is_active' => 0]])
            ->willReturn(Result::success('ok', ['updated' => 1]));

        $this->boardController($service)->listUpdate([], $this->context(body: [
            'chk' => [10, 20],
            'is_active' => [10 => 0, 20 => 0],
        ]));
    }

    public function testGroupDeleteAndListDeleteRejectAnotherDomainsGroup(): void
    {
        $service = $this->createMock(BoardGroupService::class);
        $service->method('getGroup')->willReturnCallback(fn (int $id): ?BoardGroup => $this->group($id));
        $service->expects($this->once())->method('deleteGroup')->with(1)->willReturn(Result::success('ok'));

        $controller = $this->controller(BoardGroupController::class, ['groupService' => $service]);
        $this->assertFalse($this->isSuccess($controller->delete([], $this->context(json: ['group_id' => 2]))));
        $controller->listDelete([], $this->context(body: ['chk' => [1, 2]]));
    }

    public function testCategoryDeleteAndListDeleteRejectAnotherDomainsCategory(): void
    {
        $service = $this->createMock(BoardCategoryService::class);
        $service->method('getCategory')->willReturnCallback(fn (int $id): ?BoardCategory => $this->category($id));
        $service->expects($this->once())->method('deleteCategory')->with(3)->willReturn(Result::success('ok'));

        $controller = $this->controller(BoardCategoryController::class, ['categoryService' => $service]);
        $this->assertFalse($this->isSuccess($controller->delete([], $this->context(json: ['category_id' => 4]))));
        $controller->listDelete([], $this->context(body: ['chk' => [3, 4]]));
    }

    /** @return BoardConfigService&\PHPUnit\Framework\MockObject\MockObject */
    private function boardService(): BoardConfigService
    {
        $service = $this->createMock(BoardConfigService::class);
        $service->method('getBoard')->willReturnCallback(fn (int $id): ?BoardConfig => match ($id) {
            10 => BoardConfig::fromArray(['board_id' => 10, 'domain_id' => 1, 'board_slug' => 'mine', 'board_name' => '내 게시판']),
            20 => BoardConfig::fromArray(['board_id' => 20, 'domain_id' => 2, 'board_slug' => 'other', 'board_name' => '남의 게시판']),
            default => null,
        });
        return $service;
    }

    private function boardController(BoardConfigService $service): BoardConfigController
    {
        return $this->controller(BoardConfigController::class, ['boardService' => $service]);
    }

    private function group(int $id): ?BoardGroup
    {
        return BoardGroup::fromArray(['group_id' => $id, 'domain_id' => $id, 'group_slug' => "g{$id}", 'group_name' => "그룹{$id}"]);
    }

    private function category(int $id): ?BoardCategory
    {
        return BoardCategory::fromArray([
            'category_id' => $id,
            'domain_id' => $id === 3 ? 1 : 2,
            'category_slug' => "c{$id}",
            'category_name' => "카테고리{$id}",
        ]);
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @param array<string, object> $properties
     * @return T
     */
    private function controller(string $class, array $properties): object
    {
        $reflection = new ReflectionClass($class);
        $controller = $reflection->newInstanceWithoutConstructor();
        foreach ($properties as $name => $value) {
            $property = $reflection->getProperty($name);
            $property->setValue($controller, $value);
        }
        return $controller;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed>|null $json
     */
    private function context(array $body = [], ?array $json = null): Context
    {
        $request = new Request('POST', '/admin/board', [], $body);
        $request->setJsonInput($json);
        $context = new Context($request);
        $context->setDomainInfo(Domain::fromArray(['domain_id' => 1, 'domain' => 'mine.test']));
        return $context;
    }

    private function isSuccess(JsonResponse $response): bool
    {
        return $response->isSuccess();
    }
}
