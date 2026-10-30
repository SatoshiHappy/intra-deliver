import type { Locator, Page } from '@playwright/test';
import { expect } from './test';
import { submitAndExpectUrl } from './submit';

// 見出しの E2E(records-headings-admin・records-headings-show の spec)が共有する部品。業務モジュール「見出し確認」は最初の spec が作る。
// 管理者で開く部品 openAsAdministrator は、ほかの spec も使う。

/** 「見出し確認」の管理の定義の画面 */
export const HEADING_MODULE = '/records/admin/modules/heading_check';

/**
 * ゲストからシステム管理者に切り替えて、path を開く。
 */
export async function openAsAdministrator(page: Page, path: string) {
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
 * フォーム・詳細の見出しの行のボタン(名前で始まる。「<n> 項目」が続く)。
 */
export function headingButton(page: Page, name: string) {
    return page.getByRole('button', { name: new RegExp(`^${name}`) });
}

/**
 * 登録のフォームの欄(名前のテキストボックス)。
 */
export function textField(form: Locator, name: string) {
    return form.getByRole('textbox', { name, exact: true });
}
