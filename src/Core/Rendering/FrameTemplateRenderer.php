<?php
declare(strict_types=1);

namespace Mublo\Core\Rendering;

use Mublo\Core\Event\Rendering\FrameTemplateSourceCollectEvent;

/**
 * 프레임 템플릿 렌더러
 *
 * 도메인 프레임 편집(header/footer 오버라이드)의 `{{...}}` 템플릿 문자열을
 * 실제 값·HTML로 치환한다. DB에는 원문 템플릿이 저장되고, 치환은 매 렌더
 * 시점에 일어난다 (원문 저장 원칙 — 계획문서 §4).
 *
 * 2층 구조 (계획문서 §3.1):
 * - 치환 변수: 문자열 값. 렌더러가 htmlspecialchars로 이스케이프해 삽입한다.
 * - 컴포넌트 슬롯: 완성된 HTML 덩어리. 이스케이프 없이 삽입한다.
 *   확장 슬롯(이름에 `.` 포함)은 표준 래퍼로 감싼다:
 *   <div class="frame-slot frame-slot--{확장}-{이름}">…</div>
 *
 * 실패 격리 (계획문서 §3.9 규칙 5):
 * - 미정의 토큰: 빈 문자열로 소거 + 진단 기록 (비활성 확장의 잔존 토큰 포함)
 * - resolver 예외: 해당 토큰만 빈 값 처리 + 진단 기록. 전체 렌더는 계속된다.
 *
 * 지연 해석: resolver/renderer는 템플릿에 실제 등장한 토큰에 대해서만 호출된다.
 */
class FrameTemplateRenderer
{
    /**
     * 템플릿 토큰 패턴: {{name}} / {{ name }} — 소문자 시작, 소문자·숫자·언더스코어·점
     */
    private const TOKEN_PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)\s*\}\}/';

    /** @var array<string, callable|string> 변수: 이름 => 값 또는 fn(): string */
    private array $variables = [];

    /** @var array<string, callable> 슬롯: 이름 => fn(): string (완성 HTML) */
    private array $slots = [];

    /** @var array<array{token: string, type: string, message: string}> */
    private array $diagnostics = [];

    /**
     * 치환 변수 등록 (코어 무접두사·확장 네임스페이스 공용)
     *
     * @param callable|string $resolver 문자열 값 또는 fn(): string (지연 해석)
     */
    public function setVariable(string $name, callable|string $resolver): void
    {
        $this->variables[$name] = $resolver;
    }

    /**
     * 컴포넌트 슬롯 등록
     *
     * @param callable $renderer fn(): string — 완성된 HTML 반환
     */
    public function setSlot(string $name, callable $renderer): void
    {
        $this->slots[$name] = $renderer;
    }

    /**
     * 수집 이벤트에서 확장 변수·슬롯 병합
     *
     * 코어 등록과 충돌하는 이름은 무시된다 (코어 우선 — 이벤트 쪽에서
     * 네임스페이스 검증으로 이미 걸러지지만 이중 방어).
     */
    public function applyCollected(FrameTemplateSourceCollectEvent $event): void
    {
        foreach ($event->getVariables() as $name => $def) {
            if (!isset($this->variables[$name]) && !isset($this->slots[$name])) {
                $this->variables[$name] = $def['resolver'];
            }
        }

        foreach ($event->getSlots() as $name => $def) {
            if (!isset($this->variables[$name]) && !isset($this->slots[$name])) {
                $this->slots[$name] = $def['renderer'];
            }
        }
    }

    /**
     * 등록된 전체 이름 목록 (에디터 변수 팔레트·검증용)
     *
     * @return array{variables: string[], slots: string[]}
     */
    public function getRegisteredNames(): array
    {
        return [
            'variables' => array_keys($this->variables),
            'slots' => array_keys($this->slots),
        ];
    }

    /**
     * 템플릿 렌더 — 주석 밖 구간만 1패스 치환
     *
     * HTML 주석(<!-- ... -->) 안의 `{{...}}`는 치환하지 않는다:
     * - 시드·안내 주석의 토큰 예시(`<img src="{{logo_url}}">로 교체` 등)는
     *   복사용 견본이지 실행 대상이 아니다
     * - 주석 안에 슬롯 HTML이 주입되면 내용에 따라 주석이 조기 종료되어
     *   페이지가 깨질 수 있다
     * 닫히지 않은 `<!--`는 주석으로 취급하지 않는다(일반 구간으로 치환).
     */
    public function render(string $template): string
    {
        $this->diagnostics = [];

        $parts = preg_split('/(<!--.*?-->)/s', $template, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            $parts = [$template];
        }

        $out = '';
        foreach ($parts as $i => $chunk) {
            // DELIM_CAPTURE: 홀수 인덱스가 주석 원문
            $out .= ($i % 2 === 1) ? $chunk : $this->substitute($chunk);
        }

        return $out;
    }

    private function substitute(string $segment): string
    {
        return (string) preg_replace_callback(
            self::TOKEN_PATTERN,
            fn(array $m): string => $this->resolveToken($m[1]),
            $segment
        );
    }

    /**
     * 직전 render()의 진단 목록 (미정의 토큰·resolver 실패)
     *
     * @return array<array{token: string, type: string, message: string}>
     */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    private function resolveToken(string $name): string
    {
        if (isset($this->variables[$name])) {
            $resolver = $this->variables[$name];

            try {
                $value = is_callable($resolver) ? (string) $resolver() : $resolver;
            } catch (\Throwable $e) {
                $this->diagnostics[] = [
                    'token' => $name,
                    'type' => 'resolver_error',
                    'message' => $e->getMessage(),
                ];
                return '';
            }

            return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        }

        if (isset($this->slots[$name])) {
            try {
                $html = (string) ($this->slots[$name])();
            } catch (\Throwable $e) {
                $this->diagnostics[] = [
                    'token' => $name,
                    'type' => 'resolver_error',
                    'message' => $e->getMessage(),
                ];
                return '';
            }

            return $this->wrapSlot($name, $html);
        }

        $this->diagnostics[] = [
            'token' => $name,
            'type' => 'undefined',
            'message' => '정의되지 않은 템플릿 문자열입니다 (비활성 확장 또는 오타). 빈 문자열로 소거됩니다.',
        ];

        return '';
    }

    /**
     * 확장 슬롯 표준 래퍼 (계획문서 §3.9)
     *
     * 코어 슬롯(무접두사)은 감싸지 않는다 — 출력 마크업 자체가 안정 계약이다.
     * 확장 슬롯은 `frame-slot frame-slot--{확장}-{이름}` 래퍼로 감싸
     * 운영자·AI CSS 타깃을 표준화한다.
     */
    private function wrapSlot(string $name, string $html): string
    {
        if (!str_contains($name, '.')) {
            return $html;
        }

        $class = 'frame-slot--' . str_replace('.', '-', $name);

        return '<div class="frame-slot ' . $class . '">' . $html . '</div>';
    }
}
