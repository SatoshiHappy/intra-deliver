import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { todayInTokyo } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';

// 業務データの入れ物の相対の既定値(docs の 設計/業務データの入れ物/項目と規則/README.md 3 節、画面/管理の画面.md 3.3・3.4、
// 画面/業務の画面.md 2.2 の「初期値」)。システム管理者に切り替えて項目を足すのでデモユーザー切替の持ち物。業務モジュール「備品」
// (コード equipment)は records-admin.spec.ts の筋が作る(名前の順で先に走る。名前は後の筋が変えるので、コードと URL で引く)。
// 足した項目とレコードは同じ筋の中で消して戻す(ほかの筋の件数を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

const FIELD = { code: 'check_on', name: '点検予定日' };
const ITEM_NAME = 'E2E の相対の既定値';
const DAYS = 30;

/**
 * Asia/Tokyo の今日から days 日後(YYYY-MM-DD。走らせる日に依らず、東京の日付から作る)。
 */
function tokyoDayAfter(days: number): string {
    const [year, month, day] = todayInTokyo().split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day + days))
        .toISOString()
        .slice(0, 10);
}

/**
 * ゲストからシステム管理者に切り替えて、path を開く。
 */
async function openAsAdministrator(page: Page, path: string) {
    await page.goto('/');
    await page
        .getByRole('button', { name: '利用者メニュー：ゲスト', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('menu')
            .getByRole('menuitem')
            .filter({ hasText: 'システム管理者' }),
    );
    await expect(
        page.getByRole('button', {
            name: '利用者メニュー：伊藤 涼子（経営企画室）',
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(path);
}

/**
 * 管理の画面で、型「日付」の項目を、既定値「登録の時から 30 日後」で足す。
 */
async function addRelativeDateField(page: Page) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill(FIELD.code);
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill(FIELD.name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page.getByRole('option', { name: '日付', exact: true }).click();
    const defaultValue = form.getByRole('group', {
        name: '既定値',
        exact: true,
    });
    const mode = defaultValue.getByRole('combobox', {
        name: '既定値の指定',
        exact: true,
    });
    // 「固定の値」では、値の欄を名前「既定値の固定の値」で引ける(ラベル「既定値」は持ち方の選択に結ぶ)
    await mode.click();
    await page.getByRole('option', { name: '固定の値', exact: true }).click();
    await expect(
        defaultValue.getByLabel('既定値の固定の値', { exact: true }),
    ).toBeVisible();
    await mode.click();
    await page
        .getByRole('option', { name: '登録の時から', exact: true })
        .click();
    await defaultValue
        .getByRole('textbox', { name: '登録の時からの数', exact: true })
        .fill(String(DAYS));
    await defaultValue
        .getByRole('combobox', { name: '単位', exact: true })
        .click();
    await page.getByRole('option', { name: '日', exact: true }).click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        '/records/admin/modules/equipment',
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 足したレコード(詳細の URL)と項目を消して戻す。
 */
async function removeRecordAndField(page: Page, detailUrl: string) {
    await page.goto(detailUrl);
    await page.getByRole('button', { name: '削除', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: 'レコードの削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
    await page.goto('/records/admin/modules/equipment');
    await page
        .getByRole('button', { name: `${FIELD.name}を削除`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: '項目の削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('項目を削除しました', { exact: true }),
    ).toBeVisible();
}

test('日付の項目の既定値を「登録の時から 30 日後」にすると、項目の表の札に出て、登録のフォームの初期値が東京の今日の 30 日後になる', async ({
    page,
}) => {
    const expected = tokyoDayAfter(DAYS);
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    await addRelativeDateField(page);
    await expect(
        page.getByText(`既定値: 登録の時から ${DAYS} 日後`, { exact: true }),
    ).toBeVisible();

    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: /の入力$/ });
    await expect(
        form
            .getByRole('group', { name: FIELD.name, exact: true })
            .locator('input[type="date"]'),
    ).toHaveValue(expected);
    await form
        .getByRole('textbox', { name: '品名', exact: true })
        .fill(ITEM_NAME);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    const detailUrl = page.url();

    // 詳細の日付は YYYY/MM/DD(画面/README.md 1.3)
    await expect(
        page
            .getByRole('region', { name: `${ITEM_NAME}の値` })
            .getByText(expected.replaceAll('-', '/'), { exact: true }),
    ).toBeVisible();

    await removeRecordAndField(page, detailUrl);
});
