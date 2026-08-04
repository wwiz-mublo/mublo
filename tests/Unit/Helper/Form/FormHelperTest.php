<?php

namespace Tests\Unit\Helper\Form;

use PHPUnit\Framework\TestCase;
use Mublo\Helper\Form\FormHelper;

/**
 * FormHelperTest
 *
 * FormHelper 단위 테스트
 * - normalizeFormData: numeric, date, bool, enum, json, required_string, 일반 문자열
 * - normalizeFormData: 빈 입력, 배열 값, 엣지 케이스
 * - normalizeFileData
 * - validateUploadedFile
 * - getUploadErrorMessage
 */
class FormHelperTest extends TestCase
{
    // =========================================================================
    // normalizeFormData - 기본 동작
    // =========================================================================

    public function testNormalizeFormDataReturnsEmptyArrayForEmptyInput(): void
    {
        $result = FormHelper::normalizeFormData([]);
        $this->assertEmpty($result);
    }

    public function testNormalizeFormDataWithNoSchema(): void
    {
        $result = FormHelper::normalizeFormData(['name' => 'John', 'email' => 'john@example.com']);

        $this->assertEquals('John', $result['name']);
        $this->assertEquals('john@example.com', $result['email']);
    }

    public function testNormalizeFormDataTrimsWhitespace(): void
    {
        $result = FormHelper::normalizeFormData(['name' => '  John  ']);
        $this->assertEquals('John', $result['name']);
    }

    public function testNormalizeFormDataSetsNullForEmptyString(): void
    {
        $result = FormHelper::normalizeFormData(['optional_field' => '']);
        $this->assertNull($result['optional_field']);
    }

    // =========================================================================
    // Numeric 필드
    // =========================================================================

    public function testNormalizeFormDataNumericField(): void
    {
        $result = FormHelper::normalizeFormData(
            ['count' => '42', 'price' => '1000'],
            ['numeric' => ['count', 'price']]
        );

        $this->assertEquals(42, $result['count']);
        $this->assertEquals(1000, $result['price']);
    }

    public function testNormalizeFormDataNumericFieldWithNonNumeric(): void
    {
        $result = FormHelper::normalizeFormData(
            ['count' => 'abc'],
            ['numeric' => ['count']]
        );

        // 숫자가 전혀 없으면 StringHelper::pickNumber → null 반환
        $this->assertNull($result['count']);
    }

    public function testNormalizeFormDataNumericFieldWithDecimalString(): void
    {
        $result = FormHelper::normalizeFormData(
            ['price' => '1,500원'],
            ['numeric' => ['price']]
        );

        // 숫자만 추출
        $this->assertEquals(1500, $result['price']);
    }

    // =========================================================================
    // Date 필드
    // =========================================================================

    public function testNormalizeFormDataDateFieldWithValidDate(): void
    {
        $result = FormHelper::normalizeFormData(
            ['start_date' => '2024-01-15'],
            ['date' => ['start_date']]
        );

        $this->assertEquals('2024-01-15', $result['start_date']);
    }

    public function testNormalizeFormDataDateFieldWithEmptyValue(): void
    {
        $result = FormHelper::normalizeFormData(
            ['start_date' => ''],
            ['date' => ['start_date']]
        );

        $this->assertNull($result['start_date']);
    }

    public function testNormalizeFormDataDateFieldWithInvalidDate(): void
    {
        $result = FormHelper::normalizeFormData(
            ['start_date' => 'not-a-date'],
            ['date' => ['start_date']]
        );

        $this->assertNull($result['start_date']);
    }

    // =========================================================================
    // Bool 필드
    // =========================================================================

    public function testNormalizeFormDataBoolFieldWithTrueValues(): void
    {
        $trueValues = ['1', 'true', 'Y', 'yes', 'on'];

        foreach ($trueValues as $value) {
            $result = FormHelper::normalizeFormData(
                ['is_active' => $value],
                ['bool' => ['is_active']]
            );
            $this->assertEquals(1, $result['is_active'],
                "값 '{$value}'은 1로 변환되어야 합니다");
        }
    }

    public function testNormalizeFormDataBoolFieldWithFalseValues(): void
    {
        $falseValues = ['0', 'false', 'N', 'no', 'off', ''];

        foreach ($falseValues as $value) {
            $result = FormHelper::normalizeFormData(
                ['is_active' => $value],
                ['bool' => ['is_active']]
            );
            $this->assertEquals(0, $result['is_active'],
                "값 '{$value}'은 0으로 변환되어야 합니다");
        }
    }

    public function testNormalizeFormDataBoolFieldDefaultsToZeroWhenMissing(): void
    {
        // 체크박스 해제 시 키가 미전송 → 0으로 기본값 설정
        $result = FormHelper::normalizeFormData(
            ['other_field' => 'value'],
            ['bool' => ['is_active']]
        );

        $this->assertArrayHasKey('is_active', $result);
        $this->assertEquals(0, $result['is_active']);
    }

