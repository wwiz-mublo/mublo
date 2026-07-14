<?php

namespace Mublo\Helper\List;

/**
 * ListColumnBuilder
 *
 * 리스트 컬럼을 쉽게 정의하기 위한 빌더 클래스
 *
 * 사용 예시:
 * $columns = (new ListColumnBuilder())
 *     ->add('name', '이름')
 *     ->add('email', '이메일', ['sortable' => true])
 *     ->callback('status', '상태', function($row) {
 *         return $row['status'] === 'Y' ? '활성' : '비활성';
 *     })
 *     ->actions('actions', '관리', function($row) {
 *         return '<a href="/edit?id=' . $row['id'] . '">수정</a>';
 *     })
 *     ->build();
 */
class ListColumnBuilder
{
    protected array $columns = [];

    /**
     * 기본 text 컬럼 추가
     *
     * @param string $key 필드명
     * @param string $title 헤더명
     * @param array $options 추가 옵션
     *   - render: callable 렌더 콜백 (자동으로 callback 타입으로 변환)
     *   - type: 컬럼 타입 (기본: 'text')
     *   - sortable: 정렬 가능 여부
     * @return self
     */
    public function add(string $key, string $title, array $options = []): self
    {
        // render 콜백이 있으면 callback 타입으로 변환
        if (isset($options['render']) && is_callable($options['render'])) {
            $options['type'] = 'callback';
            $options['callback'] = $options['render'];
            unset($options['render']);
        }

        $column = [
            'key'      => $key,
            'title'    => $title,
            'type'     => $options['type'] ?? 'text',
            'sortable' => $options['sortable'] ?? false,
            'sort_key' => $key,
        ];

        // 사용자 입력 옵션 override
        $this->columns[] = array_merge($column, $options);
        return $this;
    }

    /**
     * 콜백 타입 컬럼
     *
     * @param string $key 필드명
     * @param string $title 헤더명
     * @param callable $callback 콜백 함수
     * @param array $options 추가 옵션
     * @return self
     */
    public function callback(string $key, string $title, callable $callback, array $options = []): self
    {
        $column = [
            'key'      => $key,
            'title'    => $title,
            'type'     => 'callback',
            'sortable' => false,
            'callback' => $callback,
        ];

        $this->columns[] = array_merge($column, $options);
        return $this;
    }

    /**
     * 액션 버튼 컬럼 (callback과 동일)
     *
     * @param string $key 필드명
     * @param string $title 헤더명
     * @param callable $callback 콜백 함수
     * @param array $options 추가 옵션
     * @return self
     */
    public function actions(string $key, string $title, callable $callback, array $options = []): self
    {
        return $this->callback($key, $title, $callback, $options);
    }

    /**
     * 체크박스 컬럼 (일괄 선택용)
     *
     * 헤더: 전체 선택 체크박스 (name="chk_all")
     * 본문: 개별 체크박스 (name="{key}[]" value="{id}")
     *
     * @param string $key 필드명 (예: 'chk')
     * @param string $title 헤더명 (빈 문자열이면 전체선택 체크박스만)
     * @param array $options 추가 옵션
     *   - id_key: ID 필드명 (기본: 'id')
     *   - skip_key: 해당 키의 값이 truthy면 체크박스 숨김
     *               (예: 'is_super' → 슈퍼관리자는 체크박스 없음)
     * @return self
     */
    public function checkbox(string $key, string $title = '', array $options = []): self
    {
        $column = [
            'key'      => $key,
            'title'    => $title,
            'type'     => 'checkbox',
            'sortable' => false,
            'id_key'   => $options['id_key'] ?? 'id',
        ];

        $this->columns[] = array_merge($column, $options);
        return $this;
    }

    /**
     * 여러 개의 text 컬럼 한번에 등록
     *
     * @param array $items ['key' => 'title', ...]
     * @param array $overrides 개별 컬럼 옵션 override
     * @return self
     *
     * 사용 예:
     * $col->texts([
     *     'name' => '이름',
     *     'price' => '가격',
     * ], [
     *     'price' => ['sortable' => true],
     * ]);
     */
    public function texts(array $items, array $overrides = []): self
    {
        foreach ($items as $key => $title) {
            $opt = $overrides[$key] ?? [];
            $this->add($key, $title, $opt);
        }
        return $this;
    }

    /**
     * Select 컬럼
     *
     * @param string $key 필드명
     * @param string $title 헤더명
     * @param array $options select options ['value' => 'label', ...]
     * @param array $config 추가 설정
     *   - id_key: ID 필드명 (기본: 'id') - name="key[{id}]" 형식으로 사용
     * @return self
     */
    public function select(string $key, string $title, array $options, array $config = []): self
    {
        $column = [
            'key'      => $key,
            'title'    => $title,
            'type'     => 'select',
            'options'  => $options,
            'sortable' => false,
        ];

        $this->columns[] = array_merge($column, $config);
        return $this;
    }

    /**
     * 결과 빌드
     *
     * @return array
     */
    public function build(): array
    {
        return $this->columns;
    }
}
