<?php

namespace Mublo\Helper\View;

use Mublo\Helper\String\StringHelper;

/**
 * ViewFormatHelper
 *
 * View에서 사용하는 포맷팅 헬퍼.
 * ViewContext의 setHelper('format', ...) 로 주입되어
 * 스킨에서 $this->format->method() 형태로 호출.
 *
 * 사용 예:
 * ```php
 * <?= $this->format->highlightKeyword($item['title_safe'], $keyword) ?>
 * <?= $this->format->number(12500) ?>       // '1.2만'
 * <?= $this->format->bytes(1048576) ?>       // '1 MB'
 * <?= $this->format->maskName('홍길동') ?>    // '홍**'
 * <?= $this->format->relativeTime('2026-02-05 10:00:00') ?>  // '3시간 전'
 * ```
 */
class ViewFormatHelper
{
    /**
     * 검색어 하이라이팅
     *
     * htmlspecialchars 적용 후의 텍스트에 사용할 것.
     *
     * @param string $safeText 이스케이프된 텍스트
     * @param string $keyword 검색어 (원본)
     * @return string <mark> 태그로 감싼 텍스트
     */
    public function highlightKeyword(string $safeText, string $keyword): string
    {
        if ($keyword === '') {
            return $safeText;
        }

        $safeKeyword = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
        return str_ireplace(
            $safeKeyword,
            '<mark>' . $safeKeyword . '</mark>',
            $safeText
        );
    }

    /**
     * 숫자 축약 포맷
     *
     * @param int $num 숫자
     * @return string 축약 포맷 (예: 1.2K, 3.4만)
     */
    public function number(int $num): string
    {
        if ($num >= 10000) {
            return round($num / 10000, 1) . '만';
        }
        if ($num >= 1000) {
            return round($num / 1000, 1) . 'K';
        }
        return number_format($num);
    }

    /**
     * 파일 크기 포맷
     *
     * @param int $bytes 바이트 크기
     * @return string 포맷된 크기 (예: 1.2 MB)
     */
    public function bytes(int $bytes): string
    {
        return StringHelper::formatBytes($bytes);
    }

    /**
     * 이름 마스킹
     *
     * @param string $name 원본 이름
     * @param int $showStart 앞에서 보여줄 글자 수 (기본: 1)
     * @return string 마스킹된 이름 (예: '홍**')
     */
    public function maskName(string $name, int $showStart = 1): string
    {
        return StringHelper::mask($name, $showStart, 0);
    }

    /**
     * 상대시간 (한국어)
     *
     * @param string $datetime 날짜/시간 문자열
     * @return string 상대시간 (예: '3분 전', '어제')
     */
    public function relativeTime(string $datetime): string
    {
        try {
            $dt = new \DateTimeImmutable($datetime);
        } catch (\Exception) {
            return '';
        }

        $now = new \DateTimeImmutable();
        $diff = $now->getTimestamp() - $dt->getTimestamp();

        if ($diff < 0) {
            return $dt->format('m-d');
        }
        if ($diff < 60) {
            return '방금 전';
        }
        if ($diff < 3600) {
            return (int) ($diff / 60) . '분 전';
        }
        if ($diff < 86400) {
            return (int) ($diff / 3600) . '시간 전';
        }
        if ($diff < 172800) {
            return '어제';
        }

        return $dt->format('m-d');
    }
}
