const path = require('path');
const { test, expect } = require('@playwright/test');

// 같은 공통 동작을 두 편집기에서 검증한다. 서버·로그인·DB는 필요하지 않다.
const EDITORS = [
  ['MubloEditor', 'public/assets/lib/editor/mublo-editor/MubloEditor.js'],
  ['BlockHtmlEditorBase', 'public/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js'],
];

for (const [name, script] of EDITORS) {
  test.describe(name, () => {
    test.beforeEach(async ({ page }) => {
      await page.setContent('<textarea id="ed"></textarea>');
      await page.addScriptTag({ path: path.resolve(__dirname, '../..', script) });
      await page.evaluate(name => {
        window.__editor = new Function(`return ${name}`)().create('#ed');
      }, name);
    });

    test('저장 시 클래스·ID·접근성 속성이 있는 span의 경계를 보존한다', async ({ page }) => {
      const html = '<p><span class="price">100</span><span class="currency" aria-label="통화">KRW</span>'
        + '<span id="first" style="color: red;">A</span><span id="second" style="color: red;">B</span>'
        + '<span class="badge">C</span><span class="badge">D</span></p>';
      const saved = await page.evaluate(html => {
        window.__editor.setHTML(html);
        return window.__editor.getHTML();
      }, html);
      expect(saved).toBe(html);
    });

    test('저장 시 빈 아이콘·앵커·CSS 장식 요소를 보존한다', async ({ page }) => {
      const html = '<p>Menu<span class="bi bi-search" aria-hidden="true"></span>'
        + '<span id="details"></span><span data-counter="visits"></span>'
        + '<span style="display: inline-block; width: 10px;"></span></p>';
      const saved = await page.evaluate(html => {
        window.__editor.setHTML(html);
        return window.__editor.getHTML();
      }, html);
      expect(saved).toBe(html);
    });

    test('서식만 같은 span은 저장 시 계속 병합한다', async ({ page }) => {
      const saved = await page.evaluate(() => {
        window.__editor.setHTML('<p><span style="color: red;">A</span><span style="color: red;">B</span></p>');
        return window.__editor.getHTML();
      });
      expect(saved).toBe('<p><span style="color: red;">AB</span></p>');
    });

    for (const selected of [0, 1]) {
      test(`인접 서식 병합 후 선택한 글자 뒤에 입력한다 (${selected})`, async ({ page }) => {
        const html = await page.evaluate(selected => {
          const ed = window.__editor;
          ed.contentArea.innerHTML = selected === 1
            ? '<p><span style="color: red;">hello</span><span style="color: blue;"> world</span> tail</p>'
            : '<p><span style="color: blue;">hello</span><span style="color: red;"> world</span> tail</p>';
          const range = document.createRange();
          range.selectNode(ed.contentArea.querySelectorAll('span')[selected]);
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
          ed._saveSelection();
          ed._exec('foreColor', 'red');
          document.execCommand('insertText', false, 'X');
          return ed.getHTML();
        }, selected);
        expect(html).toBe(selected === 1
          ? '<p><span style="color: red;">hello worldX</span> tail</p>'
          : '<p><span style="color: red;">helloX world</span> tail</p>');
        expect(html).not.toContain('<!--');
      });
    }

    for (const source of ['url', 'upload']) {
      for (const linked of [false, true]) {
        test(`신규 이미지에 대체 텍스트·캡션을 반영한다 (${source}, 링크 ${linked})`, async ({ page }) => {
          await page.evaluate(() => {
            const ed = window.__editor;
            ed.setHTML('<p>Hello</p>');
            ed.focus();
            ed.setImageUploadHandler(async () => 'https://example.com/upload.png');
            ed._openImageDialog();
          });
          if (source === 'upload') {
            await page.locator('#mublo-editor-image-input').setInputFiles({
              name: 'original-name.png',
              mimeType: 'image/png',
              buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=', 'base64'),
            });
          } else {
            await page.locator('#mublo-editor-image-url').fill('https://example.com/image.png');
            await page.locator('#mublo-editor-image-url-add').click();
          }
          await expect(page.locator('#mublo-editor-image-meta')).toBeVisible();
          await page.locator('#mublo-editor-image-alt').fill('설명 문구');
          await page.locator('#mublo-editor-image-caption').fill('사진 캡션');
          if (linked) await page.locator('#mublo-editor-image-link').fill('https://example.com/detail');
          await page.locator('#mublo-editor-image-insert').click();
          const area = page.locator('.mublo-editor-content');
          await expect(area.locator('img')).toHaveAttribute('alt', '설명 문구');
          await expect(area.locator('figure figcaption')).toHaveText('사진 캡션');
          if (linked) {
            await expect(area.locator('figure > a')).toHaveAttribute('href', 'https://example.com/detail');
            await expect(area.locator('figure > a > img')).toHaveCount(1);
          } else {
            await expect(area.locator('a')).toHaveCount(0);
          }
          const saved = await page.evaluate(() => window.__editor.getHTML());
          expect(saved).toContain('사진 캡션');
        });
      }
    }
  });
}
