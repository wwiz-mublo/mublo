<?php
declare(strict_types=1);

namespace Mublo\Contract\Tracking;

/**
 * 전환 추적 세션 키 상수
 *
 * 유입을 기록하는 쪽과 전환을 발행하는 쪽이 서로를 모른 채 같은 세션 키를 쓰게
 * 하기 위한 중립 계약. 특정 확장을 열거하지 않는다 — 이 키를 읽고 쓰는 것만으로
 * 캠페인 추적에 참여할 수 있다.
 */
class TrackingKeys
{
    /** 캠페인 키 (세션 저장용) */
    const CAMPAIGN_KEY = 'visitor_campaign_key';
}
