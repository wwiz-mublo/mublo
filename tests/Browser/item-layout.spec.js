const path = require('path');
const { test, expect } = require('@playwright/test');

const root = path.resolve(__dirname, '../..');
const itemLayoutJs = path.join(root, 'public/assets/js/MubloItemLayout.js');
const blockCss = path.join(root, 'public/assets/css/block.css');
const blockRowFormJs = path.join(root, 'public/assets/js/admin/blockrow-form.js');
const editorBaseJs = path.join(root, 'public/assets/js/admin/block-html-editor/BlockHtmlEditorBase.js');
const editorJs = path.join(root, 'public/assets/js/admin/block-html-editor/index.js');
const blockPreviewJs = path.join(root, 'public/assets/js/admin/block-preview-iframe.js');

test('PC/MO none mode really hides and restores the server-rendered slot', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 800 });
  await page.setContent(`
    <div class="mublo-item-layout" data-pc-style="none" data-mo-style="list"
         data-pc-cols="4" data-mo-cols="2"><ul><li>A</li><li>B</li></ul></div>
  `);
  await page.addStyleTag({ path: blockCss });
  await page.addScriptTag({ path: itemLayoutJs });

  const layout = page.locator('.mublo-item-layout');
  await expect(layout).toHaveClass(/is-hidden/);
  await expect(layout).toBeHidden();

  await page.setViewportSize({ width: 500, height: 800 });
  await expect(layout).toHaveClass(/is-list/);
  await expect(layout).toBeVisible();
  await expect(layout).toHaveClass(/is-mobile-layout/);

  await page.setViewportSize({ width: 1200, height: 800 });
  await expect(layout).toHaveClass(/is-hidden/);
});

test('slide options and cover are rebuilt across the responsive boundary', async ({ page }) => {
  await page.setViewportSize({ width: 1200, height: 800 });
  await page.setContent(`
    <div class="mublo-item-layout" data-pc-style="slide" data-mo-style="slide"
         data-pc-cols="2" data-mo-cols="1"
         data-pc-autoplay="5000" data-mo-autoplay="3000"
         data-pc-loop="true" data-mo-loop="false"
         data-pc-slide-cover="true">
      <ul>
        <li><img alt="a" width="240" height="120" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='120'/%3E"></li>
        <li><img alt="b" width="240" height="100" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='100'/%3E"></li>
        <li><img alt="c" width="240" height="140" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='240' height='140'/%3E"></li>
      </ul>
    </div>
  `);
  await page.addInitScript(() => {
    window.__swiperOptions = [];
    window.Swiper = class {
      constructor(element, options) {
        this.element = element;
        this.options = options;
        this.autoplay = options.autoplay ? { stop() {}, start() {} } : null;
        window.__swiperOptions.push(options);
      }
      update() {}
      destroy() {}
    };
  });
  // addInitScript is for navigations; install the fake in the current setContent document as well.
  await page.evaluate(() => {
    window.__swiperOptions = [];
    window.Swiper = class {
      constructor(element, options) {
        this.element = element;
        this.options = options;
        this.autoplay = options.autoplay ? { stop() {}, start() {} } : null;
        window.__swiperOptions.push(options);
      }
      update() {}
      destroy() {}
    };
  });
  await page.addStyleTag({ path: blockCss });
  await page.addScriptTag({ path: itemLayoutJs });

  const layout = page.locator('.mublo-item-layout');
  await expect(layout).toHaveClass(/is-slide/);
  await expect.poll(() => page.evaluate(() => document.querySelector('.mublo-item-layout').classList.contains('has-fixed-height'))).toBe(true);
  expect(await page.evaluate(() => window.__swiperOptions.at(-1).keyboard.enabled)).toBe(true);
  expect(await page.evaluate(() => window.__swiperOptions.at(-1).a11y.enabled)).toBe(true);
  expect(await page.evaluate(() => window.__swiperOptions.at(-1).autoplay.delay)).toBe(5000);

  await page.setViewportSize({ width: 500, height: 800 });
  await expect(layout).toHaveClass(/is-mobile-layout/);
  await expect.poll(() => page.evaluate(() => window.__swiperOptions.length)).toBeGreaterThan(1);
  await expect.poll(() => page.evaluate(() => document.querySelector('.mublo-item-layout').classList.contains('has-fixed-height'))).toBe(false);
  expect(await page.evaluate(() => window.__swiperOptions.at(-1).autoplay.delay)).toBe(3000);
  expect(await page.evaluate(() => !!window.__swiperOptions.at(-1).loop)).toBe(false);
});

