import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 業務データの入れ物の複数選べる参照(docs の 設計/業務データの入れ物/画面/README.md 6 節の管理の分の「複数選べる参照」、
// 画面/業務の画面.md 2.2 の「複数の参照の欄」)。システム管理者に切り替えて項目を足すので demo の持ち物。業務モジュール「備品」(コード equipment)は
// records-admin.spec.ts の筋が作る(名前の順で先に走る。名前は後の筋が変えるので、コードと URL で引く)。
// 足した項目とレコードは同じ筋の中で消して戻す(ほかの筋の件数を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

/** 足す項目(型「ユーザー(複数)」・一覧に表示)と、登録で選ぶ 3 人(検索する語・候補の表示名) */
const FIELD = { code: 'users_in_charge', name: '使用者' };
const PICKS = [
    ['高橋', '高橋 誠'],
    ['中村', '中村 美咲'],
    ['山田', '山田 恵'],
] as const;
const ITEM_NAME = 'E2E の複数の参照';

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
 * 管理の画面で、型「ユーザー(複数)」の項目(一覧に表示)を足す。
 */
async function addMultipleUserField(page: Page) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill(FIELD.code);
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill(FIELD.name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page
        .getByRole('option', { name: 'ユーザー(複数)', exact: true })
        .click();
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
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
 * 登録のフォームの複数の参照の欄で、PICKS の 3 人を順に選ぶ(選んだ相手は候補に出ない = 画面/業務の画面.md 2.2)。チップの並びを返す。
 */
async function pickUsers(page: Page, form: Locator) {
    const field = form.getByRole('group', { name: FIELD.name, exact: true });
    for (const [query, name] of PICKS) {
        await field.getByRole('combobox').click();
        await page.getByPlaceholder('検索する語').fill(query);
        await page.getByRole('option', { name, exact: true }).click();
        await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
    }

    return field
        .getByRole('list', { name: `${FIELD.name}の選択` })
        .getByRole('listitem');
}

/**
 * 編集の画面の初期値のチップも、候補から選んだときと同じ所属(主所属の組織の名前)を出す(画面/業務の画面.md 2.2)。
 */
async function expectEditChipsKeepAffiliation(page: Page, detailUrl: string) {
    await page.goto(`${detailUrl}/edit`);
    const chips = page
        .getByRole('group', { name: FIELD.name, exact: true })
        .getByRole('list', { name: `${FIELD.name}の選択` })
        .getByRole('listitem');
    await expect(chips).toHaveCount(3);
    await expect(chips.nth(0)).toContainText('高橋 誠');
    await expect(chips.nth(0)).toContainText('第一営業部');
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

test('「ユーザー(複数)」の項目で 3 人を選ぶとチップが 3 つ並び、詳細は全員を「、」でつなぎ、一覧は先頭の 2 人と「ほか 1 名」', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/admin/modules/equipment');
    await addMultipleUserField(page);

    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: /の入力$/ });
    await form
        .getByRole('textbox', { name: '品名', exact: true })
        .fill(ITEM_NAME);
    const chips = await pickUsers(page, form);
    await expect(chips).toHaveCount(3);
    await expect(chips.nth(0)).toContainText('高橋 誠');
    await expect(chips.nth(0)).toContainText('第一営業部');
    await expect(chips.nth(2)).toContainText('山田 恵');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();

    // 詳細は全員を並びの順に「、」でつなぐ(画面/README.md 1.3)
    await expect(
        page.getByRole('region', { name: `${ITEM_NAME}の値` }),
    ).toContainText('高橋 誠、中村 美咲、山田 恵');
    const detailUrl = page.url();
    await expectEditChipsKeepAffiliation(page, detailUrl);

    // 一覧の列は先頭の 2 人と「ほか 1 名」
    await page.goto('/records/data/equipment');
    await expect(
        page
            .getByRole('region', { name: /の一覧$/ })
            .getByRole('row')
            .filter({ has: page.getByRole('link', { name: ITEM_NAME }) }),
    ).toContainText('高橋 誠、中村 美咲 ほか 1 名');

    await removeRecordAndField(page, detailUrl);
});
