<?php
declare(strict_types=1);

namespace Mublo\Core\Event\Mypage;

use Mublo\Core\Event\AbstractEvent;

/**
 * 마이페이지 섹션 등록 이벤트
 *
 * 코어가 발행 → 패키지(Board/Shop 등)가 구독하여 마이페이지 "섹션"을 등록한다.
 * AdminMenuBuildingEvent와 동일한 패턴.
 *
 * 원칙(P2 "액자/그림"):
 *   - 마이페이지는 코어 소유 "액자"(라우트 + 셸). 패키지는 "그림"(뷰 + 데이터)만 등록.
 *
 * 등록 두 종류:
 *   - registerHub:    패키지당 마이페이지 허브 1개 → /mypage/{provider} (권장 관례, 예: 마이쇼핑 요약).
 *   - registerSection: 세부 섹션 → /mypage/{provider}/{key} (레거시/다중 섹션, 예: Board 글/댓글).
 */
class MypageSectionBuildingEvent extends AbstractEvent
{
    /** @var array<string, array<string, array>> provider => key => section */
    private array $sections = [];

    /** @var array<string, array> provider => hub */
    private array $hubs = [];

    private string $currentProvider = 'core';

    /**
     * 등록 출처(provider) 지정. 이후 registerSection 호출이 이 provider 네임스페이스로 묶인다.
     */
    public function setSource(string $provider): self
    {
        $this->currentProvider = strtolower($provider);
        return $this;
    }

    /**
     * 마이페이지 섹션 등록.
     *
     * @param string   $key          섹션 키 (provider 내 유일). 예: 'articles'
     * @param string   $label        사이드바/제목용 라벨
     * @param string   $viewPath     콘텐츠 partial 절대 경로 (패키지 소유)
     * @param callable $dataProvider fn(int $memberId, int $domainId, int $page, int $perPage): array
     *                               반환: 뷰 데이터(예: ['items' => [...], 'pagination' => [...]])
     */
    public function registerSection(string $key, string $label, string $viewPath, callable $dataProvider): self
    {
        $this->sections[$this->currentProvider][$key] = [
            'provider'     => $this->currentProvider,
            'key'          => $key,
            'label'        => $label,
            'viewPath'     => $viewPath,
            'dataProvider' => $dataProvider,
        ];
        return $this;
    }

    public function getSection(string $provider, string $key): ?array
    {
        return $this->sections[strtolower($provider)][$key] ?? null;
    }

    /** @return array<string, array<string, array>> */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * 마이페이지 허브 등록 (패키지당 1개) → /mypage/{provider}.
     *
     * 허브 = 요약본 + 본체(`/{provider}/...`)로 가는 통로. 상세는 프레임 안에서 안 그린다.
     *
     * @param string          $label        사이드바/제목용 라벨 (예: '마이쇼핑')
     * @param string|callable $viewPath     요약 뷰 절대 경로(패키지 소유). 스킨 해석이 필요하면
     *                                       fn(int $domainId): string 콜백을 넘기면 렌더 시점에 해석된다.
     * @param callable        $dataProvider fn(int $memberId, int $domainId): array — 요약 뷰 데이터
     */
    public function registerHub(string $label, string|callable $viewPath, callable $dataProvider): self
    {
        $this->hubs[$this->currentProvider] = [
            'provider'     => $this->currentProvider,
            'label'        => $label,
            'viewPath'     => $viewPath,
            'dataProvider' => $dataProvider,
        ];
        return $this;
    }

    public function getHub(string $provider): ?array
    {
        return $this->hubs[strtolower($provider)] ?? null;
    }

    /** @return array<string, array> */
    public function getHubs(): array
    {
        return $this->hubs;
    }
}
