<?php
declare(strict_types=1);

namespace Mublo\Contract\Block;

/**
 * 블록 렌더러가 칸에서 읽을 수 있는 것.
 *
 * 렌더러는 칸 엔티티가 아니라 이 읽기 전용 뷰를 받는다. 엔티티에는 저장·정렬·
 * 스택 구성처럼 렌더링과 무관한 표면이 함께 있고, 그것까지 계약으로 굳으면
 * 코어가 칸 구조를 바꿀 때마다 설치된 모든 블록이 깨진다.
 *
 * 여기 있는 것과 없는 것
 * - 있다: 이 칸이 무엇을 어떻게 보여줄지 — 콘텐츠 설정·아이템·스킨·출력 갯수.
 * - 없다: 배경·테두리·여백·제목 영역. 칸의 껍데기는 코어가 그리고, 렌더러는
 *   그 안쪽만 채운다. 제목 영역은 toTitleView() 로 완성된 형태만 건네받는다.
 * - 없다: setter, toArray(), 행 ID, 정렬 순서, 스택 자식 구성. 코어 내부다.
 *
 * 부족한 것이 생기면 메서드를 더한다. 추가는 구현체가 BlockColumn 하나뿐이라
 * 비파괴적이고, 제거는 그렇지 않다.
 */
interface BlockColumnView
{
    /** 칸 ID. 스킨이 DOM id 를 만들 때 쓴다. */
    public function getColumnId(): int;

    /** 이 칸이 속한 도메인. 확장이 자기 데이터를 조회할 때의 범위다. */
    public function getDomainId(): int;

    /** 렌더 캐시 키. 같은 칸이라도 스택 자식마다 달라진다. */
    public function getRenderKey(): string;

    /** 운영자가 고른 스킨명 (basic, gallery 등). */
    public function getContentSkin(): ?string;

    /** 콘텐츠 설정. 타입마다 키가 다르며 해석은 타입 소유자의 몫이다. */
    public function getContentConfig(): ?array;

    /** 운영자가 고른 출력 대상 (게시판 ID, 배너 ID 등). */
    public function getContentItems(): ?array;

    /**
     * PC 자동재생 간격(ms). 0 이면 자동재생 없음.
     *
     * 슬라이드형 스킨이 자기 런타임을 초기화할 때 운영자 설정을 지켜야 하므로
     * 원값이 필요하다. getLayoutDataAttributes() 는 코어의 MubloItemLayout 용
     * data 속성이라, 자체 마크업을 그리는 스킨에는 닿지 않는다.
     */
    public function getPcAutoplay(): int;

    /** 모바일 자동재생 간격(ms). 0 이면 자동재생 없음. */
    public function getMoAutoplay(): int;

    /** PC 출력 갯수. */
    public function getPcCount(): int;

    /** 모바일 출력 갯수. */
    public function getMoCount(): int;

    /**
     * MubloItemLayout 용 data-* 속성 문자열.
     *
     * 스킨은 `<div class="mublo-item-layout" <?= $column->getLayoutDataAttributes() ?>>`
     * 로 쓴다. 레이아웃 원값(슬라이드 여부·열 수·자동재생 등)을 개별로 노출하지
     * 않는 이유는, 런타임이 그 값들을 어떻게 소비하는지가 코어의 구현이기 때문이다.
     */
    public function getLayoutDataAttributes(): string;

    /**
     * 제목 영역에 필요한 값 묶음.
     *
     * 제목·문구·더보기 링크는 운영자가 관리자에서 설정하고, 코어의 공용 파셜
     * (views/Block/_shared/title.php)이 그린다. 렌더러는 이 배열을 스킨에
     * 넘기기만 하면 된다. 스킨이 제목을 직접 그리려면 파셜을 오버라이드한다.
     *
     * @return array<string, mixed>
     */
    public function toTitleView(): array;
}
