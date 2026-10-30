import type { Locator, Page } from '@playwright/test';
import { expect } from './test';
import { submitAndExpectUrl } from './submit';

// 複数選べる参照の E2E(records-multiple.spec.ts)の手助け。業務モジュール「備品」(コード equipment)は records-admin.spec.ts の
// 筋が作る。型「ユーザーと組織(複数)」の筋の段(docs の 設計/業務データの入れ物/画面/README.md 6 節の型「ユーザーと組織」の行)を
// 名前つきの手助けにして、spec のテストの関数を短く保つ。

/** 型「ユーザーと組織(複数)」の項目(一覧に表示・検索条件にする)と、2 件のレコードの品名 */
const PARTY_FIELD = { code: 'parties_in_charge', name: '関係先' };
const BOTH_ITEM = 'E2E のユーザーと組織(両方)';
export const USER_ONLY_ITEM = 'E2E のユーザーと組織(人だけ)';

/**
 * 管理の画面で、型「ユーザーと組織(複数)」の項目(一覧に表示。検索条件にするは追加のときの初期値のオン)を足す。
 */
export async function addPartyField(page: Page) {
    await page.getByRole('link', { name: '項目を追加', exact: true }).click();
    const form = page.getByRole('form', { name: '項目の入力' });
    await form
        .getByRole('textbox', { name: 'コード', exact: true })
        .fill(PARTY_FIELD.code);
    await form
        .getByRole('textbox', { name: '名前', exact: true })
        .fill(PARTY_FIELD.name);
    await form.getByRole('combobox', { name: '型', exact: true }).click();
    await page
        .getByRole('option', { name: 'ユーザーと組織(複数)', exact: true })
        .click();
    await form
        .getByRole('checkbox', { name: '一覧に表示', exact: true })
        .click();
    await expect(
        form.getByRole('checkbox', { name: '検索条件にする', exact: true }),
    ).toBeChecked();
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
 * 開いた候補の一覧で、群 group の候補 name を語 query で探して選ぶ(選ぶと閉じる)。
 */
async function pickParty(
    page: Page,
    combobox: Locator,
    query: string,
    group: 'ユーザー' | '組織',
    name: string,
) {
    await combobox.click();
    await page.getByPlaceholder('検索する語').fill(query);
    await page
        .getByRole('group', { name: group, exact: true })
        .getByRole('option', { name, exact: true })
        .click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
}

/**
 * 登録の画面で、品名と型「ユーザーと組織(複数)」の項目の相手を入れて登録し、詳細の URL を返す。
 */
export async function createWithParties(
    page: Page,
    itemName: string,
    picks: [string, 'ユーザー' | '組織', string][],
) {
    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: /の入力$/ });
    await form
        .getByRole('textbox', { name: '品名', exact: true })
        .fill(itemName);
    const combobox = form
        .getByRole('group', { name: PARTY_FIELD.name, exact: true })
        .getByRole('combobox');
    for (const [query, group, name] of picks) {
        await pickParty(page, combobox, query, group, name);
    }
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();

    return page.url();
}

/**
 * 詳細の URL のレコードを消す。
 */
export async function removeRecord(page: Page, detailUrl: string) {
    await page.goto(detailUrl);
    await page.getByRole('button', { name: '削除', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: 'レコードの削除' })
            .getByRole('button', { name: '削除', exact: true }),
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
}

/**
 * 開いた候補の一覧が群「ユーザー」「組織」の見出しつきで、語が組織だけに当たっても群の見出しを出す(0 件の群「ユーザー」は出さない)。
 * 途中で「高橋 誠」を選ぶ(選び済みの除外を種類と id で比べることは、画面の単体テストの unpickedOptions が確かめる)。
 */
