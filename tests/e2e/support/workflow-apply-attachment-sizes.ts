import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import {
    APPLY_BUTTON,
    APPLY_URL,
    DRAFT_BUTTON,
    attachmentSurface,
    fileInput,
    limitsOf,
    rowsOf,
    sparseFile,
    surfaceButton,
} from './workflow-apply-attachment';

// 申請の画面の添付の筋のうち、要求の大きさ(zz-workflow-record-apply-2d の spec。docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の
// 「要求の大きさ」)の手順。

/**
 * 選んだファイルを送ろうとして、送らずにトースト(文は props の tooLarge)が出ること(トーストが消えるまで待つ)。
 */
export async function expectBlockedBeforeSending(page: Page, form: Locator) {
    const { tooLarge } = await limitsOf(page);
    const toast = page.getByText(tooLarge, { exact: true });
    await form.getByRole('button', DRAFT_BUTTON).click();
    await expect(toast).toBeVisible();
    await expect(toast).toHaveCount(0, { timeout: 15_000 });
}

/**
 * 選んだファイルを全部、送る前の「削除」で外す。
 */
export async function removeChosen(page: Page, names: string[]) {
    for (const name of names) {
        await surfaceButton(page, `${name}を削除`).click();
    }

    await expect(
        rowsOf(attachmentSurface(page), '今回追加するファイル'),
    ).toHaveCount(0);
}

/**
 * 1 ファイルの上限より 1 バイト大きいファイルを選ぶ(中身の無いファイル。送らない)。
 */
export async function chooseOversized(page: Page, path: string) {
    const limits = await limitsOf(page);
    await fileInput(page).setInputFiles(
        await sparseFile(path, limits.maxFileBytes + 1),
    );
    await expect(
        rowsOf(attachmentSurface(page), '今回追加するファイル'),
    ).toHaveCount(1);
}

/**
 * 申請の応答を 413 にして申請する: トースト・入力と選んだファイルが残る・Inertia の既定のエラーの表示が出ない。
 */
export async function applyAnswered413(
    page: Page,
    form: Locator,
    name: string,
) {
    const { tooLarge } = await limitsOf(page);
    const url = page.url();
    let answered = 0;
    await page.route(`**${APPLY_URL}`, async (route) => {
        if (route.request().method() !== 'POST') {
            await route.fallback();

            return;
        }

        answered += 1;
        await route.fulfill({ status: 413, body: '' });
    });
    await form.getByRole('button', APPLY_BUTTON).click();
    await page
        .getByRole('dialog', { name: '申請しますか？' })
        .getByRole('button', { name: '決定', exact: true })
        .click();
    await expect(page.getByText(tooLarge, { exact: true })).toBeVisible();
    expect(answered).toBe(1);
    await expect(page).toHaveURL(url);
    await expect(
        form.getByRole('textbox', { name: 'プロジェクト名', exact: true }),
    ).toHaveValue(name);
    const added = rowsOf(attachmentSurface(page), '今回追加するファイル');
    await expect(added).toHaveCount(1);
    await expect(added.first()).toContainText('ok.txt');
    await expect(page.locator('dialog, iframe')).toHaveCount(0);
    await page.unroute(`**${APPLY_URL}`);
}
