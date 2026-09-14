const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * 이미지 업로드 취소 회귀 테스트.
 *
 * 배경: 이미지 모달의 삽입 핸들러는 업로드를 await 로 기다리는데, 그동안 ESC·배경
 * 클릭이 모달을 닫아도 진행 중인 핸들러는 취소되지 않았다. 업로드가 늦게 도착하면
 * 취소한 이미지가 본문에 삽입됐고, 그 사이 사용자가 다른 이미지의 교체 모드에
 * 들어가 있으면 insertImage 의 교체 분기가 그 무관한 이미지의 src 를 덮어썼다.
 * 이제 모달이 세대 번호를 갖고, 닫힘이 세대를 올려 늦은 결과를 버린다.
 *
 * 두 편집기에 같은 스펙을 돌린다 — 이유는 editor-inline-style.spec.js 와 같다.
 * 이 스펙은 서버도 로그인도 DB 도 쓰지 않는다. 업로드는 테스트가 쥔 Promise 로
 * 흉내 내서, "업로드가 끝나기 전에 닫는" 타이밍을 결정적으로 만든다.
 */

const EDITORS = [
  {
    label: 'MubloEditor',
    js: path.resolve(__dirname, '../../public/assets/lib/editor/mublo-editor/MubloEditor.js'),
    global: 'MubloEditor',
  },
  {
    label: 'BlockHtmlEditorBase',
    js: path.resolve(__dirname, '../../public/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js'),
    global: 'BlockHtmlEditorBase',
  },
];

const PNG = {
  name: 'late.png',
  mimeType: 'image/png',
  buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aXioAAAAASUVORK5CYII=', 'base64'),
};

/** 편집기를 띄우고, 업로드를 테스트가 resolve 하는 Promise 로 잡아 둔다 */
async function bootWithPendingUpload(page, target, initialHtml) {
  await page.setContent('<textarea id="ed"></textarea>');
  await page.addScriptTag({ path: target.js });
  await page.evaluate(({ globalName, initialHtml }) => {
    // 편집기는 클래식 스크립트의 최상위 const 라 window 프로퍼티가 아니다
    const factory = new Function(`return ${globalName}`)();
    const ed = factory.create('#ed');
    ed.setHTML(initialHtml);
    ed.focus();
    ed.setImageUploadHandler(() => new Promise((resolve) => {
      window.__resolveUpload = resolve;
    }));
    ed._openImageDialog();
    window.__editor = ed;
  }, { globalName: target.global, initialHtml });
}

/** 파일을 고르고 삽입을 눌러, 핸들러가 업로드를 기다리는 상태로 만든다 */
async function startInsert(page) {
  await page.locator('#mublo-editor-image-input').setInputFiles(PNG);
  await page.locator('#mublo-editor-image-insert').click();
  // 업로드 핸들러가 실제로 호출될 때까지 — 이 시점부터 핸들러는 await 에 걸려 있다
  await page.waitForFunction(() => typeof window.__resolveUpload === 'function');
}

for (const target of EDITORS) {
  test.describe(target.label, () => {
    test('업로드 중 모달을 닫으면 늦게 도착한 업로드가 삽입되지 않는다', async ({ page }) => {
      await bootWithPendingUpload(page, target, '<p>Hello</p>');
      await startInsert(page);

      await page.keyboard.press('Escape');
      await expect(page.locator('#mublo-editor-modal')).toHaveCount(0);

      const saved = await page.evaluate(async () => {
        window.__resolveUpload('https://example.com/late.png');
        await new Promise((r) => setTimeout(r, 100));
        return window.__editor.getHTML();
      });

      expect(saved).not.toContain('late.png');
      expect(saved).toContain('Hello');
      await expect(page.locator('.mublo-editor-content img')).toHaveCount(0);
    });

    test('취소한 업로드가 뒤늦게 교체 모드의 다른 이미지를 덮어쓰지 않는다', async ({ page }) => {
      await bootWithPendingUpload(page, target, '<p><img src="https://example.com/existing.png" alt="기존">x</p>');
      await startInsert(page);

      await page.keyboard.press('Escape');
      await expect(page.locator('#mublo-editor-modal')).toHaveCount(0);

      const src = await page.evaluate(async () => {
        const ed = window.__editor;
        // 사용자가 그 사이 기존 이미지의 교체 모드에 들어간 상황
        ed._replacingImage = ed.contentArea.querySelector('img');
        window.__resolveUpload('https://example.com/late.png');
        await new Promise((r) => setTimeout(r, 100));
        return ed.contentArea.querySelector('img').getAttribute('src');
      });

      expect(src).toBe('https://example.com/existing.png');
      await expect(page.locator('.mublo-editor-content img')).toHaveCount(1);
    });

    test('닫지 않으면 같은 경로로 정상 삽입된다 (대조군)', async ({ page }) => {
      await bootWithPendingUpload(page, target, '<p>Hello</p>');
      await startInsert(page);

      await page.evaluate(() => window.__resolveUpload('https://example.com/ok.png'));
      await expect(page.locator('.mublo-editor-content img')).toHaveAttribute('src', 'https://example.com/ok.png');
    });
  });
}