async function expectPartyCandidateGroups(page: Page, combobox: Locator) {
    await combobox.click();
    await expect(
        page.getByRole('group', { name: 'ユーザー', exact: true }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('group', { name: '組織', exact: true })
            .getByRole('option')
            .first(),
    ).toBeVisible();
    await page.keyboard.press('Escape');
    await pickParty(page, combobox, '高橋', 'ユーザー', '高橋 誠');
    await combobox.click();
    await page.getByPlaceholder('検索する語').fill('開発部');
    await expect(
        page.getByRole('group', { name: '組織', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('group', { name: 'ユーザー', exact: true }),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');
}

/**
 * 登録のフォームで、候補の群を確かめながら人 2 人と組織 1 つを選び(チップが 3 つ並び、組織のチップに種類の印)、BOTH_ITEM を登録して
 * 詳細の URL を返す。
 */
export async function createBothItem(page: Page) {
    await page.goto('/records/data/equipment/create');
    const form = page.getByRole('form', { name: /の入力$/ });
    await form
        .getByRole('textbox', { name: '品名', exact: true })
        .fill(BOTH_ITEM);
    const field = form.getByRole('group', {
        name: PARTY_FIELD.name,
        exact: true,
    });
    const combobox = field.getByRole('combobox');
    await expectPartyCandidateGroups(page, combobox);
    await pickParty(page, combobox, '中村', 'ユーザー', '中村 美咲');
    await pickParty(page, combobox, '開発部', '組織', '開発部');
    const chips = field
        .getByRole('list', { name: `${PARTY_FIELD.name}の選択` })
        .getByRole('listitem');
    await expect(chips).toHaveCount(3);
    await expect(
        chips.nth(2).getByRole('img', { name: '組織', exact: true }),
    ).toBeVisible();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();

    return page.url();
}

/**
 * 開いている BOTH_ITEM の詳細で、相手ごとに種類の印(ユーザー 2・組織 1)が付く。
 */
export async function expectPartyMarksInDetail(page: Page) {
    const values = page.getByRole('region', { name: `${BOTH_ITEM}の値` });
    await expect(values).toContainText('開発部');
    await expect(
        values.getByRole('img', { name: 'ユーザー', exact: true }),
    ).toHaveCount(2);
    await expect(
        values.getByRole('img', { name: '組織', exact: true }),
    ).toHaveCount(1);
}

/**
 * 一覧の行(品名のリンクで引く)。
 */
function partyRow(page: Page, name: string) {
    return page
        .getByRole('region', { name: /の一覧$/ })
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name, exact: true }) });
}

/**
 * 一覧のセルは先頭の 2 つ(種類の印つき)と「ほか 1 件」(人の後に組織が残っても「件」)。
 */
export async function expectPartyListCell(page: Page) {
    await page.goto('/records/data/equipment');
    await expect(partyRow(page, BOTH_ITEM)).toContainText('ほか 1 件');
    await expect(
        partyRow(page, BOTH_ITEM).getByRole('img', {
            name: 'ユーザー',
            exact: true,
        }),
    ).toHaveCount(2);
}

/**
 * 一覧の検索欄でユーザー 1 人と組織 1 つを選ぶと、両方を値に持つレコードだけが出る(クエリの配列は添字の形)。検索の後も欄に 2 つの
 * チップが残る。
 */
export async function searchByUserAndDepartment(page: Page) {
    await page.getByRole('button', { name: '検索条件', exact: true }).click();
    const search = page.getByRole('search', { name: 'レコードの検索' });
    const filter = search.getByRole('combobox', {
        name: PARTY_FIELD.name,
        exact: true,
    });
    await pickParty(page, filter, '高橋', 'ユーザー', '高橋 誠');
    await pickParty(page, filter, '開発部', '組織', '開発部');
    await page.getByRole('button', { name: '検索', exact: true }).click();
    await expect(page).toHaveURL(
        /filter%5Bparties_in_charge%5D%5B0%5D=user%3A\d+&filter%5Bparties_in_charge%5D%5B1%5D=department%3A\d+/,
    );
    await expect(partyRow(page, BOTH_ITEM)).toBeVisible();
    await expect(partyRow(page, USER_ONLY_ITEM)).toHaveCount(0);
    await expect(
        search
            .getByRole('list', { name: `${PARTY_FIELD.name}の選択` })
            .getByRole('listitem'),
    ).toHaveCount(2);
}

/**
 * 管理の画面で、足した型「ユーザーと組織(複数)」の項目を消す。
 */
export async function removePartyField(page: Page) {
    await page.goto('/records/admin/modules/equipment');
    await page
        .getByRole('button', { name: `${PARTY_FIELD.name}を削除`, exact: true })
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
