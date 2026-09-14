const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * 편집기 접근성(툴바 role·버튼 이름·모달 dialog·Tab 포커스 트랩) 회귀 테스트.
 *
 * 배경: 툴바 버튼은 아이콘만 든 <button> 이라 스크린리더가 읽을 이름이 없었고,
 * 모달은 dialog 로 선언되지 않아 보조기술이 그냥 div 로 읽었으며, 열려 있는 동안
 * Tab 이 모달 밖으로 새어 뒤쪽 페이지를 훑었다.
 *
 * 두 편집기에 같은 스펙을 돌린다 — 이유는 editor-inline-style.spec.js 와 같다.
 * 블록 HTML 편집기는 별도 아티팩트라 한쪽만 고치면 조용히 갈라진다.
 *
 * 이 스펙은 서버도 로그인도 DB 도 쓰지 않는다.
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

for (const target of EDITORS) {
  test.describe(target.label, () => {
    test.beforeEach(async ({ page }) => {
      await page.setContent('<!DOCTYPE html><html><body><div id="ed"></div><button id="outside">뒤쪽</button></body></html>');
      await page.addScriptTag({ path: target.js });
      await page.evaluate((globalName) => {
        // 편집기는 클래식 스크립트의 최상위 const 라 window 프로퍼티가 아니다.
        const factory = new Function(`return ${globalName}`)();
        window.__editor = factory.create('#ed', { toolbar: 'full' });
      }, target.global);
    });

    test('툴바가 role=toolbar 로 선언된다', async ({ page }) => {
      const role = await page.evaluate(() => {
        const toolbar = document.querySelector('.mublo-editor-toolbar');
        return toolbar && toolbar.getAttribute('role');
      });

      expect(role).toBe('toolbar');
    });

    test('아이콘 버튼에 읽을 수 있는 이름이 붙는다', async ({ page }) => {
      const result = await page.evaluate(() => {
        const buttons = Array.from(document.querySelectorAll('.mublo-editor-toolbar .mublo-editor-btn'));
        return {
          total: buttons.length,
          named: buttons.filter((b) => (b.getAttribute('aria-label') || '').trim().length > 0).length,
        };
      });

      expect(result.total).toBeGreaterThan(0);
      // 드롭다운·색상 선택기 토글은 별도 경로로 만들어지므로 전량 일치를 요구하지 않는다
      expect(result.named).toBeGreaterThan(0);
    });

    test('모달이 dialog 로 선언되고 이름을 갖는다', async ({ page }) => {
      const attrs = await page.evaluate(() => {
        window.__editor._createModal('링크 삽입', '<input type="text" id="a"><input type="text" id="b">', '삽입', () => true);
        const modal = document.getElementById('mublo-editor-modal');
        return {
          role: modal.getAttribute('role'),
          modal: modal.getAttribute('aria-modal'),
          label: modal.getAttribute('aria-label'),
        };
      });

      expect(attrs.role).toBe('dialog');
      expect(attrs.modal).toBe('true');
      expect(attrs.label).toBe('링크 삽입');
    });

    test('모달 제목의 마크업은 이름에서 걷어낸다', async ({ page }) => {
      const label = await page.evaluate(() => {
        window.__editor._createModal('<span class="x">표 삽입</span>', '<input type="text" id="a">', '삽입', () => true);
        return document.getElementById('mublo-editor-modal').getAttribute('aria-label');
      });

      expect(label).toBe('표 삽입');
    });

    test('모달이 열려 있는 동안 Tab 이 밖으로 새지 않는다', async ({ page }) => {
      await page.evaluate(() => {
        window.__editor._createModal('링크 삽입', '<input type="text" id="a"><input type="text" id="b">', '삽입', () => true);
      });

      // 마지막 포커스 대상에서 Tab → 첫 대상으로 돌아와야 한다
      const wrapped = await page.evaluate(() => {
        const modal = document.getElementById('mublo-editor-modal');
        const focusables = Array.from(modal.querySelectorAll(
          'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])'
        )).filter((el) => !el.disabled && el.offsetParent !== null);

        focusables[focusables.length - 1].focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

        return document.activeElement === focusables[0];
      });

      expect(wrapped).toBe(true);
    });

    test('모달 밖에 포커스가 있으면 모달 안으로 되돌린다', async ({ page }) => {
      const pulledBack = await page.evaluate(() => {
        window.__editor._createModal('링크 삽입', '<input type="text" id="a">', '삽입', () => true);
        const modal = document.getElementById('mublo-editor-modal');

        document.getElementById('outside').focus();
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Tab', bubbles: true }));

        return modal.contains(document.activeElement);
      });

      expect(pulledBack).toBe(true);
    });
  });
}
