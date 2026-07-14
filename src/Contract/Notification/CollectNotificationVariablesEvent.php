<?php

namespace Mublo\Contract\Notification;

use Mublo\Core\Event\AbstractEvent;

/**
 * 외부 채널 알림 템플릿 치환 변수 수집 이벤트
 *
 * 알림 플러그인(EmailNotify, SendonSms, SendonTalk 등)이 관리자 채널 설정 페이지 로드 시 dispatch하며,
 * 각 패키지/플러그인이 자신의 치환 변수를 addVariables()로 등록한다.
 *
 * src/Contract/Notification/에 위치하여 어떤 알림 플러그인이든 재사용 가능한 중립 계약.
 */
class CollectNotificationVariablesEvent extends AbstractEvent
{
    /** @var array<string, array{label: string, variables: array<string, string>}> */
    private array $sources = [];

    /**
     * 사용 가능한 변수 등록
     *
     * @param string $sourceKey   소스 식별자 (예: 'shop', 'mshop')
     * @param string $sourceLabel 표시 라벨 (예: '쇼핑몰', '기기판매')
     * @param array<string, string> $variables field => 한글 라벨 매핑
     */
    public function addVariables(string $sourceKey, string $sourceLabel, array $variables): void
    {
        $this->sources[$sourceKey] = [
            'label' => $sourceLabel,
            'variables' => $variables,
        ];
    }

    /**
     * 소스별 그룹화된 변수 반환
     *
     * @return array<string, array{label: string, variables: array<string, string>}>
     */
    public function getSources(): array
    {
        return $this->sources;
    }

    /**
     * 전체 변수 flat 목록 (소스 구분 없이 field => label)
     *
     * @return array<string, string>
     */
    public function getAllVariables(): array
    {
        $all = [];
        foreach ($this->sources as $source) {
            foreach ($source['variables'] as $field => $label) {
                $all[$field] = $label;
            }
        }
        return $all;
    }
}
