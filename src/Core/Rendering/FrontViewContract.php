<?php
namespace Mublo\Core\Rendering;

/**
 * 모든 Front 스킨에 전달되는 예약 데이터인 $mublo의 단일 계약 정의입니다.
 */
final class FrontViewContract
{
    public const VERSION = 1;
    public const RESERVED_KEY = 'mublo';

    /** @var list<string> */
    public const SECTIONS = [
        'contractVersion',
        'site',
        'viewer',
        'request',
        'navigation',
        'theme',
        'security',
        'runtime',
    ];

    /** @return array<string, mixed> */
    public static function empty(bool $viewerAware = false): array
    {
        return [
            'contractVersion' => self::VERSION,
            'site' => [
                'domainId' => 0,
                'url' => '',
                'config' => [],
                'company' => [],
                'seo' => [],
                'images' => [],
                'customerService' => [],
            ],
            'viewer' => self::anonymousViewer(),
            'request' => self::emptyRequest(),
            'navigation' => [
                'menuTree' => [],
                'utilityMenus' => [],
                'footerMenus' => [],
                'currentMenuCode' => null,
            ],
            'theme' => ['frameSkin' => 'basic'],
            'security' => ['available' => false, 'csrfToken' => ''],
            'runtime' => [
                'area' => 'front',
                'viewerAware' => $viewerAware,
                'preview' => true,
            ],
        ];
    }

    /** @param array<string, mixed> $mublo */
    public static function assertValid(array $mublo): void
    {
        if (($mublo['contractVersion'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported $mublo view contract version.');
        }

        foreach (self::SECTIONS as $section) {
            if (!array_key_exists($section, $mublo)) {
                throw new \InvalidArgumentException("Missing \$mublo view-contract section: {$section}");
            }
        }

        foreach (['site', 'viewer', 'request', 'navigation', 'theme', 'security', 'runtime'] as $section) {
            if (!is_array($mublo[$section])) {
                throw new \InvalidArgumentException("Invalid \$mublo view-contract section: {$section}");
            }
        }

        $requiredKeys = [
            'site' => ['domainId', 'url', 'config', 'company', 'seo', 'images', 'customerService'],
            'viewer' => ['available', 'authenticated', 'member', 'notificationUnreadCount'],
            'request' => ['available', 'url', 'path', 'query'],
            'navigation' => ['menuTree', 'utilityMenus', 'footerMenus', 'currentMenuCode'],
            'theme' => ['frameSkin'],
            'security' => ['available', 'csrfToken'],
            'runtime' => ['area', 'viewerAware', 'preview'],
        ];

        foreach ($requiredKeys as $section => $keys) {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $mublo[$section])) {
                    throw new \InvalidArgumentException("Missing \$mublo view-contract key: {$section}.{$key}");
                }
            }
        }
    }

    /** @param array<string, mixed> $mublo @return array<string, mixed> */
    public static function withoutViewerState(array $mublo): array
    {
        self::assertValid($mublo);
        $mublo['viewer'] = self::anonymousViewer();
        $mublo['request'] = self::emptyRequest();
        $mublo['security'] = ['available' => false, 'csrfToken' => ''];
        $mublo['runtime']['viewerAware'] = false;

        return $mublo;
    }

    /** @return array{available: false, authenticated: false, member: null, notificationUnreadCount: 0} */
    private static function anonymousViewer(): array
    {
        return [
            'available' => false,
            'authenticated' => false,
            'member' => null,
            'notificationUnreadCount' => 0,
        ];
    }

    /** @return array{available: false, url: '', path: '', query: array{}} */
    private static function emptyRequest(): array
    {
        return [
            'available' => false,
            'url' => '',
            'path' => '',
            'query' => [],
        ];
    }
}
