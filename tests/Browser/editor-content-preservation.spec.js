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

    test('저장 시 굵게·글꼴 span 의 빈 껍데기 제거와 병합도 동작한다', async ({ page }) => {
      // styleWithCSS 상태의 execCommand(굵게 등)와 <font> 변환이 만드는 span 은
      // color 계열이 아니다 — 서식 판정이 이들을 빠뜨리면 정리가 조용히 멈춘다
      const r = await page.evaluate(() => {
        const ed = window.__editor;
        const probe = (html) => { ed.setHTML(html); return ed.getHTML(); };
        return {
          boldShell: probe('<p>a<span style="font-weight: 700;"></span>b</p>'),
          boldMerge: probe('<p><span style="font-weight: 700;">foo</span><span style="font-weight: 700;">bar</span></p>'),
          fontMerge: probe('<p><span style="font-family: Arial;">foo</span><span style="font-family: Arial;">bar</span></p>'),
        };
      });
      expect(r.boldShell).toBe('<p>ab</p>');
      expect(r.boldMerge).toBe('<p><span style="font-weight: 700;">foobar</span></p>');
      expect(r.fontMerge).toBe('<p><span style="font-family: Arial;">foobar</span></p>');
    });

    test('여러 텍스트 노드로 쪼개진 빈 서식 span 도 저장 시 정리한다', async ({ page }) => {
      // extractContents/insertNode 는 빈 텍스트 노드를 남기곤 한다 —
      // childNodes 가 ['', ZWSP] 여도 합친 내용 기준으로 비어 있으면 지운다
      const saved = await page.evaluate(() => {
        const ed = window.__editor;
        ed.contentArea.innerHTML = '<p>a<span style="color: red;"></span>b</p>';
        const span = ed.contentArea.querySelector('span');
        span.appendChild(document.createTextNode(''));
        span.appendChild(document.createTextNode('\u200b'));
        return ed.getHTML();
      });
      expect(saved).toBe('<p>ab</p>');
    });

    test('업로드에서 대체 텍스트를 비워 두면 파일명 폴백을 지우지 않는다', async ({ page }) => {
      await page.evaluate(() => {
        const ed = window.__editor;
        ed.setHTML('<p>Hello</p>');
        ed.focus();
        ed.setImageUploadHandler(async () => 'https://example.com/upload.png');
        ed._openImageDialog();
      });
      await page.locator('#mublo-editor-image-input').setInputFiles({
        name: 'original-name.png',
        mimeType: 'image/png',
        buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=', 'base64'),
      });
      await expect(page.locator('#mublo-editor-image-meta')).toBeVisible();
      // 대체 텍스트는 비워 두고 캡션만 채운다 — 메타 적용이 돌면서도 alt 폴백은 남아야 한다
      await page.locator('#mublo-editor-image-caption').fill('사진 캡션');
      await page.locator('#mublo-editor-image-insert').click();
      const area = page.locator('.mublo-editor-content');
      await expect(area.locator('img')).toHaveAttribute('alt', 'original-name.png');
      await expect(area.locator('figure figcaption')).toHaveText('사진 캡션');
    });
  });
}
