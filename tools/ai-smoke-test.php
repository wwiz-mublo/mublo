<?php
/**
 * AI 공급자 실키 스모크 테스트
 *
 * 유닛 테스트는 HTTP를 목킹하므로, 공급자 API의 실제 계약(구조화 출력 스키마
 * 수용 여부, 응답 형식)은 이 스크립트로 실키 1회 호출해 확인합니다.
 *
 * 사용법:
 *   php tools/ai-smoke-test.php <provider> <api_key> [model]
 *   php tools/ai-smoke-test.php anthropic sk-ant-xxxx
 *   php tools/ai-smoke-test.php openai sk-xxxx gpt-5.4-mini
 *
 *   provider: openai | anthropic | gemini
 *   model 생략 시 config/ai.php의 default_model 사용
 *
 * 주의: 실제 과금이 발생하는 호출입니다 (소형 프롬프트 1회).
 *       API 키는 어디에도 저장·기록되지 않습니다.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI 전용 스크립트입니다.\n");
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

if (!defined('MUBLO_CONFIG_PATH')) {
    define('MUBLO_CONFIG_PATH', $basePath . '/config');
}

use Mublo\Infrastructure\AI\AiHttpClient;
use Mublo\Service\AI\Provider\AiProviderFactory;

$provider = $argv[1] ?? '';
$apiKey = $argv[2] ?? '';
$config = require MUBLO_CONFIG_PATH . '/ai.php';

if ($provider === '' || $apiKey === '') {
    fwrite(STDERR, "사용법: php tools/ai-smoke-test.php <provider> <api_key> [model]\n");
    fwrite(STDERR, '  provider: ' . implode(' | ', array_keys($config['providers'])) . "\n");
    exit(1);
}

if (!isset($config['providers'][$provider])) {
    fwrite(STDERR, "지원하지 않는 공급자: {$provider}\n");
    exit(1);
}

$model = $argv[3] ?? $config['providers'][$provider]['default_model'];
if (!in_array($model, $config['providers'][$provider]['models'], true)) {
    fwrite(STDERR, "config/ai.php 허용 목록에 없는 모델: {$model}\n");
    fwrite(STDERR, '  허용: ' . implode(', ', $config['providers'][$provider]['models']) . "\n");
    exit(1);
}

echo "── AI 스모크 테스트 ──\n";
echo "공급자: {$provider}\n";
echo "모델:   {$model}\n";
echo "호출 중...\n\n";

$started = microtime(true);

try {
    $result = (new AiProviderFactory(new AiHttpClient()))->make($provider)->generate(
        $apiKey,
        $model,
        'You are an HTML generator. Return only the requested structure.',
        '한 문장짜리 환영 인사를 담은 <p> 태그 하나를 생성하라. CSS는 .welcome 클래스에 색상 하나만.',
    );
} catch (\Throwable $e) {
    $elapsed = round(microtime(true) - $started, 2);
    fwrite(STDERR, "✗ 실패 ({$elapsed}s): " . $e->getMessage() . "\n");
    exit(1);
}

$elapsed = round(microtime(true) - $started, 2);

echo "✓ 성공 ({$elapsed}s)\n\n";
echo "html     : " . mb_substr(trim($result['html']), 0, 200) . "\n";
echo "css      : " . mb_substr(trim($result['css']), 0, 200) . "\n";
echo "notes    : " . mb_substr(trim($result['notes']), 0, 200) . "\n";
echo "behavior : " . json_encode($result['behavior'], JSON_UNESCAPED_UNICODE) . "\n\n";

$behavior = $result['behavior'];
$checks = [
    'html 비어있지 않음'      => trim($result['html']) !== '',
    'behavior.types 배열'     => is_array($behavior['types'] ?? null)
        && array_diff($behavior['types'], ['slider', 'tabs', 'accordion']) === [],
    'slider_preset 정규화됨'  => in_array($behavior['slider_preset'] ?? '', ['none', 'hero', 'cards', 'gallery'], true),
];

$allPass = true;
foreach ($checks as $label => $pass) {
    echo ($pass ? '✓' : '✗') . " {$label}\n";
    $allPass = $allPass && $pass;
}

exit($allPass ? 0 : 1);
