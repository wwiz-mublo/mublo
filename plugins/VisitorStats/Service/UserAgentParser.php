<?php

namespace Mublo\Plugin\VisitorStats\Service;

/**
 * UserAgentParser
 *
 * 순수 정규식 기반 UA 파싱 (외부 의존 없음)
 * browser, os, device 정보를 추출
 */
class UserAgentParser
{
    /**
     * UA 문자열 파싱
     *
     * @return array{browser: string, os: string, device: string}
     */
    public static function parse(string $ua): array
    {
        return [
            'browser' => self::detectBrowser($ua),
            'os'      => self::detectOs($ua),
            'device'  => self::detectDevice($ua),
        ];
    }

    /**
     * 봇 UA 여부 판별
     */
    public static function isBot(string $ua): bool
    {
        if ($ua === '') {
            return true;
        }

        $botPatterns = [
            'bot', 'crawl', 'spider', 'slurp', 'mediapartners',
            'feedfetcher', 'wget', 'curl', 'python', 'java/',
            'apache-httpclient', 'go-http-client', 'headlesschrome',
            'lighthouse', 'pingdom', 'uptimerobot', 'monitoring',
            'facebookexternalhit', 'twitterbot', 'linkedinbot',
            'whatsapp', 'telegrambot', 'discordbot', 'kakaotalk-scrap',
            'yandex', 'baiduspider', 'sogou', 'duckduckbot',
            'ahrefsbot', 'semrushbot', 'mj12bot', 'dotbot',
            'petalbot', 'bytespider', 'gptbot', 'claudebot',
        ];

        $uaLower = strtolower($ua);
        foreach ($botPatterns as $pattern) {
            if (str_contains($uaLower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private static function detectBrowser(string $ua): string
    {
        // 순서 중요: 구체적인 것부터 매칭
        if (preg_match('/Edg(e|A|iOS)?\/[\d.]+/i', $ua)) return 'edge';
        if (preg_match('/SamsungBrowser\/[\d.]+/i', $ua)) return 'samsung';
        if (preg_match('/OPR\/[\d.]+|Opera\/[\d.]+/i', $ua)) return 'opera';
        if (preg_match('/Firefox\/[\d.]+/i', $ua) && !str_contains($ua, 'Seamonkey')) return 'firefox';
        if (preg_match('/MSIE|Trident/i', $ua)) return 'ie';
        if (preg_match('/Chrome\/[\d.]+/i', $ua) && !str_contains($ua, 'Chromium')) return 'chrome';
        if (preg_match('/Safari\/[\d.]+/i', $ua) && str_contains($ua, 'Version/')) return 'safari';

        return 'other';
    }

    private static function detectOs(string $ua): string
    {
        // iOS를 Android보다 먼저 (iPad UA에 둘 다 있을 수 있음)
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) return 'ios';
        if (preg_match('/Android/i', $ua)) return 'android';
        if (preg_match('/Windows NT/i', $ua)) return 'windows';
        if (preg_match('/Macintosh|Mac OS X/i', $ua)) return 'mac';
        if (preg_match('/Linux/i', $ua)) return 'linux';

        return 'other';
    }

    private static function detectDevice(string $ua): string
    {
        // 태블릿 먼저 (iPad, Android tablet 등)
        if (preg_match('/iPad|tablet/i', $ua)) return 'tablet';
        if (preg_match('/Android/i', $ua) && !preg_match('/Mobile/i', $ua)) return 'tablet';

        // 모바일
        if (preg_match('/Mobile|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry/i', $ua)) return 'mobile';

        return 'pc';
    }
}
