const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * 블록 HTML 편집기의 이미지 링크 회귀 테스트.
 *
 * 배경: 이 편집기는 MubloEditor 와 공통 조상을 가진 별도 아티팩트다. 회원 에디터에서
 * 같은 버그를 고칠 때(이미지를 고르고 링크를 걸면 이미지 아래에 주소가 텍스트로만
 * 남던 문제) 이쪽에는 전파되지 않아, 관리자 블록 편집에서만 증상이 남아 있었다.
 * 헤더 주석의 "공통 코어 버그는 양쪽에 함께 수정한다" 규칙이 지켜졌는지 이 스펙이
 * 확인한다 — 다음번에 한쪽만 고치면 여기서 걸린다.
 *
 * 네 가지를 본다.
 *   - 이미지가 선택된 상태의 링크는 이미지를 <a>로 감싼다 (텍스트 앵커가 아니다)
 *   - 링크된 이미지의 unlink 는 앵커를 푼다 (접힌 캐럿에서 execCommand 가 무효라 직접 언랩)
 *   - javascript: 류 스킴은 입력 단계에서 거부한다 (setAttribute 경로는 출력 sanitize 를 지나간다)
 *   - 캡션을 붙일 때 figure 가 앵커째 감싼다 (img 만 옮기면 앵커가 빈 채로 남아 링크가 끊긴다)
 *
 * 이 스펙은 서버도 로그인도 DB 도 쓰지 않는다. 편집기 스크립트만 붙여 DOM 결과를
 * 확인하므로 다른 브라우저 테스트와 달리 환경변수 없이 항상 실행된다.
 */

const EDITOR_JS = path.resolve(__dirname, '../../public/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js');
const EDITOR_CSS = path.resolve(__dirname, '../../public/assets/lib/editor/mublo-editor/MubloEditor.css');

/** 이미지 한 장이 든 편집기를 띄우고 window.__ed / window.__img 로 노출한다 */
async function boot(page) {
    await page.setContent('<textarea id="block-html"></textarea>');
    await page.addStyleTag({ path: EDITOR_CSS });
    await page.addScriptTag({ path: EDITOR_JS });
    await page.evaluate(() => {
        const editor = BlockHtmlEditorBase.create(document.getElementById('block-html'));
        editor.contentArea.innerHTML = '<p><img src="/sample.png" alt="sample"></p>';
        window.__ed = editor;
        window.__img = editor.contentArea.querySelector('img');
    });
}

/** 링크 모달에 주소를 넣고 확인 버튼을 누른다 */
async function submitLinkModal(page, url) {
    await page.evaluate((value) => {
        document.querySelector('#mublo-editor-link-url').value = value;
        const buttons = Array.from(document.querySelectorAll('#mublo-editor-modal button'));
        buttons.find((b) => b.textContent.trim() === '삽입').click();
    }, url);
}

test('이미지를 선택한 채 링크를 걸면 이미지 자체가 앵커가 된다', async ({ page }) => {
    await boot(page);
    await page.evaluate(() => {
        window.__ed._selectImage(window.__img);
        window.__ed._insertLink();
    });
    await submitLinkModal(page, 'https://example.com');

    const html = await page.evaluate(() => window.__ed.contentArea.innerHTML);
    expect(html).toContain('<a href="https://example.com"');
    expect(html).toMatch(/<a[^>]*>\s*<img/);
    // 종전 증상: 이미지 아래에 주소가 텍스트로만 남았다
    expect(html).not.toMatch(/>https:\/\/example\.com</);
});

test('링크된 이미지에서 unlink 하면 앵커가 풀린다', async ({ page }) => {
    await boot(page);
    const html = await page.evaluate(() => {
        window.__ed._setImageLink(window.__img, 'https://example.com', '_blank');
        window.__ed._selectImage(window.__img);
        window.__ed._exec('unlink');
        return window.__ed.contentArea.innerHTML;
    });

    expect(html).not.toContain('<a ');
    expect(html).toContain('<img');
});

test('실행 가능한 스킴은 입력 단계에서 거부한다', async ({ page }) => {
    await boot(page);
    await page.evaluate(() => {
        window.__ed._selectImage(window.__img);
        window.__ed._insertLink();
    });
    // 탭 문자를 끼운 우회 형태도 함께 막혀야 한다
    await submitLinkModal(page, 'jav\tascript:alert(1)');

    const html = await page.evaluate(() => window.__ed.contentArea.innerHTML);
    expect(html).not.toContain('<a ');
});

test('캡션을 붙여도 이미지 링크가 끊기지 않는다', async ({ page }) => {
    await boot(page);
    const html = await page.evaluate(() => {
        window.__ed._setImageLink(window.__img, 'https://example.com', '_blank');
        window.__ed._applyImageMetadata(window.__img, 'sample', '캡션');
        return window.__ed.contentArea.innerHTML;
    });

    // figure > a > img — 앵커째 감싸야 링크가 남는다
    expect(html).toMatch(/<figure[^>]*>\s*<a[^>]*>\s*<img/);
    expect(html).toContain('캡션');
});
