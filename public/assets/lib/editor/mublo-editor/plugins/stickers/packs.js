/**
 * 머블로 기본 스티커 팩 등록 (v1.6)
 *
 * MubloEditorStickers.js 다음에 로드하면 두 팩이 등록된다.
 * - 머블로봇: 자체 제작 SVG 마스코트 10종 (tools/make-mublobot-stickers.mjs 로 생성)
 * - Twemoji: CC-BY 4.0 이모지 24종 (twemoji/LICENSE.txt 참조 — 공개 사이트 사용 시 고지 필요)
 *
 * 경로는 이 스크립트 위치 기준으로 자동 계산되므로 어디에 배포해도 동작한다.
 */
(function () {
    'use strict';
    if (typeof MubloEditorStickers === 'undefined') {
        console.error('[mublo-sticker-packs] MubloEditorStickers.js must be loaded first');
        return;
    }

    const base = (document.currentScript && document.currentScript.src)
        ? document.currentScript.src.replace(/[^/]+$/, '')
        : 'plugins/stickers/';

    MubloEditorStickers.setPacks([
        {
            name: '머블로봇',
            baseUrl: base + 'mublobot/',
            items: [
                { file: 'best.svg', label: '기분 최고!' },
                { file: 'like.svg', label: '좋아요!' },
                { file: 'party.svg', label: '축하해요!' },
                { file: 'love.svg', label: '사랑해요' },
                { file: 'fighting.svg', label: '파이팅!' },
                { file: 'study.svg', label: '열공중' },
                { file: 'surprise.svg', label: '놀랐어요' },
                { file: 'sad.svg', label: '슬퍼요' },
                { file: 'angry.svg', label: '화났어요' },
                { file: 'sleepy.svg', label: '졸려요' }
            ]
        },
        {
            name: 'Twemoji',
            baseUrl: base + 'twemoji/',
            items: [
                { file: '1f600.svg', label: '웃음' },
                { file: '1f602.svg', label: '빵터짐' },
                { file: '1f60d.svg', label: '하트눈' },
                { file: '1f60e.svg', label: '멋짐' },
                { file: '1f914.svg', label: '음...' },
                { file: '1f973.svg', label: '파티' },
                { file: '1f97a.svg', label: '글썽' },
                { file: '1f621.svg', label: '부글부글' },
                { file: '1f634.svg', label: '쿨쿨' },
                { file: '1f44d.svg', label: '좋아요' },
                { file: '1f44f.svg', label: '짝짝짝' },
                { file: '1f64f.svg', label: '부탁해요' },
                { file: '1f4aa.svg', label: '힘내요' },
                { file: '1f525.svg', label: '열정' },
                { file: '2764.svg', label: '하트' },
                { file: '1f389.svg', label: '축하' },
                { file: '1f381.svg', label: '선물' },
                { file: '2b50.svg', label: '별' },
                { file: '1f4af.svg', label: '백점' },
                { file: '1f680.svg', label: '발사' },
                { file: '2705.svg', label: '완료' },
                { file: '274c.svg', label: '안돼요' },
                { file: '26a0.svg', label: '주의' },
                { file: '1f4a1.svg', label: '아이디어' }
            ]
        }
    ]);
})();
