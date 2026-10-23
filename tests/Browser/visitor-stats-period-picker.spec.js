const path = require('path');
const { test, expect } = require('@playwright/test');

// 실제 기간 선택 스크립트를 실행한다. 서버·로그인·DB는 사용하지 않는다.
for (const timezoneId of ['Asia/Seoul', 'America/New_York']) {
  test.describe(timezoneId, () => {
    test.use({ timezoneId });

    for (const id of ['vs-period', 'cs-period', 'cv-period']) {
      test(`${id}: 표시 기간과 요청 기간이 366일 제한에 맞춰 함께 보정된다`, async ({ page }) => {
        await page.setContent(`<style>.d-none{display:none}.d-flex{display:flex}</style>
          <select id="${id}"><option value="last_7_days">최근 7일</option></select>`);
        await page.evaluate(() => {
          window.notices = [];
          window.periods = [];
          window.MubloRequest = { showAlert: message => window.notices.push(message) };
          document.querySelector('select').addEventListener('change', event => {
            window.periods.push(event.target.value);
          });
        });
        await page.addScriptTag({
          path: path.resolve(__dirname, '../../plugins/VisitorStats/assets/js/period-picker.js'),
        });
        await page.selectOption('select', { label: '기간 지정' });

        // 일반 범위, 하루, 정확히 366일, 367일, 윤년을 가로지르는 범위.
        for (const [start, end, expectedStart, adjusted] of [
          ['2026-09-01', '2026-09-15', '2026-09-01', false],
          ['2026-09-15', '2026-09-15', '2026-09-15', false],
          ['2025-09-15', '2026-09-15', '2025-09-15', false],
          ['2025-09-14', '2026-09-15', '2025-09-15', true],
          ['2020-01-01', '2024-03-01', '2023-03-02', true],
        ]) {
          await page.evaluate(() => { window.notices = []; window.periods = []; });
          await page.getByLabel('시작일').fill(start);
          await page.getByLabel('종료일').fill(end);
          await page.getByRole('button', { name: '적용' }).click();
          await expect(page.getByLabel('시작일')).toHaveValue(expectedStart);
          await expect(page.getByLabel('종료일')).toHaveValue(end);
          expect(await page.evaluate(() => window.periods)).toEqual([`custom:${expectedStart}:${end}`]);
          const notices = await page.evaluate(() => window.notices);
          expect(notices).toHaveLength(adjusted ? 1 : 0);
          if (adjusted) expect(notices[0]).toContain(expectedStart);
        }
      });
    }
  });
}
