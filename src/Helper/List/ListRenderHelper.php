<?php
declare(strict_types=1);

namespace Mublo\Helper\List;

use Exception;

/**
 * ListRenderHelper
 *
 * PHP 기반 모든 관리자/프런트 리스트를 "스킨 기반"으로 렌더링하는 공통 헬퍼
 *
 * ✔ table / div / grid / card 등 어떤 레이아웃도 스킨(template) 변경만으로 출력 가능
 * ✔ columns 정의만 바꾸면 즉시 UI가 변경됨
 * ✔ callback 으로 자유로운 HTML 출력 가능
 * ✔ 모든 Attribute 규칙이 통일되어 유지보수성 극대화
 *
 * [지원 타입]
 * - text        : 기본 텍스트 출력 (기본값)
 * - html        : HTML 그대로 출력 (escape 없음)
 * - badge       : 배지 출력
 * - image       : 이미지 태그 출력
 * - link        : a 태그 출력
 * - select      : <select> 렌더링
 * - radio       : 라디오 그룹
 * - checkbox    : 체크박스 출력
 * - toggle      : ON/OFF 스위치 형태
 * - actions     : 수정/삭제 등 버튼 그룹 자동 출력
 * - callback    : PHP 함수(closure)를 사용하여 HTML 직접 생성
 *
 * [Attribute 구조 – 통일 규칙]
 * - _wrap_attr : 전체 컨테이너(wrapper)
 * - _th_attr   : 헤더 셀 wrapper (thead)
 * - _row_attr  : 행 wrapper (tbody)
 * - _cell_attr : 데이터 셀 wrapper (tbody)
 * - _el_attr   : 셀 내부 요소
 *
 * 사용 예시:
 * echo (new ListRenderHelper)
 *      ->setColumns($columns)
 *      ->setRows($rows)
 *      ->setSkin('table/basic')
 *      ->setWrapAttr(['class' => 'table table-striped'])
 *      ->showHeader(true)
 *      ->render();
 */
