import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';
import { todayInTokyo } from './seed-dates';

// 代理の画面の筋(zz-workflow-proxy-screens.spec.ts・zz-workflow-proxy-admin.spec.ts。docs の
// 設計/ワークフロー/案件の運用/画面/README.md の 9 節)が共有する、代理を追加するダイアログと表の行の削除の部品。

/** 代理を追加するダイアログの題 */
export const ADD_DIALOG = '代理を追加する';

/**
 * 今日から days 日後の日付(YYYY-MM-DD。入力欄の値)。
 */
export function inDays(days: number): string {
    return new Date(Date.parse(todayInTokyo()) + days * 86_400_000)
        .toISOString()
        .slice(0, 10);
}

/** 画面の日付の表示(YYYY/MM/DD) */
export const shown = (date: string) => date.replaceAll('-', '/');

/**
 * ダイアログの人を選ぶ欄(欄の名前の群)で、語を打って候補の人を選ぶ。候補の基準日(on)は入力中の開始日(既定 = 今日)で送られる。
 */
export async function pickUser(
    page: Page,
    dialog: Locator,
    label: string,
    name: string,
) {
    const field = dialog.getByRole('group', { name: label, exact: true });
    await field
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    const requested = page.waitForRequest(
        (request) =>
            request.url().includes('/workflow/user-candidates') &&
            request.url().includes(`on=${todayInTokyo()}`),
    );
    await page.getByPlaceholder('名前・コードで検索').fill(name.slice(0, 2));
    await requested;
    await page.getByRole('option', { name: new RegExp(name) }).click();
    await expect(
        field.getByRole('list', { name: `選んだ${label}` }),
    ).toContainText(name);
}

/**
 * ダイアログの「追加」を押して、元の画面へ戻りトースト「代理を追加しました」が出るのを待つ。
 */
export async function submitAdd(page: Page, dialog: Locator, url: RegExp) {
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '追加', exact: true }),
        url,
    );
    await expect(
        page.getByText('代理を追加しました', { exact: true }),
    ).toBeVisible();
}

/**
 * 表の行の「削除」を押し、確かめのダイアログ(題「代理の削除」)で削除して、トーストが出て行が消えるのを待つ。
 */
export async function deleteRow(page: Page, row: Locator) {
    await row.getByRole('button', { name: '削除', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: '代理の削除' });
    await expect(
        confirm.getByText(
            'この代理を削除しますか。削除した時から、代理先は代わりに処理できなくなります。',
            { exact: true },
        ),
    ).toBeVisible();
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '削除', exact: true }),
    );
    await expect(
        page.getByText('代理を削除しました', { exact: true }),
    ).toBeVisible();
    await expect(row).toHaveCount(0);
}