    // =========================================================================
    // Enum 필드
    // =========================================================================

    public function testNormalizeFormDataEnumFieldWithValidValue(): void
    {
        $result = FormHelper::normalizeFormData(
            ['status' => 'active'],
            ['enum' => ['status' => ['values' => ['active', 'inactive'], 'default' => 'active']]]
        );

        $this->assertEquals('active', $result['status']);
    }

    public function testNormalizeFormDataEnumFieldWithInvalidValue(): void
    {
        $result = FormHelper::normalizeFormData(
            ['status' => 'invalid_value'],
            ['enum' => ['status' => ['values' => ['active', 'inactive'], 'default' => 'active']]]
        );

        // 유효하지 않은 값은 default로
        $this->assertEquals('active', $result['status']);
    }

    public function testNormalizeFormDataEnumFieldWithEmptyValue(): void
    {
        $result = FormHelper::normalizeFormData(
            ['status' => ''],
            ['enum' => ['status' => ['values' => ['active', 'inactive'], 'default' => 'inactive']]]
        );

        $this->assertEquals('inactive', $result['status']);
    }

    public function testNormalizeFormDataEnumFieldDefaultsWhenMissing(): void
    {
        $result = FormHelper::normalizeFormData(
            ['other_field' => 'value'],
            ['enum' => ['status' => ['values' => ['a', 'b'], 'default' => 'a']]]
        );

        $this->assertEquals('a', $result['status']);
    }

    // =========================================================================
    // JSON 필드
    // =========================================================================

    public function testNormalizeFormDataJsonFieldWithValidJson(): void
    {
        $json = '{"key":"value","count":42}';
        $result = FormHelper::normalizeFormData(
            ['config' => $json],
            ['json' => ['config']]
        );

        $this->assertEquals($json, $result['config']);
    }

    public function testNormalizeFormDataJsonFieldWithInvalidJson(): void
    {
        $result = FormHelper::normalizeFormData(
            ['config' => 'not valid json {'],
            ['json' => ['config']]
        );

        $this->assertNull($result['config']);
    }

    public function testNormalizeFormDataJsonFieldWithEmptyValue(): void
    {
        $result = FormHelper::normalizeFormData(
            ['config' => ''],
            ['json' => ['config']]
        );

        $this->assertNull($result['config']);
    }

    // =========================================================================
    // Required String 필드
    // =========================================================================

    public function testNormalizeFormDataRequiredStringField(): void
    {
        $result = FormHelper::normalizeFormData(
            ['domain' => '  example.com  '],
            ['required_string' => ['domain']]
        );

        $this->assertNotNull($result['domain']);
        $this->assertNotEmpty($result['domain']);
    }

    // =========================================================================
    // 배열 값
    // =========================================================================

    public function testNormalizeFormDataPassesThroughArrayValues(): void
    {
        $data = ['tags' => ['php', 'framework', 'open-source']];
        $result = FormHelper::normalizeFormData($data);

        $this->assertEquals(['php', 'framework', 'open-source'], $result['tags']);
    }

    // =========================================================================
    // 복합 스키마
    // =========================================================================

    public function testNormalizeFormDataWithComplexSchema(): void
    {
        $formData = [
            'member_id' => '1',
            'storage_limit' => '100',
            'start_date' => '2024-01-01',
            'end_date' => '',
            'domain' => 'example.com',
            'status' => 'active',
            'is_public' => '1',
        ];

        $schema = [
            'numeric' => ['member_id', 'storage_limit'],
            'date' => ['start_date', 'end_date'],
            'required_string' => ['domain'],
            'bool' => ['is_public'],
            'enum' => [
                'status' => ['values' => ['active', 'inactive'], 'default' => 'active'],
            ],
        ];

        $result = FormHelper::normalizeFormData($formData, $schema);

        $this->assertEquals(1, $result['member_id']);
        $this->assertEquals(100, $result['storage_limit']);
        $this->assertEquals('2024-01-01', $result['start_date']);
        $this->assertNull($result['end_date']);
        $this->assertNotEmpty($result['domain']);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals(1, $result['is_public']);
    }

    // =========================================================================
    // normalizeFileData
    // =========================================================================

    public function testNormalizeFileDataReturnsEmptyForEmptyInput(): void
    {
        $result = FormHelper::normalizeFileData([]);
        $this->assertEmpty($result);
    }

    public function testNormalizeFileDataWithMultipleFields(): void
    {
        $fileData = [
            'name' => [
                'logo' => 'logo.png',
                'favicon' => 'favicon.ico',
            ],
            'type' => [
                'logo' => 'image/png',
                'favicon' => 'image/x-icon',
            ],
            'tmp_name' => [
                'logo' => '/tmp/logo.tmp',
                'favicon' => '/tmp/favicon.tmp',
            ],
            'error' => [
                'logo' => UPLOAD_ERR_OK,
                'favicon' => UPLOAD_ERR_OK,
            ],
            'size' => [
                'logo' => 1024,
                'favicon' => 512,
            ],
        ];

        $result = FormHelper::normalizeFileData($fileData);

        $this->assertArrayHasKey('logo', $result);
        $this->assertArrayHasKey('favicon', $result);
        $this->assertEquals('logo.png', $result['logo']['name']);
        $this->assertEquals('favicon.ico', $result['favicon']['name']);
    }

