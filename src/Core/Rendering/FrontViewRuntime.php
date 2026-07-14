<?php
namespace Mublo\Core\Rendering;

/**
 * 현재 Front 요청의 스킨 실행 환경.
 *
 * 일반 콘텐츠, 프레임, 블록, Plugin/Package 삽입 뷰가 동일한 ViewContext와
 * $mublo 데이터 계약을 사용하도록 요청 동안 한 번 초기화한다.
 */
final class FrontViewRuntime
{
    private ?ViewContext $viewContext = null;

    /** @var array<string, mixed> */
    private array $mublo = [];

    /**
     * @param array<string, mixed> $mublo
     */
    public function initialize(ViewContext $viewContext, array $mublo): void
    {
        FrontViewContract::assertValid($mublo);
        $this->viewContext = $viewContext;
        $this->mublo = $mublo;
    }

    public function isInitialized(): bool
    {
        return $this->viewContext !== null;
    }

    /**
     * Front 파이프라인 밖의 관리자 미리보기에서도 동일한 키 구조를 보장한다.
     *
     * @return array<string, mixed>
     */
    public static function emptyMublo(bool $viewerAware = false): array
    {
        return FrontViewContract::empty($viewerAware);
    }

    public function getViewContext(): ViewContext
    {
        if ($this->viewContext === null) {
            throw new \LogicException('FrontViewRuntime has not been initialized.');
        }

        return $this->viewContext;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMublo(bool $viewerAware = true): array
    {
        if ($viewerAware) {
            return $this->mublo;
        }

        return FrontViewContract::withoutViewerState($this->mublo);
    }

    /**
     * 동일 ViewContext에서 스킨 조각을 문자열로 렌더링한다.
     *
     * @param array<string, mixed> $data
     */
    public function render(string $path, array $data = [], bool $viewerAware = true): string
    {
        if (array_key_exists(FrontViewContract::RESERVED_KEY, $data)) {
            throw new \InvalidArgumentException('"mublo" is a reserved view-data key.');
        }

        $context = $this->getViewContext();
        $bufferLevel = ob_get_level();
        ob_start();

        try {
            $context->render($path, ['mublo' => $this->getMublo($viewerAware)] + $data);
            return (string) ob_get_clean();
        } catch (\Throwable $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }
    }
}
