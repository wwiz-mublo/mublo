<?php
declare(strict_types=1);

namespace Mublo\Service\CustomField;

use Mublo\Core\Container\DependencyContainer;
use Mublo\Core\Rendering\AssetManager;

/**
 * CustomFieldRenderer
 *
 * 커스텀 필드 타입별 HTML 렌더링
 * 회원가입, 프로필, 체크아웃 등 모든 커스텀 필드 폼에서 공용 사용
 *
 * 사용법:
 *   // 필드 HTML 출력
 *   echo CustomFieldRenderer::render($field, $currentValue, [
 *       'namePrefix'   => 'formData[fields]',  // → name="formData[fields][{id}]"
 *       'idPrefix'     => 'field_',             // → id="field_{id}"
 *       'showExisting' => true,                 // 기존 파일 표시 (수정 모드)
 *   ]);
 *
 *   // 파일 업로드 JS (페이지 하단에 한 번)
 *   echo CustomFieldRenderer::renderFileScript('/member/upload-field-file');
 */
class CustomFieldRenderer
{
    /**
     * 단일 커스텀 필드 입력 요소 HTML 생성
     *
     * @param array $field 필드 정의 (field_id, field_type, field_options, field_config, is_required 등)
     * @param mixed $currentValue 현재 값 (null이면 새 입력, file은 메타 배열, address는 연관 배열)
     * @param array $options 렌더링 옵션:
     *   - namePrefix (string): 입력 name 접두사 (기본: 'fields')
     *   - idPrefix (string): HTML id 접두사 (기본: 'field_')
     *   - showExisting (bool): 기존 파일/값 표시 (수정 모드, 기본: false)
     * @return string 생성된 HTML
     */
    public static function render(array $field, mixed $currentValue = null, array $options = []): string
    {
        // 위젯 CSS는 어떤 필드 타입이든(파일 없어도) head에 등록 — 자기완결(스킨 비의존)
        self::registerWidgetCss();

        $fieldId = $field['field_id'];
        $fieldType = $field['field_type'] ?? 'text';
        $namePrefix = $options['namePrefix'] ?? 'fields';
        $idPrefix = $options['idPrefix'] ?? 'field_';
        $showExisting = $options['showExisting'] ?? false;
        $isRequired = !empty($field['is_required']) ? 'required' : '';

        $inputName = "{$namePrefix}[{$fieldId}]";
        $inputId = "{$idPrefix}{$fieldId}";

        // options 파싱 (JSON 문자열 또는 배열)
        $fieldOptions = $field['field_options'] ?? '[]';
        if (is_string($fieldOptions)) {
            $fieldOptions = json_decode($fieldOptions, true) ?: [];
        }

        ob_start();

        echo '<div class="custom-field-group">';

        // 라벨(필드 단위) — 컴포넌트 소유라 스킨 셀렉터에 안 샘
        $fieldLabel = $field['field_label'] ?? '';
        if ($fieldLabel !== '') {
            echo '<label class="custom-field-label" for="' . $inputId . '">'
                . self::esc($fieldLabel)
                . (!empty($field['is_required']) ? '<span class="custom-field-required">*</span>' : '')
                . '</label>';
        }

        switch ($fieldType) {
            case 'textarea':
                self::renderTextarea($inputId, $inputName, $currentValue, $isRequired);
                break;

            case 'select':
                self::renderSelect($inputId, $inputName, $fieldOptions, $currentValue, $isRequired);
                break;

            case 'radio':
                self::renderRadio($inputName, $fieldOptions, $currentValue, $isRequired);
                break;

            case 'checkbox':
                self::renderCheckbox($namePrefix, $fieldId, $fieldOptions, $currentValue);
                break;

            case 'address':
                self::renderAddress($inputId, $namePrefix, $fieldId, $currentValue, $isRequired);
                break;

            case 'file':
            case 'avatar':
                $fieldConfig = $field['field_config'] ?? '{}';
                if (is_string($fieldConfig)) {
                    $fieldConfig = json_decode($fieldConfig, true) ?: [];
                }
                self::renderFile($inputId, $inputName, $fieldId, $idPrefix, $fieldConfig, $currentValue, $showExisting, $fieldType === 'avatar');
                break;

            default:
                self::renderInput($inputId, $inputName, $fieldType, $currentValue, $isRequired);
                break;
        }

        // 설명/안내 (member=field_description, shop order=placeholder)
        $fieldDesc = $field['field_description'] ?? ($field['placeholder'] ?? '');
        if ($fieldDesc !== '') {
            echo '<div class="custom-field-help">' . self::esc($fieldDesc) . '</div>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    /**
     * 파일 업로드 + 커스텀 필드 공용 JavaScript 로드
     *
     * 페이지당 한 번만 호출. MubloCustomField.js를 로드하고 uploadUrl을 설정.
     *
     * @param string $uploadUrl 파일 업로드 API URL
     * @return string script 태그 HTML
     */
    public static function renderFileScript(string $uploadUrl): string
    {
        $jsUrl = json_encode($uploadUrl);
        $assetPath = self::esc(asset('/assets/js/MubloCustomField.js'));  // ?filemtime 캐시버스팅(Cloudflare)

        // 공용 위젯 CSS를 head에 등록(파일 없는 페이지는 render()가 이미 등록).
        self::registerWidgetCss();

        return '<script src="' . $assetPath . '"></script>'
            . '<script>MubloCustomField.setUploadUrl(' . $jsUrl . ');</script>';
    }

    /**
     * 커스텀 필드 위젯 공용 CSS를 컴포넌트 대역에 등록.
     * AssetManager 싱글톤을 통해 등록 → FrontViewRenderer가 <!-- Component CSS -->에 출력.
     * 중복 제거되므로 여러 번 호출돼도 안전.
     */
    private static function registerWidgetCss(): void
    {
        try {
            DependencyContainer::getInstance()
                ->get(AssetManager::class)
                ->addCss('/assets/css/components/mublo-custom-field.css', 'component');
        } catch (\Throwable) {
            // AssetManager를 못 구하는 컨텍스트(헤드리스 등)는 조용히 무시
        }
    }

    /** HTML 이스케이프 중앙화 (속성·텍스트 공용, ENT_QUOTES). */
    private static function esc(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    // =========================================================================
    // 타입별 렌더링 (Private)
    // =========================================================================

    private static function renderTextarea(string $id, string $name, mixed $value, string $required): void
    {
        $val = self::esc(is_string($value) ? $value : '');
        echo '<textarea id="' . $id . '" name="' . $name . '" class="custom-field-textarea" rows="3" ' . $required . '>' . $val . '</textarea>';
    }

    private static function renderSelect(string $id, string $name, array $options, mixed $currentValue, string $required): void
    {
        echo '<select id="' . $id . '" name="' . $name . '" class="custom-field-select" ' . $required . '>';
        echo '<option value="">선택하세요</option>';

        foreach ($options as $opt) {
            $optValue = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $optLabel = is_array($opt) ? ($opt['label'] ?? $optValue) : $opt;
            $selected = ((string) $currentValue === (string) $optValue) ? ' selected' : '';
            echo '<option value="' . self::esc($optValue) . '"' . $selected . '>'
                . self::esc($optLabel) . '</option>';
        }

        echo '</select>';
    }

    private static function renderRadio(string $name, array $options, mixed $currentValue, string $required): void
    {
        echo '<div class="custom-field-radio">';

        foreach ($options as $opt) {
            $optValue = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $optLabel = is_array($opt) ? ($opt['label'] ?? $optValue) : $opt;
            $checked = ((string) $currentValue === (string) $optValue) ? ' checked' : '';
            echo '<label><input type="radio" name="' . $name . '" value="'
                . self::esc($optValue) . '" ' . $required . $checked . '>'
                . self::esc($optLabel) . '</label>';
        }

        echo '</div>';
    }

    private static function renderCheckbox(string $namePrefix, int $fieldId, array $options, mixed $currentValue): void
    {
        // 현재 체크된 값 파싱 (배열 또는 콤마 구분 문자열)
        $checkedValues = [];
        if (is_array($currentValue)) {
            $checkedValues = $currentValue;
        } elseif (is_string($currentValue) && $currentValue !== '') {
            $checkedValues = explode(',', $currentValue);
        }

        $checkboxName = "{$namePrefix}[{$fieldId}][]";
        echo '<div class="custom-field-checkbox">';

        foreach ($options as $opt) {
            $optValue = is_array($opt) ? ($opt['value'] ?? '') : $opt;
            $optLabel = is_array($opt) ? ($opt['label'] ?? $optValue) : $opt;
            $checked = in_array($optValue, $checkedValues) ? ' checked' : '';
            echo '<label><input type="checkbox" name="' . $checkboxName . '" value="'
                . self::esc($optValue) . '"' . $checked . '>'
                . self::esc($optLabel) . '</label>';
        }

        echo '</div>';
    }

    private static function renderAddress(string $id, string $namePrefix, int $fieldId, mixed $currentValue, string $required): void
    {
        $zipcode = '';
        $address1 = '';
        $address2 = '';

        if (is_array($currentValue)) {
            $zipcode = $currentValue['zipcode'] ?? '';
            $address1 = $currentValue['address1'] ?? '';
            $address2 = $currentValue['address2'] ?? '';
        }

        $baseName = "{$namePrefix}[{$fieldId}]";

        echo '<div class="custom-field-address">';
        echo '<div class="custom-field-address-row">';
        echo '<input type="text" id="' . $id . '_zipcode" name="' . $baseName . '[zipcode]"'
            . ' class="custom-field-input custom-field-address-zipcode" placeholder="우편번호"'
            . ' value="' . self::esc($zipcode) . '" ' . $required . '>';
        echo '<button type="button" class="custom-field-address-search" onclick="MubloAddress.search(' . $fieldId . ')">주소검색</button>';
        echo '</div>';

        echo '<input type="text" id="' . $id . '_address1" name="' . $baseName . '[address1]"'
            . ' class="custom-field-input custom-field-address-main" placeholder="기본주소" readonly'
            . ' value="' . self::esc($address1) . '">';

        echo '<input type="text" id="' . $id . '_address2" name="' . $baseName . '[address2]"'
            . ' class="custom-field-input custom-field-address-detail" placeholder="상세주소"'
            . ' value="' . self::esc($address2) . '">';

        echo '</div>';
    }

    private static function renderFile(
        string $id,
        string $name,
        int $fieldId,
        string $idPrefix,
        array $config,
        mixed $currentValue,
        bool $showExisting,
        bool $isAvatar = false
    ): void {
        $maxSizeMb = $config['max_size'] ?? 5;
        $allowedExt = $config['allowed_ext'] ?? '';
        $fileMeta = is_array($currentValue) ? $currentValue : null;

        $metaId = $idPrefix . $fieldId . '_meta';
        $currentId = $idPrefix . $fieldId . '_current';
        $resultId = $idPrefix . $fieldId . '_result';
        $previewId = $idPrefix . $fieldId . '_preview';

        // avatar는 이미지 picker로 제한, 일반 file은 허용 확장자 기반 accept
        if ($isAvatar) {
            $acceptAttr = ' accept="image/*"';
        } else {
            $acceptAttr = '';
            if ($allowedExt) {
                $exts = array_map(fn($e) => '.' . trim($e), explode(',', $allowedExt));
                $acceptAttr = ' accept="' . implode(',', $exts) . '"';
            }
        }

        $inputClass = 'custom-field-file' . ($isAvatar ? ' custom-field-avatar' : '');

        echo '<div class="custom-field-file-group">';

        // Hidden: 파일 메타 JSON
        $metaValue = ($showExisting && $fileMeta) ? self::esc(json_encode($fileMeta)) : '';
        echo '<input type="hidden" name="' . $name . '" id="' . $metaId . '" value="' . $metaValue . '">';

        // avatar 미리보기 (기존 이미지가 있으면 표시, 신규 선택 시 JS가 갱신)
        if ($isAvatar) {
            $existingUrl = ($showExisting && $fileMeta && !empty($fileMeta['url'])) ? $fileMeta['url'] : '';
            $hasImg = $existingUrl !== '';
            echo '<div class="custom-field-avatar-preview" id="' . $previewId . '"'
                . ' data-existing-url="' . self::esc($existingUrl) . '"'
                . ($hasImg ? '' : ' style="display:none"') . '>';
            echo '<img id="' . $previewId . '_img" src="' . self::esc($existingUrl) . '" alt="아바타 미리보기">';
            echo '</div>';
        }

        // 기존 파일 표시 (수정 모드)
        if ($showExisting && $fileMeta && !empty($fileMeta['filename'])) {
            echo '<div class="custom-field-file-current" id="' . $currentId . '">';
            echo '<span class="custom-field-file-name">' . self::esc($fileMeta['filename']) . '</span>';
            if (!empty($fileMeta['size'])) {
                echo '<span class="custom-field-file-size">(' . round($fileMeta['size'] / 1024, 1) . 'KB)</span>';
            }
            echo '<button type="button" class="custom-field-file-delete" onclick="MubloCustomField.deleteExisting(\''
                . $idPrefix . '\', ' . $fieldId . ')">삭제</button>';
            echo '</div>';
        }

        // 파일 선택 — 네이티브 input은 시각 숨김, label을 가상 컨트롤로(브라우저 편차 제거)
        echo '<div class="custom-field-file-input-row">';
        echo '<input type="file" id="' . $id . '"'
            . ' class="' . $inputClass . '"'
            . ' data-field-id="' . $fieldId . '"'
            . ' data-id-prefix="' . $idPrefix . '"'
            . ' data-max-size="' . $maxSizeMb . '"'
            . $acceptAttr . '>';
        echo '<label for="' . $id . '" class="custom-field-file-trigger">';
        echo '<span class="custom-field-file-button">파일 선택</span>';
        echo '<span class="custom-field-file-chosen">선택된 파일 없음</span>';
        echo '</label>';
        echo '</div>';

        // 안내 텍스트 (파일 선택 바로 아래)
        if ($allowedExt) {
            echo '<div class="custom-field-help">허용 파일: ' . self::esc($allowedExt) . ' (최대 ' . $maxSizeMb . 'MB)</div>';
        } else {
            echo '<div class="custom-field-help">최대 ' . $maxSizeMb . 'MB</div>';
        }

        // 업로드 결과 표시
        echo '<div class="custom-field-file-result" id="' . $resultId . '">';
        echo '<span class="custom-field-file-name"></span>';
        echo '<button type="button" class="custom-field-file-remove"'
            . ' onclick="MubloCustomField.removeFile(\'' . $idPrefix . '\', ' . $fieldId . ')">취소</button>';
        echo '</div>';

        echo '</div>';
    }

    private static function renderInput(string $id, string $name, string $fieldType, mixed $value, string $required): void
    {
        $inputType = match ($fieldType) {
            'email' => 'email',
            'tel' => 'tel',
            'number' => 'number',
            'date' => 'date',
            default => 'text',
        };
        $val = self::esc(is_string($value) ? $value : '');
        $extraClass = ($fieldType === 'tel') ? ' mask-hp' : '';

        echo '<input type="' . $inputType . '" id="' . $id . '" name="' . $name . '"'
            . ' class="custom-field-input' . $extraClass . '" value="' . $val . '" ' . $required . '>';
    }
}
