<?php
declare(strict_types=1);

namespace Tests\Unit\Controller\Front;

use Mublo\Contract\Auth\ReauthenticationInterface;
use Mublo\Controller\Front\MypageController;
use Mublo\Core\Context\Context;
use Mublo\Core\Event\EventDispatcher;
use Mublo\Core\Http\Request;
use Mublo\Core\Response\JsonResponse;
use Mublo\Core\Result\Result;
use Mublo\Core\Session\SessionInterface;
use Mublo\Service\Auth\AuthService;
use Mublo\Service\Balance\BalanceManager;
use Mublo\Service\Member\MemberService;
use Mublo\Service\Mypage\MypageMenuBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * 비밀번호 변경 앞의 본인 재확인 관문.
 *
 * 세션만으로 비밀번호를 갈아치울 수 있으면, 자리를 비운 단말이나 탈취된 세션으로
 * 계정을 영구히 가져갈 수 있다. 관문이 무엇으로 확인했는지는 묻지 않되, 확인 없이는
 * 통과시키지 않는다는 것이 이 테스트가 지키는 계약이다.
 */
final class MypageReauthenticationGateTest extends TestCase
{
    private MemberService&MockObject $members;
    private ReauthenticationInterface&MockObject $reauth;
    private MypageController $controller;

    protected function setUp(): void
    {
        $this->members = $this->createMock(MemberService::class);
        $this->reauth = $this->createMock(ReauthenticationInterface::class);

        $auth = $this->createStub(AuthService::class);
        $auth->method('user')->willReturn(['member_id' => 7]);

        $this->controller = new MypageController(
            $this->members,
            $auth,
            $this->createStub(BalanceManager::class),
            $this->createStub(EventDispatcher::class),
            $this->createStub(SessionInterface::class),
            $this->createStub(MypageMenuBuilder::class),
            $this->reauth,
        );
    }

    public function testWrongCurrentPasswordWithoutAnEarlierConfirmationIsRejected(): void
    {
        $this->members->method('verifyPassword')->willReturn(false);
        $this->reauth->expects($this->never())->method('confirm');
        $this->reauth->method('isConfirmed')->willReturn(false);
        $this->members->expects($this->never())->method('update');

        $response = $this->controller->updateProfile($this->passwordChangeRequest('틀린비밀번호'), $this->context());

        $this->assertStringContainsString('현재 비밀번호가 일치하지 않습니다', $this->payload($response));
    }

    public function testCorrectCurrentPasswordRecordsTheConfirmationAndProceeds(): void
    {
        $this->members->method('verifyPassword')->with(7, '맞는비밀번호')->willReturn(true);
        $this->reauth->expects($this->once())->method('confirm')->with(7);
        $this->reauth->method('isConfirmed')->with(7)->willReturn(true);
        $this->members->expects($this->once())->method('update')
            ->with(7, $this->callback(static fn (array $data): bool => ($data['password'] ?? null) === '새비밀번호'))
            ->willReturn(Result::success('수정되었습니다.'));

        $this->controller->updateProfile($this->passwordChangeRequest('맞는비밀번호'), $this->context());
    }

    /**
     * 확인 수단은 비밀번호만이 아니다. 자기 비밀번호를 모르는 회원(SNS 전용)이
     * 다른 방법으로 확인을 마쳤다면, 현재 비밀번호 칸이 비어도 통과해야 한다.
     */
    public function testEarlierConfirmationLetsAMemberWithoutAPasswordThrough(): void
    {
        $this->members->expects($this->never())->method('verifyPassword');
        $this->reauth->expects($this->never())->method('confirm');
        $this->reauth->method('isConfirmed')->with(7)->willReturn(true);
        $this->members->expects($this->once())->method('update')
            ->willReturn(Result::success('수정되었습니다.'));

        $this->controller->updateProfile($this->passwordChangeRequest(''), $this->context());
    }

    /**
     * 비밀번호를 건드리지 않는 수정(닉네임 등)까지 확인을 요구하면, 계정을 바꾸지도
     * 않는 작업에 문턱만 생긴다.
     */
    public function testProfileEditWithoutAPasswordChangeNeedsNoConfirmation(): void
    {
        $this->reauth->expects($this->never())->method('isConfirmed');
        $this->members->expects($this->never())->method('verifyPassword');
        $this->members->expects($this->once())->method('update')
            ->with(7, $this->callback(static fn (array $data): bool => !array_key_exists('password', $data)))
            ->willReturn(Result::success('수정되었습니다.'));

        $this->controller->updateProfile($this->request(['nickname' => '새닉네임']), $this->context());
    }

    private function passwordChangeRequest(string $currentPassword): Request&MockObject
    {
        return $this->request([
            'nickname' => '닉네임',
            'current_password' => $currentPassword,
            'new_password' => '새비밀번호',
            'new_password_confirm' => '새비밀번호',
        ]);
    }

    /** @param array<string, mixed> $post */
    private function request(array $post): Request&MockObject
    {
        $request = $this->createMock(Request::class);
        $request->method('post')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $post[$key] ?? $default
        );
        $request->method('isAjax')->willReturn(true);

        return $request;
    }

    private function context(): Context
    {
        return $this->createStub(Context::class);
    }

    private function payload(mixed $response): string
    {
        $this->assertInstanceOf(JsonResponse::class, $response);

        return json_encode($response->getData(), JSON_UNESCAPED_UNICODE) ?: '';
    }
}
