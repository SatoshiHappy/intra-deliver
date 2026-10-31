import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { todayInTokyo } from './seed-dates';
import { submitAndExpectUrl } from './submit';

// 定義の管理の画面の筋(zz-workflow-admin.spec.ts。docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 10 節)が共有する、
// 詳細のタブ・表の行・版を追加するダイアログ・確かめのダイアログの部品。

/** 日付(YYYY-MM-DD)の n 日後 */
export const plusDays = (date: string, days: number) =>
    new Date(Date.parse(date) + days * 86_400_000).toISOString().slice(0, 10);

/** 詳細のタブのリンク(今のタブは aria-current) */
export const tab = (page: Page, name: string) =>
    page
        .getByRole('navigation', { name: 'フロー定義のタブ' })
        .getByRole('link', { name, exact: true });

/** 表の本文の行 */
export const bodyRows = (table: Locator, page: Page) =>
    table.getByRole('row').filter({ has: page.getByRole('cell') });

/** 版のタブの表の本文の行 */
export const versionRows = (page: Page) =>
    bodyRows(page.getByRole('table', { name: '版の一覧' }), page);

/**
 * 「版を追加」を開き、開始日の min(今日と最後の版の開始日の翌日の遅い方)・既定(= min)・複製元の版を確かめて、開始日の欄を返す。
 */
export async function openAddDialog(
    page: Page,
    dialog: Locator,
): Promise<Locator> {
    const lastStart =
        (await versionRows(page)
            .filter({ hasText: '版 3' })
            .getByRole('cell')
            .nth(1)
            .textContent()) ?? '';
    const dayAfter = plusDays(lastStart, 1);
    const min = dayAfter > todayInTokyo() ? dayAfter : todayInTokyo();
    await page.getByRole('button', { name: '版を追加', exact: true }).click();
    await expect(dialog).toContainText(
        'プロジェクト計画の承認に新しい版を追加します。',
    );
    const start = dialog
        .getByRole('group', { name: '開始日', exact: true })
        .locator('input');
    await expect(start).toHaveAttribute('min', min);
    await expect(start).toHaveValue(min);
    await expect(
        dialog.getByRole('textbox', { name: '複製元の版' }),
    ).toHaveValue('版 3');

    return start;
}

/**
 * 確かめのダイアログ(題)で「決定」を押し、トーストが出るのを待つ。
 */
export async function confirmToggle(page: Page, title: string, toast: string) {
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: title })
            .getByRole('button', { name: '決定', exact: true }),
    );
    await expect(page.getByText(toast, { exact: true })).toBeVisible();
}
