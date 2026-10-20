import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 業務データの入れ物の行の権限の管理の操作(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 7 節の E2E の見える範囲の分)。
// システム管理者に切り替えて定義を変えるので demo の持ち物。業務モジュール「備品」(コード equipment)は records-admin.spec.ts の筋が作る
// (名前の順で先に走る。名前は後の筋が変えるので、コードと URL で引く)。変えた見える範囲と足した項目は同じ筋の中で戻す(ほかの筋を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

/** 足す項目(型「ユーザー」・関係する人) */
const FIELD = { code: 'related_user', name: '担当者' };
const MODULE_PATH = '/records/admin/modules/equipment';

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
 * 業務モジュールの編集で見える範囲を選んで更新し、定義の画面へ移ったことを確かめる。
 */
async function chooseScope(page: Page, label: string) {
    await page.goto(`${MODULE_PATH}/edit`);
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    await form
        .getByRole('radiogroup', { name: '見える範囲', exact: true })
        .getByRole('radio', { name: label, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        MODULE_PATH,
    );
    await expect(
        page.getByText('業務モジュールを更新しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 定義の画面の項目の表の、足した項目の行。
 */
function fieldRow(page: Page) {
    return page.getByRole('row').filter({
        has: page.getByRole('cell', { name: FIELD.code, exact: true }),
    });
}

/**
 * 型「ユーザー」の項目を「関係する人」を付けて足す。
 */
async function addRelatedUserField(page: Page) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill(FIELD.code);
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill(FIELD.name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page.getByRole('option', { name: 'ユーザー', exact: true }).click();
    const flag = form.getByRole('checkbox', {
        name: '関係する人',
        exact: true,
    });
    await expect(flag).not.toBeChecked();
    await expect(flag).toHaveAccessibleDescription(
        '見える範囲が「関係する人だけ」の業務モジュールで、この項目に入っている人(組織は所属する人)がレコードを表示できるようにします。',
    );
    await flag.click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '追加', exact: true }),
        MODULE_PATH,
    );
    await expect(
        page.getByText('項目を追加しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 足した項目を消し、見える範囲を全員に戻す。
 */
async function restore(page: Page) {
    await page.goto(MODULE_PATH);
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
    await chooseScope(page, '全員');
}

test('見える範囲を「関係する人だけ」にしてユーザーの項目に「関係する人」を付けると、保存の後もラジオの選択と項目の表の札「関係する人」が残る', async ({
    page,
}) => {
    await openAsAdministrator(page, MODULE_PATH);
    await chooseScope(page, '関係する人だけ');
    await addRelatedUserField(page);
    await expect(
        fieldRow(page).getByText('関係する人', { exact: true }),
    ).toBeVisible();

    // 開き直した編集の画面でも、ラジオは「関係する人だけ」のまま
    await page.goto(`${MODULE_PATH}/edit`);
    const scope = page.getByRole('radiogroup', {
        name: '見える範囲',
        exact: true,
    });
    await expect(
        scope.getByRole('radio', { name: '関係する人だけ', exact: true }),
    ).toBeChecked();
    await expect(
        scope.getByRole('radio', { name: '全員', exact: true }),
    ).not.toBeChecked();

    await restore(page);
    await expect(fieldRow(page)).toHaveCount(0);
    await page.goto(`${MODULE_PATH}/edit`);
    await expect(
        scope.getByRole('radio', { name: '全員', exact: true }),
    ).toBeChecked();
});
