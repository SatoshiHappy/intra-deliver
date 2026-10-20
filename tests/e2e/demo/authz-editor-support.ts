import { expect } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 認可設定の編集の E2E(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 8 節、画面.md 9 節)の共通の部品。利用者の切替を使うので持ち物の中に置く。

export type Person = { role: string; label: string };

/** 切替の候補のうち、システム管理者(u0004)と申請者(u0001) */
export const ADMINISTRATOR: Person = {
    role: 'システム管理者',
    label: '伊藤 涼子（経営企画室）',
};
export const APPLICANT: Person = {
    role: '申請者',
    label: '佐々木 陽太（東京営業課）',
};

/** 初期データが作る組み込みの 3 列(認可設定の編集.md 5.2)の見出し */
export const BUILTIN_COLUMNS = [
    'ゲストユーザ',
    '認証済みユーザ',
    'システム管理者',
];

export const toasts = (page: Page) => page.locator('[data-sonner-toast]');

function userMenu(page: Page, label: string) {
    return page.getByRole('button', {
        name: `利用者メニュー：${label}`,
        exact: true,
    });
}

/**
 * 利用者メニューから user に切り替える(from = 切り替える前の表示)。
 */
export async function switchTo(page: Page, from: string, user: Person) {
    await userMenu(page, from).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('menu')
            .getByRole('menuitem')
            .filter({ hasText: user.role }),
    );
    await expect(userMenu(page, user.label)).toBeVisible();
}

/**
 * ゲストで始めて user に切り替える(切り替えた後のホームにいる)。
 */
export async function signInAs(page: Page, user: Person) {
    await page.goto('/');
    await switchTo(page, 'ゲスト', user);
}

/**
 * user に切り替えて、認可設定の画面を開く。
 */
export async function openOverviewAs(page: Page, user: Person) {
    await signInAs(page, user);
    await page.goto('/authz/overview');
}

export const startButton = (page: Page) =>
    page.getByRole('button', { name: '権限設定を開始する', exact: true });

export const endButton = (page: Page) =>
    page.getByRole('button', { name: '権限設定を終了する', exact: true });

export const addColumnButton = (page: Page) =>
    page.getByRole('button', { name: '列を追加', exact: true });

/** 押せる形のセルのボタン(表の本体のセルの中。行の開閉のボタンは行見出しの中) */
export const cellButtons = (page: Page) => page.locator('tbody td button');

export async function startEditing(page: Page) {
    await startButton(page).click();
    await expect(endButton(page)).toBeVisible();
}

/**
 * 格子の、名前で引いた行(先頭の列は th scope="row")。
 */
export function resourceRow(page: Page, name: string) {
    return page.locator('tbody tr').filter({
        has: page.getByRole('rowheader', { name, exact: true }),
    });
}

/**
 * 格子の、メニューグループの種類の「管理」の行。「管理」は 2 つある(グローバルナビ・サイトマップ)ので、
 * 親の行「グローバルナビ（PC用）」より後で最初の行。
 */
export function menuAdminRow(page: Page) {
    return page
        .getByRole('rowheader', { name: 'グローバルナビ（PC用）', exact: true })
        .locator('xpath=ancestor::tr[1]/following-sibling::tr')
        .filter({
            has: page.getByRole('rowheader', { name: '管理', exact: true }),
        })
        .first();
}

/**
 * 行の、subject の列の action 番目(0 始まり)のセル。列の位置は見出しの 2 行目(対象者の行)の幅から数える。
 */
export async function subjectCell(
    page: Page,
    row: Locator,
    subject: string,
    action = 0,
) {
    const index = await page
        .locator('thead tr')
        .nth(1)
        .locator('th')
        .evaluateAll((heads, name) => {
            let position = 0;

            for (const head of heads) {
                if ((head.textContent ?? '').includes(name)) {
                    return position;
                }

                position += (head as HTMLTableCellElement).colSpan;
            }

            return -1;
        }, subject);

    expect(index, `見出しに列「${subject}」が無い`).toBeGreaterThanOrEqual(0);

    return row.locator('td').nth(index + action);
}

/**
 * セルを 1 回押し、保存の応答(再読み込みまで)を待つ。
 */
export async function press(page: Page, cell: Locator) {
    await submitAndExpectUrl(page, cell.getByRole('button'));
}

/**
 * リソースの種類を選ぶ(編集中のまま部分再読み込みされる)。
 */
export async function chooseType(page: Page, label: string) {
    const type = page.getByRole('combobox', { name: 'リソースの種類' });
    await type.click();
    await page.getByRole('option', { name: label, exact: true }).click();
    await expect(type).toHaveText(label);
}

/**
 * 「列を追加」で、種類ユーザーの候補を語で探して 1 人選び、「追加」を押す。dialog を返す(誤りのときは開いたまま)。
 */
export async function addUserColumn(page: Page, word: string, name: string) {
    await addColumnButton(page).click();
    const dialog = page.getByRole('dialog', { name: '列を追加', exact: true });
    await dialog
        .getByRole('group', { name: 'ユーザー', exact: true })
        .getByRole('combobox')
        .click();
    await page.getByPlaceholder('検索する語').fill(word);
    await page.getByRole('option', { name }).click();
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '追加', exact: true }),
    );

    return dialog;
}

/**
 * 列の見出しの「…」から「列を削除」を開き、確かめのダイアログを返す。
 */
export async function openDeleteDialog(page: Page, name: string) {
    await page.getByRole('button', { name: `${name}の列の操作` }).click();
    await page.getByRole('menuitem', { name: '列を削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '列の削除', exact: true });
    await expect(dialog).toBeVisible();

    return dialog;
}

/**
 * 列を削除する(確かめのダイアログで「削除」)。
 */
export async function deleteColumn(page: Page, name: string) {
    const dialog = await openDeleteDialog(page, name);
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
    );
    await expect(dialog).toBeHidden();
    await expect(page.getByRole('columnheader', { name })).toHaveCount(0);
}