class ListRenderHelper
{
    protected array $headerSchema = [];
    protected array $columns = [];
    protected array $rows = [];
    protected array $wrapAttr = [];
    protected bool $showHeader = true;
    protected string $skin = 'table/basic';
    protected array $config = [];
    /** @var string|null 빈 목록 문구 (null 이면 스킨 기본값) */
    protected ?string $emptyText = null;
    /** @var callable|null 행 속성 콜백 */
    protected $trAttrCallback = null;
    /** @var array|null 정렬 컨텍스트 ['field'=>?, 'order'=>'ASC'|'DESC', 'query'=>array] */
    protected ?array $sort = null;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default_skin' => 'table/basic',
            'skins_path' => dirname(__DIR__, 3) . '/views/List',
        ], $config);

        $this->skin = $this->config['default_skin'];
    }

    /**
     * 헤더 스키마 설정 (선택적)
     */
    public function setHeaderSchema(array $schema): self
    {
        $this->headerSchema = $schema;
        return $this;
    }

    /**
     * 컬럼 정의
     */
    public function setColumns(array $columns): self
    {
        $this->columns = $columns;
        return $this;
    }

    /**
     * row 배열 설정
     */
    public function setRows(array $rows): self
    {
        $this->rows = $rows;
        return $this;
    }

    /**
     * 컨테이너 속성 설정 (_wrap_attr)
     */
    public function setWrapAttr(array $attrs): self
    {
        $this->wrapAttr = $attrs;
        return $this;
    }

    /**
     * table header(title) 출력 여부
     */
    public function showHeader(bool $bool): self
    {
        $this->showHeader = $bool;
        return $this;
    }

    /**
     * 스킨 지정
     * ex) table/basic, div/basic, Plugin/Gallery/card
     */
    public function setSkin(string $skin): self
    {
        $this->skin = $skin;
        return $this;
    }

    /**
     * 빈 목록 문구 설정
     *
     * 검색 중("조건에 맞는 항목이 없습니다")과 진짜 빈 목록("아직 없습니다. 추가하세요")은
     * 다른 안내가 필요하다. 설정하지 않으면 스킨 기본 문구("데이터가 없습니다.")가 나온다.
     *
     * HTML 은 이스케이프된다. 줄바꿈이 필요하면 "\n" 을 넣는다 — 스킨이 nl2br 로 살린다.
     */
    public function setEmptyText(string $text): self
    {
        $this->emptyText = $text;
        return $this;
    }

    /**
     * 행(tr) 속성 콜백 설정
     *
     * @param callable $callback fn($row) => ['data-id' => $row['id'], ...]
     */
    public function setTrAttr(callable $callback): self
    {
        $this->trAttrCallback = $callback;
        return $this;
    }

    /**
     * 정렬 컨텍스트 설정 (설정 시에만 sortable 헤더가 클릭 가능한 링크로 렌더됨)
     *
     * @param string|null $field 현재 정렬 컬럼
     * @param string      $order 현재 정렬 방향 (ASC|DESC)
     * @param array       $query 정렬 링크에 보존할 기존 쿼리스트링 (검색어 등)
     */
    public function setSort(?string $field, string $order = 'DESC', array $query = []): self
    {
        $this->sort = [
            'field' => $field,
            'order' => strtoupper($order) === 'ASC' ? 'ASC' : 'DESC',
            'query' => $query,
        ];
        return $this;
    }

    /**
     * 정렬 헤더 링크 정보 생성 (스킨에서 호출)
     *
     * 정렬 컨텍스트가 없거나 sortable 컬럼이 아니면 null 반환 → 스킨은 기존 동작 유지
     *
     * @return array{url:string,active:bool,order:?string}|null
     */
    public function sortLink(array $col): ?array
    {
        if ($this->sort === null || empty($col['sortable'])) {
            return null;
        }

        $field = $col['sort_key'] ?? $col['key'];
        $isActive = ($this->sort['field'] === $field);

        // 활성 컬럼이면 방향 토글, 아니면 ASC부터 시작
        $nextOrder = ($isActive && $this->sort['order'] === 'ASC') ? 'DESC' : 'ASC';

        $query = array_merge($this->sort['query'], [
            'sort'  => $field,
            'order' => $nextOrder,
        ]);
        unset($query['page']); // 정렬 변경 시 1페이지로 이동

        return [
            'url'    => '?' . http_build_query($query),
            'active' => $isActive,
            'order'  => $isActive ? $this->sort['order'] : null,
        ];
    }

    /**
     * 행 속성 빌드
     */
    public function buildTrAttr(array $row): string
    {
        if ($this->trAttrCallback === null) {
            return '';
        }

        $attrs = call_user_func($this->trAttrCallback, $row);
        return $this->buildAttr($attrs);
    }

    /**
     * 스킨 경로 해결
     */
    protected function resolveSkinPath(string $skin): string
    {
        // 1. 플러그인 스킨 우선 확인
        if (str_starts_with($skin, 'Plugin/')) {
            $pluginSkin = dirname(__DIR__, 2) . "/{$skin}.php";
            if (file_exists($pluginSkin)) {
                return $pluginSkin;
            }
        }

        // 2. 코어 스킨 확인
        $coreSkin = $this->config['skins_path'] . "/{$skin}.php";
        if (file_exists($coreSkin)) {
            return $coreSkin;
        }

        throw new Exception("Skin not found: {$skin}");
    }

    /**
     * 스킨 렌더링 (HTML 구조는 스킨 파일이 결정)
     */
    public function render(): string
    {
        $skinFile = $this->resolveSkinPath($this->skin);

        $headerSchema = $this->headerSchema ?? [];
        $columns = $this->columns;
        $rows = $this->rows;
        $wrapAttr = $this->buildAttr($this->wrapAttr);
        $showHeader = $this->showHeader;
        $emptyText = $this->emptyText;
        $self = $this;

        ob_start();
        include $skinFile;
        return ob_get_clean();
    }

    /**
     * 셀 렌더링 타입 처리
     */
    public function renderCell(array $row, array $col): string
    {
        $type = $col['type'] ?? 'text';

        return match ($type) {
            'select'    => $this->renderSelect($row, $col),
            'radio'     => $this->renderRadio($row, $col),
            'checkbox'  => $this->renderCheckbox($row, $col),
            'toggle'    => $this->renderToggle($row, $col),
            'image'     => $this->renderImage($row, $col),
            'badge'     => $this->renderBadge($row, $col),
            'link'      => $this->renderLink($row, $col),
            'actions'   => $this->renderActions($row, $col),
            'callback'  => $this->renderCallback($row, $col),
            // strict_types 파일이라 int/float 셀 값이 그대로 오면 htmlspecialchars 가
            // TypeError 로 죽는다 (예: 메뉴 목록의 item_id). 문자열로 좁혀서 받는다.
            'html'      => (string) ($row[$col['key']] ?? ''),
            default     => htmlspecialchars((string) ($row[$col['key']] ?? ''), ENT_QUOTES, 'UTF-8'),
        };
    }

    /**
     * TYPE: SELECT
     *
     * id_key 옵션으로 ID 필드 지정 가능 (기본: 'id')
     */
    protected function renderSelect(array $row, array $col): string
    {
        $key = $col['key'];
        $idKey = $col['id_key'] ?? 'id';
        $id = $row[$idKey] ?? $row['id'] ?? '';
        $value = $row[$key] ?? '';
        $options = $col['options'] ?? [];
        $disabledValues = $col['disabled_values'] ?? [];
        $disabledRows = $col['disabled_rows'] ?? [];
        $elAttr = $this->buildAttr($col['_el_attr'] ?? ['class' => 'form-select']);

        $eKey = htmlspecialchars($key, ENT_QUOTES, 'UTF-8');
        $eId = htmlspecialchars((string) $id, ENT_QUOTES, 'UTF-8');
        // 특정 행(예: 본인)은 select 자체를 비활성화
        $rowDisabled = in_array($id, $disabledRows) ? ' disabled' : '';
        $html = '<select name="' . $eKey . '[' . $eId . ']" ' . $elAttr . $rowDisabled . '>';

        foreach ($options as $k => $label) {
            $selected = ($k == $value) ? 'selected' : '';
            $disabled = in_array($k, $disabledValues) ? 'disabled' : '';
            $eK = htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8');
            $eLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
            $html .= '<option value="' . $eK . '" ' . $selected . ' ' . $disabled . '>' . $eLabel . '</option>';
        }

        return $html . '</select>';
    }

    /**
     * TYPE: RADIO
     */
    protected function renderRadio(array $row, array $col): string
    {
        $key = $col['key'];
        $id = $row['id'] ?? '';
        $value = $row[$key] ?? '';
        $options = $col['options'] ?? [];
        $elAttr = $this->buildAttr($col['_el_attr'] ?? []);

        $html = '';

        foreach ($options as $k => $label) {
            $checked = ($k == $value) ? 'checked' : '';
            $html .= '<label style="margin-right:8px">
                <input type="radio" name="' . $key . '[' . $id . ']" value="' . $k . '" ' . $checked . ' ' . $elAttr . '> ' . $label . '
            </label>';
        }

        return $html;
    }

    /**
     * TYPE: CHECKBOX
     *
     * 두 가지 용도:
     * 1. 일괄 선택용 (id_key가 설정됨): name="key[]" value="{id}"
     * 2. 데이터 필드용 (기존): name="key[{id}]" value="Y"
     *
     * 옵션:
     * - skip_key: 해당 키의 값이 truthy면 체크박스를 숨김 (빈 문자열 반환)
     */
    protected function renderCheckbox(array $row, array $col): string
    {
        // skip 조건 체크
        $skipKey = $col['skip_key'] ?? null;
        if ($skipKey && !empty($row[$skipKey])) {
            return '';
        }

        $key = $col['key'];
        $elAttr = $this->buildAttr($col['_el_attr'] ?? []);

        // 일괄 선택용 체크박스 (id_key가 설정된 경우)
        if (isset($col['id_key'])) {
            $idKey = $col['id_key'];
            $id = $row[$idKey] ?? $row['id'] ?? '';
            return '<input type="checkbox" name="' . $key . '[]" value="' . $id . '" class="form-check-input" ' . $elAttr . '>';
        }

        // 데이터 필드용 체크박스 (기존 동작)
        $id = $row['id'] ?? '';
        $checked = (($row[$key] ?? 'N') === 'Y') ? 'checked' : '';
        return '<input type="checkbox" name="' . $key . '[' . $id . ']" value="Y" ' . $checked . ' ' . $elAttr . '>';
    }

    /**
     * TYPE: TOGGLE
     */
    protected function renderToggle(array $row, array $col): string
    {
        $key = $col['key'];
        $id = $row['id'] ?? '';
        $checked = (($row[$key] ?? 'N') === 'Y') ? 'checked' : '';

        return <<<HTML
            <label class="switch">
                <input type="checkbox" name="{$key}[{$id}]" value="Y" {$checked}>
                <span class="slider"></span>
            </label>
        HTML;
    }

    /**
     * TYPE: IMAGE
     */
    protected function renderImage(array $row, array $col): string
    {
        $src = $row[$col['key']] ?? '';
        if (!$src) return '';

        $elAttr = $this->buildAttr($col['_el_attr'] ?? []);

        $eSrc = htmlspecialchars((string) $src, ENT_QUOTES, 'UTF-8');
        return '<img src="' . $eSrc . '" ' . $elAttr . '>';
    }

    /**
     * TYPE: BADGE
     */
    protected function renderBadge(array $row, array $col): string
    {
        $value = htmlspecialchars((string) ($row[$col['key']] ?? ''), ENT_QUOTES, 'UTF-8');
        $class = htmlspecialchars($col['class'] ?? 'badge bg-info', ENT_QUOTES, 'UTF-8');
        return '<span class="' . $class . '">' . $value . '</span>';
    }

    /**
     * TYPE: LINK
     */
    protected function renderLink(array $row, array $col): string
    {
        $url = $row[$col['key']] ?? '';
        if (!$url) return '';

        $label = $col['label'] ?? $url;
        $elAttr = $this->buildAttr($col['_el_attr'] ?? []);

        $eUrl = htmlspecialchars((string) $url, ENT_QUOTES, 'UTF-8');
        $eLabel = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
        return '<a href="' . $eUrl . '" target="_blank" ' . $elAttr . '>' . $eLabel . '</a>';
    }

    /**
     * TYPE: ACTION BUTTONS
     */
    protected function renderActions(array $row, array $col): string
    {
        if (!isset($col['buttons'])) return '';

        $id = $row['id'];
        $html = '';

        foreach ($col['buttons'] as $btn) {
            $label = htmlspecialchars($btn['label'], ENT_QUOTES, 'UTF-8');
            $url = htmlspecialchars(str_replace('{id}', $id, $btn['url']), ENT_QUOTES, 'UTF-8');
            $attr = $this->buildAttr($btn['_el_attr'] ?? []);

            $html .= '<a href="' . $url . '" ' . $attr . '>' . $label . '</a> ';
        }

        return $html;
    }

    /**
     * TYPE: CALLBACK
     */
    protected function renderCallback(array $row, array $col): string
    {
        return isset($col['callback']) ? call_user_func($col['callback'], $row) : '';
    }

    /**
     * 공통 attribute builder
     */
    public function buildAttr(array $attr): string
    {
        $result = [];

        foreach ($attr as $key => $val) {
            if ($key === 'data') {
                foreach ($val as $k => $v) {
                    $escaped = htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
                    $result[] = 'data-' . $k . '="' . $escaped . '"';
                }
            } else {
                $escaped = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
                $result[] = $key . '="' . $escaped . '"';
            }
        }

        return implode(' ', $result);
    }
}
