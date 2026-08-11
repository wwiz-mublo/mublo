<?php

namespace Mublo\Tools;

/**
 * 확장이 의존해도 되는 코어 심볼 표면 — docs/compatibility-policy.md 의 "안정 API" 를 코드로 옮긴 것이다.
 *
 * 두 검사기(check-extension-api·check-event-payload)가 같은 목록을 봐야 한다. 소비자 쪽에서 막는 것과
 * 생산자 쪽에서 막는 것이 서로 다른 표면을 기준으로 하면, 한쪽이 허용한 타입을 다른 쪽이 금지하는
 * 모순이 생긴다. 문서와 이 클래스가 어긋나면 문서가 아니라 이 코드가 진실이다 — CI 가 이것을 강제한다.
 */
final class ExtensionApiSurface
{
    /**
     * 안정 심볼 (네임스페이스 프리픽스)
     *
     * @return list<string>
     */
    public static function stablePrefixes(): array
    {
        return [
            // 확장 등록·부팅
            'Mublo\Core\Extension\\',
            'Mublo\Core\Container\\',
            'Mublo\Core\Context\\',

            // 이벤트 — 정책상 안정. 네임스페이스가 어디든 \Event\ 아래면 안정으로 본다(아래 isStable 참고).
            'Mublo\Core\Event\\',

            // Contract (확장 간 결합의 정식 통로)
            'Mublo\Contract\\',
            'Mublo\Core\Registry\\',

            // 요청/응답
            'Mublo\Core\Http\\',
            'Mublo\Core\Response\\',
            'Mublo\Core\Result\\',
            'Mublo\Core\Session\SessionInterface',
            'Mublo\Core\App\PrefixedRouteCollector',
            'Mublo\Core\Middleware\\',

            // 블록 시스템
            //
            // 렌더러는 엔티티가 아니라 Contract\Block\BlockColumnView 를 받는다. 칸 엔티티의
            // 저장·정렬·스택 구성 표면은 확장 계약이 아니며, 여기 없는 것이 정상이다.
            //
            // BlockRow·BlockColumn 두 엔티티만 남긴 이유는 미리보기 계약
            // (Contract\Block\BlockPreviewRendererInterface::renderRow) 때문이다. 저장되지 않은
            // 구성을 렌더하는 것이 목적이라 확장이 엔티티를 직접 조립해 건네야 한다.
            // 나머지 블록 엔티티(BlockKit·BlockPage 등)는 코어 내부다.
            'Mublo\Core\Block\\',
            'Mublo\Enum\Block\\',
            'Mublo\Entity\Block\BlockRow',
            'Mublo\Entity\Block\BlockColumn',

            // 확장이 상속하는 기반 클래스
            'Mublo\Entity\BaseEntity',
            'Mublo\Repository\BaseRepository',

            // 관리자·렌더 확장 지점
            'Mublo\Core\Dashboard\\',
            'Mublo\Core\Rendering\\',
            'Mublo\Core\Theme\\',
            'Mublo\Core\Report\\',
            'Mublo\Core\Crypto\\',

            // 마이페이지 확장 지점 — Package/Plugin 컨트롤러가 마이페이지 레이아웃을
            // 사용할 수 있도록 분리된 서비스 (클래스 독블록 참조)
            'Mublo\Service\Mypage\MypageMenuBuilder',

            // 여러 확장의 폼 스킨이 공유하는 정적 HTML 렌더 facade
            'Mublo\Service\CustomField\CustomFieldRenderer',

            // 인프라 — 주입해서 쓰는 서비스와 값 객체
            'Mublo\Infrastructure\Database\Database',
            'Mublo\Infrastructure\Database\DatabaseException',
            'Mublo\Infrastructure\Database\SqlStatementSplitter',
            'Mublo\Infrastructure\Crypto\CryptoManager',
            'Mublo\Infrastructure\Cache\CacheInterface',
            'Mublo\Infrastructure\Storage\\',
            'Mublo\Infrastructure\Image\ImageProcessor',
            'Mublo\Infrastructure\Mail\\',
            'Mublo\Infrastructure\Log\Logger',
            'Mublo\Infrastructure\Log\LogLevel',
            'Mublo\Infrastructure\Security\RateLimiter',
            'Mublo\Infrastructure\Code\CodeGenerator',
            'Mublo\Core\Cookie\CookieInterface',

            // 예외
            'Mublo\Exception\\',

            // 보조 유틸 (값 객체·헬퍼)
            'Mublo\Helper\\',
        ];
    }

    public static function isStable(string $symbol): bool
    {
        // 이벤트는 정책상 안정 API 다. 다만 코어가 이벤트를 Core\Event\ 밖에도 두었다
        // (예: Service\Admin\Event\AdminMenuBuildingEvent). 위치가 아니라 성격으로 판단한다.
        if (self::isEventSymbol($symbol)) {
            return true;
        }

        foreach (self::stablePrefixes() as $prefix) {
            // 역슬래시로 끝나면 네임스페이스 전체, 아니면 정확한 클래스 하나.
            // 접두사 매칭을 느슨하게 하면 Database 가 DatabaseManager 까지 통과시킨다.
            $matched = str_ends_with($prefix, '\\')
                ? str_starts_with($symbol, $prefix)
                : $symbol === $prefix;

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /** isStable 이 무조건 통과시키는 "이벤트" 판정 — 생산자 쪽 검사 대상과 같은 기준이다. */
    public static function isEventSymbol(string $symbol): bool
    {
        return str_contains($symbol, '\Event\\') || str_ends_with($symbol, 'Event');
    }
}
