<?php

namespace Mublo\Contract\Tracking;

/**
 * 전환 추적 세션 키 상수
 *
 * 패키지(Shop/Mshop/Rental)와 방문통계 플러그인 간
 * 세션 키 이름을 통일하기 위한 중립 계약.
 */
class TrackingKeys
{
    /** 캠페인 키 (세션 저장용) */
    const CAMPAIGN_KEY = 'visitor_campaign_key';
}