    public function testNormalizeFileDataSkipsFilesWithErrors(): void
    {
        $fileData = [
            'name' => [
                'valid_file' => 'image.png',
                'no_file' => '',
            ],
            'type' => ['valid_file' => 'image/png', 'no_file' => ''],
            'tmp_name' => ['valid_file' => '/tmp/img.tmp', 'no_file' => ''],
            'error' => ['valid_file' => UPLOAD_ERR_OK, 'no_file' => UPLOAD_ERR_NO_FILE],
            'size' => ['valid_file' => 1024, 'no_file' => 0],
        ];

        $result = FormHelper::normalizeFileData($fileData);

        $this->assertArrayHasKey('valid_file', $result);
        $this->assertArrayNotHasKey('no_file', $result);
    }

    // =========================================================================
    // validateUploadedFile
    // =========================================================================

    /**
     * 실제 파일로 검증한다.
     *
     * 예전 테스트는 tmp_name 을 빈 문자열로 두고도 통과했다. 검증기가 파일 내용을
     * 한 번도 들여다보지 않았다는 뜻이라 "유효한 업로드가 통과한다" 를 보증하지
     * 못했다. 지금은 확장자와 실제 내용이 일치해야 통과한다.
     */
    public function testValidateUploadedFileWithValidFile(): void
    {
        $path = $this->makePngTempFile();

        $result = FormHelper::validateUploadedFile([
            'name' => 'test.png',
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ], ['maxSize' => 5 * 1024 * 1024]);

        @unlink($path);

        $this->assertTrue($result['valid'], (string) $result['error']);
        $this->assertNull($result['error']);
    }

    /**
     * 확장자를 위조한 파일은 통과하지 못한다 — 코어 allowlist 가 내용과 대조한다.
     */
    public function testValidateUploadedFileRejectsExtensionMismatch(): void
    {
        // 내용은 PNG 인데 이름은 .pdf
        $path = $this->makePngTempFile();

        $result = FormHelper::validateUploadedFile([
            'name' => 'invoice.pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ]);

        @unlink($path);

        $this->assertFalse($result['valid']);
    }

    /**
     * 내용을 판별할 수 없으면 통과가 아니라 거부다(fail-closed).
     */
    public function testValidateUploadedFileWithoutReadableTempFileIsRejected(): void
    {
        $result = FormHelper::validateUploadedFile([
            'name' => 'test.png',
            'tmp_name' => '',
            'error' => UPLOAD_ERR_OK,
            'size' => 1024,
        ]);

        $this->assertFalse($result['valid']);
    }

    /** 최소한의 유효한 1x1 PNG 를 임시 파일로 만든다. */
    private function makePngTempFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mublo_up_');
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='
        ));

        return $path;
    }

    public function testValidateUploadedFileWithUploadError(): void
    {
        $file = [
            'name' => '',
            'error' => UPLOAD_ERR_NO_FILE,
            'size' => 0,
            'tmp_name' => '',
            'type' => '',
        ];

        $result = FormHelper::validateUploadedFile($file);

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['error']);
    }

    public function testValidateUploadedFileExceedingMaxSize(): void
    {
        $file = [
            'name' => 'large.jpg',
            'error' => UPLOAD_ERR_OK,
            'size' => 10 * 1024 * 1024, // 10MB
            'tmp_name' => '',
            'type' => 'image/jpeg',
        ];

        $result = FormHelper::validateUploadedFile($file, ['maxSize' => 5 * 1024 * 1024]); // 5MB 제한

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('MB', $result['error']);
    }

    public function testValidateUploadedFileWithDisallowedExtension(): void
    {
        $file = [
            'name' => 'script.php',
            'error' => UPLOAD_ERR_OK,
            'size' => 100,
            'tmp_name' => '',
            'type' => 'text/plain',
        ];

        $result = FormHelper::validateUploadedFile($file, [
            'allowedExtensions' => ['jpg', 'png', 'gif'],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('확장자', $result['error']);
    }

    // =========================================================================
    // getUploadErrorMessage
    // =========================================================================

    public function testGetUploadErrorMessageForKnownErrors(): void
    {
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_INI_SIZE));
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_FORM_SIZE));
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_PARTIAL));
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_NO_FILE));
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_NO_TMP_DIR));
        $this->assertNotEmpty(FormHelper::getUploadErrorMessage(UPLOAD_ERR_CANT_WRITE));
    }

    public function testGetUploadErrorMessageForUnknownError(): void
    {
        $message = FormHelper::getUploadErrorMessage(999);
        $this->assertNotEmpty($message);
        $this->assertStringContainsString('알 수 없는', $message);
    }
}