test('reduced motion disables autoplay and dynamic layouts clean themselves up', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.setContent('<main id="root"></main>');
  await page.evaluate(() => {
    window.__swiperOptions = [];
    window.Swiper = class {
      constructor(element, options) {
        this.options = options;
        this.autoplay = null;
        window.__swiperOptions.push(options);
      }
      update() {}
      destroy() {}
    };
  });
  await page.addScriptTag({ path: itemLayoutJs });
  await page.locator('#root').evaluate(rootElement => {
    rootElement.insertAdjacentHTML('beforeend', `
      <div class="mublo-item-layout" data-pc-style="slide" data-mo-style="slide"
           data-pc-cols="1" data-mo-cols="1" data-pc-autoplay="5000">
        <ul><li>A</li><li>B</li></ul>
      </div>`);
  });

  const layout = page.locator('.mublo-item-layout');
  await expect(layout).toHaveClass(/is-slide/);
  expect(await page.evaluate(() => window.__swiperOptions.at(-1).autoplay)).toBe(false);
  await layout.evaluate(element => element.remove());
  await expect.poll(() => page.evaluate(() => Object.keys(window.MubloItemLayout.instances).length)).toBe(0);
});

test('dual list labels are text-only and keyboard selectable', async ({ page }) => {
  await page.setContent('<div id="list"></div>');
  await page.evaluate(() => {
    window.__alerts = [];
    window.MubloRequest = { showAlert(message) { window.__alerts.push(message); } };
  });
  await page.addScriptTag({ path: blockRowFormJs });
  await page.evaluate(() => {
    window.__xss = false;
    window.__listbox = new window.MubloDualListbox('#list', {
      available: [{ id: 'bad', label: '<img src=x onerror="window.__xss=true">게시판' }]
    });
  });

  await expect(page.locator('.dual-listbox-item')).toHaveText('<img src=x onerror="window.__xss=true">게시판');
  await expect(page.locator('.dual-listbox-item img')).toHaveCount(0);
  expect(await page.evaluate(() => window.__xss)).toBe(false);

  await page.locator('.dual-listbox-item').focus();
  await page.keyboard.press('Space');
  await expect(page.locator('.dual-listbox-item')).toHaveAttribute('aria-selected', 'true');
  await page.getByRole('button', { name: '선택 추가' }).click();
  expect(await page.evaluate(() => window.__listbox.getSelected())).toEqual(['bad']);
});

test('HTML block CSS preview is scoped away from the admin document', async ({ page }) => {
  await page.setContent('<textarea id="editor"></textarea><div id="outside" class="demo">outside</div>');
  await page.addScriptTag({ path: editorBaseJs });
  await page.addScriptTag({ path: editorJs });
  await page.evaluate(() => {
    window.BlockHtmlEditor.createVisual('#editor', {
      css: 'body { display:none; } .demo { color: rgb(255, 0, 0); }'
    });
  });

  expect(await page.locator('body').evaluate(element => getComputedStyle(element).display)).not.toBe('none');
  expect(await page.locator('#outside').evaluate(element => getComputedStyle(element).color)).not.toBe('rgb(255, 0, 0)');
  const scopedCss = await page.locator('#editor_visual_css').textContent();
  expect(scopedCss).toContain('.mublo-editor-content');
});

test('sandboxed block preview cannot access the parent admin document', async ({ page }) => {
  await page.setContent(`
    <div id="admin-secret">unchanged</div>
    <div id="previewLoading"></div>
    <iframe id="previewFrame" sandbox="allow-scripts" style="display:none"></iframe>
  `);
  await page.addScriptTag({ path: blockPreviewJs });
  await page.evaluate(() => {
    window.__previewEscaped = false;
    renderBlockPreviewIframe(`
      <div style="height:900px">preview</div>
      <script>
        try {
          parent.document.getElementById('admin-secret').textContent = 'compromised';
          parent.__previewEscaped = true;
        } catch (error) {}
      <\/script>
    `, [], [], { autoHeight: true });
  });

  await expect(page.locator('#previewFrame')).toBeVisible();
  await expect.poll(() => page.locator('#previewFrame').evaluate(frame => parseInt(frame.style.height || '0', 10)))
    .toBeGreaterThanOrEqual(200);
  await expect(page.locator('#admin-secret')).toHaveText('unchanged');
  expect(await page.evaluate(() => window.__previewEscaped)).toBe(false);
  await expect(page.locator('#previewFrame')).toHaveAttribute('sandbox', 'allow-scripts');
});
