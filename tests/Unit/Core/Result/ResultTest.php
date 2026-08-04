<?php

namespace Tests\Unit\Core\Result;

use PHPUnit\Framework\TestCase;
use Mublo\Core\Result\Result;

/**
 * ResultTest
 *
 * Service 계층 결과 객체 테스트
 */
class ResultTest extends TestCase
{
    // ─── 팩토리 메서드 ───

    public function testSuccessCreatesSuccessResult(): void
    {
        $result = Result::success('처리 완료');

        $this->assertTrue($result->isSuccess());
        $this->assertFalse($result->isFailure());
        $this->assertSame('처리 완료', $result->getMessage());
    }

    public function testFailureCreatesFailureResult(): void
    {
        $result = Result::failure('오류 발생');

        $this->assertFalse($result->isSuccess());
        $this->assertTrue($result->isFailure());
        $this->assertSame('오류 발생', $result->getMessage());
    }

    public function testSuccessWithEmptyMessage(): void
    {
        $result = Result::success();

        $this->assertTrue($result->isSuccess());
        $this->assertSame('', $result->getMessage());
    }

    public function testSuccessWithData(): void
    {
        $result = Result::success('성공', ['member_id' => 42, 'redirect' => '/dashboard']);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(['member_id' => 42, 'redirect' => '/dashboard'], $result->getData());
    }

    public function testFailureWithData(): void
    {
        $result = Result::failure('유효성 오류', ['field' => 'email', 'errors' => ['형식이 올바르지 않습니다']]);

        $this->assertTrue($result->isFailure());
        $this->assertSame('email', $result->get('field'));
    }

    // ─── 데이터 접근 ───

    public function testGetReturnsSpecificKey(): void
    {
        $result = Result::success('', ['board_id' => 7, 'slug' => 'hello']);

        $this->assertSame(7, $result->get('board_id'));
        $this->assertSame('hello', $result->get('slug'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $result = Result::success('', ['existing' => 'value']);

        $this->assertNull($result->get('missing'));
        $this->assertSame('기본값', $result->get('missing', '기본값'));
    }

    public function testHasReturnsTrueForExistingKey(): void
    {
        $result = Result::success('', ['foo' => 'bar']);

        $this->assertTrue($result->has('foo'));
    }

    public function testHasReturnsFalseForMissingKey(): void
    {
        $result = Result::success('', ['foo' => 'bar']);

        $this->assertFalse($result->has('baz'));
    }

    public function testHasReturnsTrueForNullValue(): void
    {
        $result = Result::success('', ['nullable' => null]);

        $this->assertTrue($result->has('nullable'));
    }

    public function testGetDataReturnsEmptyArrayByDefault(): void
    {
        $result = Result::success('메시지만');

        $this->assertSame([], $result->getData());
    }

    // ─── 불변 변환 ───

    public function testWithDataMergesData(): void
    {
        $original = Result::success('', ['a' => 1]);
        $updated = $original->withData(['b' => 2]);

        // 원본 불변
        $this->assertFalse($original->has('b'));

        // 새 Result에 병합됨
        $this->assertTrue($updated->has('a'));
        $this->assertTrue($updated->has('b'));
        $this->assertSame(1, $updated->get('a'));
        $this->assertSame(2, $updated->get('b'));
    }

    public function testWithDataOverridesExistingKey(): void
    {
        $original = Result::success('', ['count' => 5]);
        $updated = $original->withData(['count' => 10]);

        $this->assertSame(5, $original->get('count'));
        $this->assertSame(10, $updated->get('count'));
    }

    public function testWithMessageChangesMessage(): void
    {
        $original = Result::success('원본 메시지');
        $updated = $original->withMessage('변경된 메시지');

        $this->assertSame('원본 메시지', $original->getMessage());
        $this->assertSame('변경된 메시지', $updated->getMessage());
        $this->assertTrue($updated->isSuccess());
    }

    public function testWithMessagePreservesSuccessState(): void
    {
        $original = Result::failure('실패');
        $updated = $original->withMessage('다른 메시지');

        $this->assertTrue($updated->isFailure());
    }

    // ─── 배열 변환 ───

    public function testToArrayContainsSuccessAndMessage(): void
    {
        $result = Result::success('완료', ['id' => 3]);
        $array = $result->toArray();

        $this->assertTrue($array['success']);
        $this->assertSame('완료', $array['message']);
        $this->assertSame(3, $array['id']);
    }

    public function testToArrayForFailure(): void
    {
        $result = Result::failure('오류');
        $array = $result->toArray();

        $this->assertFalse($array['success']);
        $this->assertSame('오류', $array['message']);
    }

    // ─── 실제 Service 패턴 시뮬레이션 ───

    public function testTypicalControllerPattern(): void
    {
        $successResult = Result::success('저장되었습니다.', ['article_id' => 100]);

        if ($successResult->isSuccess()) {
            $this->assertSame(100, $successResult->get('article_id'));
            $this->assertSame('저장되었습니다.', $successResult->getMessage());
        } else {
            $this->fail('should not reach here');
        }
    }

    public function testTypicalValidationFailurePattern(): void
    {
        $failResult = Result::failure('제목을 입력해 주세요.', ['field' => 'title']);

        $this->assertTrue($failResult->isFailure());
        $this->assertSame('제목을 입력해 주세요.', $failResult->getMessage());
        $this->assertSame('title', $failResult->get('field'));
    }
}
