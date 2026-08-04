const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * MubloEditor 인라인 서식(글자색·배경색·글자크기) 적용 회귀 테스트.
 *
 * 배경: 색을 적용할 때 기존 서식을 보지 않고 새 span 으로 감싸기만 해서 세 증상이
 * 한꺼번에 났다.
 *   - 이미 색이 있는 글자를 다시 칠하면 새 span 이 바깥을 감싸는데 CSS 는 안쪽이
 *     이기므로 화면이 바뀌지 않았다
 *   - 감싸기만 하니 중첩이 계속 쌓였다
 *   - 적용 후 캐럿이 span 끝으로 모이고, 그 상태에서 다시 고르면 제로폭 문자만 든
 *     빈 span 이 새로 생겨 누적됐다
 *
 * 이 스펙은 서버도 로그인도 DB 도 쓰지 않는다. 에디터 스크립트만 붙여 DOM 결과를
 * 확인하므로 다른 브라우저 테스트와 달리 환경변수 없이 항상 실행된다.
 */

const EDITOR_JS = path.resolve(__dirname, '../../public/assets/lib/editor/mublo-editor/MubloEditor.js');

test.beforeEach(async ({ page }) => {
  await page.setContent('<!DOCTYPE html><html><body><div id="ed"></div></body></html>');
  await page.addScriptTag({ path: EDITOR_JS });
  await page.evaluate(() => {
    window.__editor = MubloEditor.create('#ed', { toolbar: 'full' });
  });
});

/**
 * 본문을 초기화하고 첫 단어를 선택한 뒤 서식을 적용한다.
 * extractContents 가 빈 텍스트 노드를 남기므로 내용이 있는 첫 노드를 찾아야 한다.
 */
async function applyToFirstWord(page, { reset = null, command, value, length = 5 }) {
  return page.evaluate(({ reset, command, value, length }) => {
    const editor = window.__editor;
    const area = editor.contentArea;

    if (reset !== null) {
      area.innerHTML = reset;
    }

    const walker = document.createTreeWalker(area, NodeFilter.SHOW_TEXT);
    let node = walker.nextNode();
    while (node && node.data.replace(/​/g, '').trim().length === 0) {
      node = walker.nextNode();
    }
    if (!node) {
      throw new Error('선택할 텍스트 노드를 찾지 못했습니다.');
    }

    const range = document.createRange();
    range.setStart(node, 0);
    range.setEnd(node, Math.min(length, node.length));

    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    editor._saveSelection();

    editor._exec(command, value);

    return area.innerHTML;
  }, { reset, command, value, length });
}

test('같은 글자에 색을 다시 적용하면 교체된다', async ({ page }) => {
  await applyToFirstWord(page, { reset: '<p>hello world</p>', command: 'foreColor', value: 'red' });
  const html = await applyToFirstWord(page, { command: 'foreColor', value: 'blue' });

  // 바깥을 덧씌우면 CSS 상 안쪽이 이겨 화면이 바뀌지 않는다
  expect(html).toBe('<p><span style="color: blue;">hello</span> world</p>');
});

test('색을 여러 번 바꿔도 span 이 쌓이지 않는다', async ({ page }) => {
  await applyToFirstWord(page, { reset: '<p>hello world</p>', command: 'foreColor', value: 'red' });
  await applyToFirstWord(page, { command: 'foreColor', value: 'blue' });
  const html = await applyToFirstWord(page, { command: 'foreColor', value: 'green' });

  expect(html).toBe('<p><span style="color: green;">hello</span> world</p>');
  expect(html.match(/<span/g)).toHaveLength(1);
});

test('캐럿 상태에서 색만 계속 바꿔도 빈 span 이 누적되지 않는다', async ({ page }) => {
  const html = await page.evaluate(() => {
    const editor = window.__editor;
    editor.contentArea.innerHTML = '<p>hello world</p>';

    const node = editor.contentArea.querySelector('p').firstChild;
    const range = document.createRange();
    range.setStart(node, 5);
    range.collapse(true);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    editor._saveSelection();

    editor._exec('foreColor', 'red');
    editor._exec('foreColor', 'blue');
    editor._exec('foreColor', 'green');

    return editor.contentArea.innerHTML;
  });

  // 서식 예약용 span 은 하나만 남고, 색은 마지막 선택을 따른다
  expect(html.match(/<span/g)).toHaveLength(1);
  expect(html).toContain('color: green;');
});

test('저장본에는 서식 예약용 빈 span 이 남지 않는다', async ({ page }) => {
  const saved = await page.evaluate(() => {
    const editor = window.__editor;
    editor.contentArea.innerHTML = '<p>hello world</p>';

    const node = editor.contentArea.querySelector('p').firstChild;
    const range = document.createRange();
    range.setStart(node, 5);
    range.collapse(true);
    const selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    editor._saveSelection();

    editor._exec('foreColor', 'red');

    return editor.getHTML();
  });

  // getHTML 은 클론에서 정리한다 — 편집 중 캐럿에는 영향이 없어야 한다
  expect(saved).not.toContain('​');
  expect(saved.replace(/\s+/g, ' ')).toContain('hello world');
});

test('글자색과 배경색은 서로를 지우지 않는다', async ({ page }) => {
  await applyToFirstWord(page, { reset: '<p>hello world</p>', command: 'foreColor', value: 'red' });
  const html = await applyToFirstWord(page, { command: 'hiliteColor', value: 'yellow' });

  expect(html).toContain('color: red;');
  expect(html).toContain('background-color: yellow;');
});

test('선택하지 않은 글자의 서식은 건드리지 않는다', async ({ page }) => {
  // span 이 선택 밖 내용까지 갖고 있으면 조상 정리를 하면 안 된다
  const html = await applyToFirstWord(page, {
    reset: '<p><span style="color: red;">hello world</span></p>',
    command: 'foreColor',
    value: 'blue',
  });

  expect(html).toContain('color: blue;');
  expect(html).toContain('color: red;');
  expect(html).toContain('world');
});

test('글자 크기도 같은 규칙으로 교체된다', async ({ page }) => {
  await applyToFirstWord(page, { reset: '<p>hello world</p>', command: 'fontSize', value: '24px' });
  const html = await applyToFirstWord(page, { command: 'fontSize', value: '32px' });

  expect(html).toBe('<p><span style="font-size: 32px;">hello</span> world</p>');
});
