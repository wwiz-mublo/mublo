const path = require('path');
const { test, expect } = require('@playwright/test');

const EDITOR_JS = path.resolve(__dirname, '../../public/assets/lib/editor/mublo-editor/MubloEditor.js');
const EDITOR_CSS = path.resolve(__dirname, '../../public/assets/lib/editor/mublo-editor/MubloEditor.css');

test('data 속성으로 모바일 툴바를 전환하고 본문은 유지한다', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 800 });
  await page.setContent(`<!DOCTYPE html><html><body>
    <textarea class="mublo-editor" id="ed"
      data-toolbar-items="source,bold,italic,underline,link,image,fullscreen"
      data-toolbar-items-mobile="bold,italic,link,image"
      data-toolbar-breakpoint="768"><p>작성 중인 본문</p></textarea>
  </body></html>`);
  await page.addScriptTag({ path: EDITOR_JS });

  const commands = () => page.evaluate(() => (
    [...MubloEditor.get('ed').getToolbar().querySelectorAll('[data-cmd]')]
      .map(button => button.dataset.cmd)
  ));

  expect(await commands()).toEqual(['source', 'bold', 'italic', 'underline', 'link', 'image', 'fullscreen']);

  await page.setViewportSize({ width: 600, height: 800 });
  await expect.poll(commands).toEqual(['bold', 'italic', 'link', 'image']);
  expect(await page.evaluate(() => MubloEditor.get('ed').getHTML())).toContain('작성 중인 본문');

  await page.setViewportSize({ width: 1024, height: 800 });
  await expect.poll(commands).toEqual(['source', 'bold', 'italic', 'underline', 'link', 'image', 'fullscreen']);
});

test('compact 프리셋이 모바일 툴바를 대체한다', async ({ page }) => {
  // 스킨이 버튼 이름을 나열하지 않고도 좁은 화면 툴바를 지정할 수 있어야 한다.
  await page.setViewportSize({ width: 1024, height: 800 });
  // 폭을 컨테이너로 고정해 body 여백에 좌우되지 않게 한다(실제 모바일 본문 폭 기준).
  await page.setContent(`<!DOCTYPE html><html><body style="margin:0">
    <div id="col" style="width:1024px">
      <textarea class="mublo-editor" id="ed"
        data-toolbar="full"
        data-toolbar-mobile="compact"><p>작성 중인 본문</p></textarea>
    </div>
  </body></html>`);
  await page.addStyleTag({ path: EDITOR_CSS });
  await page.addScriptTag({ path: EDITOR_JS });

  const commands = () => page.evaluate(() => (
    [...MubloEditor.get('ed').getToolbar().querySelectorAll('[data-cmd]')]
      .map(button => button.dataset.cmd)
  ));

  expect((await commands()).length).toBeGreaterThan(20);

  await page.setViewportSize({ width: 360, height: 800 });
  await page.locator('#col').evaluate(el => { el.style.width = '320px'; });
  await expect.poll(commands).toEqual([
    'undo', 'redo', 'bold', 'italic', 'underline', 'link', 'image',
  ]);
  expect(await page.evaluate(() => MubloEditor.get('ed').getHTML())).toContain('작성 중인 본문');

  // 320px 폭에서 한 줄에 들어가야 이 프리셋의 의미가 있다.
  // 구분선은 세로 margin 때문에 offsetTop 이 버튼과 다르므로 버튼만 센다.
  const rows = await page.locator('.mublo-editor-toolbar').evaluate(el => (
    new Set([...el.querySelectorAll('.mublo-editor-btn')].map(b => b.offsetTop)).size
  ));
  expect(rows).toBe(1);
});

test('data-toolbar-mobile 프리셋과 활성 모드 종료 버튼을 유지한다', async ({ page }) => {
  await page.setViewportSize({ width: 1024, height: 800 });
  await page.setContent(`<!DOCTYPE html><html><body>
    <textarea class="mublo-editor" id="ed"
      data-toolbar-items="source,bold"
      data-toolbar-mobile="minimal"
      data-toolbar-breakpoint="768"><p>content</p></textarea>
  </body></html>`);
  await page.addScriptTag({ path: EDITOR_JS });
  await page.evaluate(() => MubloEditor.get('ed')._toggleSource());

  await page.setViewportSize({ width: 600, height: 800 });
  const sourceButton = page.locator('.mublo-editor-toolbar [data-cmd="source"]');
  await expect(sourceButton).toHaveCount(1);
  await expect(sourceButton).toHaveClass(/active/);

  await sourceButton.click();
  expect(await page.evaluate(() => MubloEditor.get('ed').isSourceMode)).toBe(false);
  await expect(sourceButton).toHaveCount(0);
});
