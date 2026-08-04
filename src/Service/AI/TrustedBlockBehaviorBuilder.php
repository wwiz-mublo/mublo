<?php
declare(strict_types=1);
namespace Mublo\Service\AI;

use DOMDocument;
use DOMElement;
use DOMXPath;

class TrustedBlockBehaviorBuilder
{
    /** @return array{css:string,js:string,warnings:string[]} */
    public function build(array $behavior, string $scope, string $html): array
    {
        $types = $this->behaviorTypes($behavior);
        if (!$types) return ['css' => '', 'js' => '', 'warnings' => []];

        $css = [];
        $scripts = [];
        $warnings = [];
        foreach ($types as $type) {
            try {
                $assets = match ($type) {
                    'slider' => $this->sliderAssets($behavior, $scope, $html),
                    'tabs' => $this->tabsAssets($scope, $html),
                    'accordion' => $this->accordionAssets($scope, $html),
                };
                if ($assets['css'] !== '') $css[] = $assets['css'];
                if ($assets['js'] !== '') $scripts[] = $assets['js'];
            } catch (\RuntimeException $e) {
                $warnings[] = $e->getMessage() . ' 해당 동작을 제외하고 HTML/CSS 결과만 적용했습니다.';
            }
        }

        $js = '';
        if ($scripts) {
            $js = "/* mublo-block-behavior:start */\n"
                . implode("\n\n", $scripts)
                . "\n/* mublo-block-behavior:end */";
        }

        return [
            'css' => implode("\n", array_values(array_unique($css))),
            'js' => $js,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function mergeJs(string $currentJs, string $generatedJs): string
    {
        $manual = trim(preg_replace(
            '#/\* mublo-(?:ai|block)-behavior:start \*/.*?/\* mublo-(?:ai|block)-behavior:end \*/#s',
            '',
            $currentJs
        ) ?? $currentJs);
        return trim($manual . ($manual !== '' && $generatedJs !== '' ? "\n\n" : '') . $generatedJs);
    }

    /** @return string[] */
    private function behaviorTypes(array $behavior): array
    {
        $types = is_array($behavior['types'] ?? null) ? $behavior['types'] : [];
        if (!$types && is_string($behavior['type'] ?? null) && $behavior['type'] !== 'none') {
            $types = [$behavior['type']];
        }
        return array_values(array_unique(array_filter(
            $types,
            static fn (mixed $type): bool => is_string($type)
                && in_array($type, ['slider', 'tabs', 'accordion'], true)
        )));
    }

    /** @return array{css:string,js:string} */
    private function sliderAssets(array $behavior, string $scope, string $html): array
    {
        [$xpath, $root] = $this->document($html);
        $sliders = $this->descendantsWithClass($xpath, $root, HtmlBlockAiPolicy::SLIDER_ROOT_CLASS);
        if (count($sliders) !== 1) {
            throw new \RuntimeException('슬라이드는 하나의 Mublo 슬라이드 컨테이너가 필요합니다.');
        }
        $tracks = $this->directChildrenWithClass($sliders[0], HtmlBlockAiPolicy::SLIDER_TRACK_CLASS);
        $slides = count($tracks) === 1
            ? $this->directChildrenWithClass($tracks[0], HtmlBlockAiPolicy::SLIDER_ITEM_CLASS)
            : [];
        if (count($tracks) !== 1 || count($slides) < 2) {
            throw new \RuntimeException('슬라이드 생성 결과가 Mublo 슬라이드 구조를 충족하지 않습니다.');
        }

        $rootName = HtmlBlockAiPolicy::SLIDER_ROOT_CLASS;
        $trackName = HtmlBlockAiPolicy::SLIDER_TRACK_CLASS;
        $itemName = HtmlBlockAiPolicy::SLIDER_ITEM_CLASS;
        // fallback CSS — 전역 Swiper(MubloSlider)가 없는 스킨에서만 적용된다.
        // MubloSlider.init이 성공하면 is-enhanced가 붙어 이 규칙은 비활성화된다.
        $css = <<<CSS
.{$rootName}:not(.is-enhanced) .{$trackName} { display:grid; grid-auto-flow:column; grid-auto-columns:100%; overflow-x:auto; scroll-snap-type:x mandatory; scroll-behavior:smooth; }
.{$rootName}:not(.is-enhanced) .{$itemName} { scroll-snap-align:start; min-width:0; }
CSS;

        $preset = $behavior['slider_preset'] ?? '';
        if (!is_string($preset) || !in_array($preset, HtmlBlockAiPolicy::SLIDER_PRESETS, true)) {
            $preset = 'hero';
        }
        $seconds = max(0, min(30, (int) ($behavior['autoplay_seconds'] ?? 0)));

        $selector = $this->scopeSelector($scope);
        $presetJson = json_encode($preset, JSON_THROW_ON_ERROR);
        $js = <<<JS
(() => {
    const scope = document.querySelector({$selector});
    const slider = scope ? scope.querySelector('.{$rootName}') : null;
    if (!slider) return;
    const boot = () => {
        if (window.MubloSlider) window.MubloSlider.init(slider, { preset: {$presetJson}, autoplaySeconds: {$seconds} });
    };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
})();
JS;
        return ['css' => $css, 'js' => $js];
    }

    /** @return array{css:string,js:string} */
    private function tabsAssets(string $scope, string $html): array
    {
        [$xpath, $root] = $this->document($html);
        $tabRoots = $this->descendantsWithClass($xpath, $root, HtmlBlockAiPolicy::TABS_ROOT_CLASS);
        if (!$tabRoots) throw new \RuntimeException('탭 동작에 필요한 Mublo 탭 컨테이너가 없습니다.');

        foreach ($tabRoots as $tabRoot) {
            $lists = $this->directChildrenWithClass($tabRoot, HtmlBlockAiPolicy::TABS_LIST_CLASS);
            $panelGroups = $this->directChildrenWithClass($tabRoot, HtmlBlockAiPolicy::TABS_PANELS_CLASS);
            $triggers = count($lists) === 1
                ? $this->directChildrenWithClass($lists[0], HtmlBlockAiPolicy::TABS_TRIGGER_CLASS, 'span')
                : [];
            $panels = count($panelGroups) === 1
                ? $this->directChildrenWithClass($panelGroups[0], HtmlBlockAiPolicy::TABS_PANEL_CLASS)
                : [];
            if (count($lists) !== 1 || count($panelGroups) !== 1
                || count($triggers) < 2 || count($triggers) !== count($panels)) {
                throw new \RuntimeException('탭 생성 결과가 Mublo 탭 구조를 충족하지 않습니다.');
            }
        }

        $rootName = HtmlBlockAiPolicy::TABS_ROOT_CLASS;
        $listName = HtmlBlockAiPolicy::TABS_LIST_CLASS;
        $triggerName = HtmlBlockAiPolicy::TABS_TRIGGER_CLASS;
        $panelsName = HtmlBlockAiPolicy::TABS_PANELS_CLASS;
        $panelName = HtmlBlockAiPolicy::TABS_PANEL_CLASS;
        $css = <<<CSS
.{$rootName}.is-enhanced .{$panelName} { display:none; }
.{$rootName}.is-enhanced .{$panelName}.is-active { display:block; }
CSS;
        $selector = $this->scopeSelector($scope);
        $js = <<<JS
(() => {
    const scope = document.querySelector({$selector});
    if (!scope) return;
    scope.querySelectorAll('.{$rootName}').forEach((tabs) => {
        const list = tabs.querySelector(':scope > .{$listName}');
        const triggers = list ? Array.from(list.querySelectorAll(':scope > .{$triggerName}')) : [];
        const panels = Array.from(tabs.querySelectorAll(':scope > .{$panelsName} > .{$panelName}'));
        if (triggers.length < 2 || triggers.length !== panels.length) return;
        tabs.classList.add('is-enhanced');
        list.setAttribute('role', 'tablist');
        const activate = (next, focus = false) => {
            triggers.forEach((trigger, index) => {
                const active = index === next;
                trigger.classList.toggle('is-active', active);
                trigger.setAttribute('aria-selected', active ? 'true' : 'false');
                trigger.tabIndex = active ? 0 : -1;
                panels[index].classList.toggle('is-active', active);
                panels[index].hidden = !active;
            });
            if (focus) triggers[next].focus();
        };
        triggers.forEach((trigger, index) => {
            trigger.setAttribute('role', 'tab');
            panels[index].setAttribute('role', 'tabpanel');
            trigger.addEventListener('click', () => activate(index));
            trigger.addEventListener('keydown', (event) => {
                let next = null;
                if (event.key === 'ArrowRight') next = (index + 1) % triggers.length;
                if (event.key === 'ArrowLeft') next = (index - 1 + triggers.length) % triggers.length;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = triggers.length - 1;
                if (event.key === 'Enter' || event.key === ' ') next = index;
                if (next === null) return;
                event.preventDefault();
                activate(next, true);
            });
        });
        activate(0);
    });
})();
JS;
        return ['css' => $css, 'js' => $js];
    }

    /** @return array{css:string,js:string} */
    private function accordionAssets(string $scope, string $html): array
    {
        [$xpath, $root] = $this->document($html);
        $accordionRoots = $this->descendantsWithClass($xpath, $root, HtmlBlockAiPolicy::ACCORDION_ROOT_CLASS);
        if (!$accordionRoots) throw new \RuntimeException('아코디언 동작에 필요한 Mublo 아코디언 컨테이너가 없습니다.');

        foreach ($accordionRoots as $accordionRoot) {
            $items = $this->directChildrenWithClass($accordionRoot, HtmlBlockAiPolicy::ACCORDION_ITEM_CLASS);
            if (!$items) throw new \RuntimeException('아코디언에는 하나 이상의 항목이 필요합니다.');
            foreach ($items as $item) {
                $triggers = $this->directChildrenWithClass($item, HtmlBlockAiPolicy::ACCORDION_TRIGGER_CLASS, 'span');
                $panels = $this->directChildrenWithClass($item, HtmlBlockAiPolicy::ACCORDION_PANEL_CLASS);
                if (count($triggers) !== 1 || count($panels) !== 1) {
                    throw new \RuntimeException('아코디언 생성 결과가 Mublo 아코디언 구조를 충족하지 않습니다.');
                }
            }
        }

        $rootName = HtmlBlockAiPolicy::ACCORDION_ROOT_CLASS;
        $itemName = HtmlBlockAiPolicy::ACCORDION_ITEM_CLASS;
        $triggerName = HtmlBlockAiPolicy::ACCORDION_TRIGGER_CLASS;
        $panelName = HtmlBlockAiPolicy::ACCORDION_PANEL_CLASS;
        $css = <<<CSS
.{$rootName}.is-enhanced .{$panelName} { display:none; }
.{$rootName}.is-enhanced .{$panelName}.is-open { display:block; }
CSS;
        $selector = $this->scopeSelector($scope);
        $js = <<<JS
(() => {
    const scope = document.querySelector({$selector});
    if (!scope) return;
    scope.querySelectorAll('.{$rootName}').forEach((accordion) => {
        accordion.classList.add('is-enhanced');
        accordion.querySelectorAll(':scope > .{$itemName}').forEach((item) => {
            const trigger = item.querySelector(':scope > .{$triggerName}');
            const panel = item.querySelector(':scope > .{$panelName}');
            if (!trigger || !panel) return;
            trigger.setAttribute('role', 'button');
            trigger.tabIndex = 0;
            trigger.setAttribute('aria-expanded', 'false');
            panel.hidden = true;
            const toggle = () => {
                const open = trigger.getAttribute('aria-expanded') !== 'true';
                trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
                trigger.classList.toggle('is-open', open);
                panel.classList.toggle('is-open', open);
                panel.hidden = !open;
            };
            trigger.addEventListener('click', toggle);
            trigger.addEventListener('keydown', (event) => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                toggle();
            });
        });
    });
})();
JS;
        return ['css' => $css, 'js' => $js];
    }

    /** @return array{DOMXPath,DOMElement} */
    private function document(string $html): array
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div data-mublo-behavior-root="1">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $root = $xpath->query('//*[@data-mublo-behavior-root="1"]')?->item(0);
        if (!$root instanceof DOMElement) throw new \RuntimeException('동작을 적용할 HTML 구조를 확인할 수 없습니다.');
        return [$xpath, $root];
    }

    /** @return DOMElement[] */
    private function descendantsWithClass(DOMXPath $xpath, DOMElement $root, string $class): array
    {
        $class = str_replace("'", '', $class);
        $nodes = $xpath->query(
            './/*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]',
            $root
        );
        if (!$nodes) return [];
        return array_values(array_filter(iterator_to_array($nodes), static fn ($node): bool => $node instanceof DOMElement));
    }

    /** @return DOMElement[] */
    private function directChildrenWithClass(DOMElement $parent, string $class, ?string $tag = null): array
    {
        $result = [];
        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            if ($tag !== null && strtolower($child->tagName) !== strtolower($tag)) continue;
            $classes = preg_split('/\s+/', trim($child->getAttribute('class'))) ?: [];
            if (in_array($class, $classes, true)) $result[] = $child;
        }
        return $result;
    }

    private function scopeSelector(string $scope): string
    {
        return json_encode('.' . $scope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
