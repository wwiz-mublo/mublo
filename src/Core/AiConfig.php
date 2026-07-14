<?php

namespace Mublo\Core;

final class AiConfig
{
    public const CURRENT_VERSION = 1;

    public static function load(): array
    {
        $path = MUBLO_CONFIG_PATH . '/ai.php';
        if (!is_file($path)) {
            throw new \RuntimeException('AI 설정 파일이 없습니다. 설치를 다시 완료하거나 config/ai.php를 생성해주세요.');
        }

        $config = require $path;
        if (!is_array($config)) {
            throw new \RuntimeException('AI 설정 파일 형식이 올바르지 않습니다.');
        }

        $version = (int) ($config['config_version'] ?? 0);
        if ($version < self::CURRENT_VERSION) {
            throw new \RuntimeException(sprintf(
                'AI 설정 파일 업데이트가 필요합니다. 현재 버전: %d, 필요한 버전: %d',
                $version,
                self::CURRENT_VERSION
            ));
        }

        return $config;
    }

    /**
     * AI 생성 출력 토큰 상한 (프로바이더 공통)
     *
     * config/ai.php의 max_output_tokens 키 — 설치본 설정 파일에 키가 없어도
     * 기본값으로 동작한다 (하위호환, config_version 불변).
     */
    public static function maxOutputTokens(): int
    {
        return (int) (self::load()['max_output_tokens'] ?? 16000);
    }

    public static function assets(): array
    {
        $config = self::load();
        if (!isset($config['assets']) || !is_array($config['assets'])) {
            throw new \RuntimeException('AI 자산 설정이 올바르지 않습니다.');
        }

        return $config['assets'];
    }
}
