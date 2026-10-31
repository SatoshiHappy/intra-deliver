import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { textFile } from './workflow-apply-attachment';
import {
    addedRows,
    attachmentField,
    decideButton,
    dialogFileInput,
    processLimitsOf,
} from './workflow-process-attachment';

// 処理のダイアログの添付の欄の手順(zz-workflow-record-apply-4 の spec。docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節・9 節)。

/** ダイアログの今回追加するファイルの行の「<ファイル名>を削除」 */
function removeButton(dialog: Locator, name: string): Locator {
    return attachmentField(dialog).getByRole('button', {
        name: `${name}を削除`,
        exact: true,
    });
}

/**
 * 欄が出ていて(案内の 1 行は props の文)、2 つ選んで 1 つ選び直し、また 1 つ足して 2 つ(first.txt・third.txt)にする。
 */
export async function pickThenTrim(page: Page, dialog: Locator) {
    const { guide } = await processLimitsOf(page);
    await expect(
        attachmentField(dialog).getByText(guide, { exact: true }),
    ).toBeVisible();
    await dialogFileInput(dialog).setInputFiles(
        ['first.txt', 'second.txt'].map(textFile),
    );
    await expect(addedRows(dialog)).toHaveCount(2);
    await expect(addedRows(dialog).nth(0)).toContainText('first.txt');
    await expect(addedRows(dialog).nth(0)).toContainText('10 B');
    await removeButton(dialog, 'second.txt').click();
    await expect(addedRows(dialog)).toHaveCount(1);
    await dialogFileInput(dialog).setInputFiles(textFile('third.txt'));
    await expect(addedRows(dialog)).toHaveCount(2);
}

/**
 * 選んだファイルを全部、送る前の「削除」で外す。
 */
export async function removeChosen(dialog: Locator, names: string[]) {
    for (const name of names) {
        await removeButton(dialog, name).click();
    }

    await expect(addedRows(dialog)).toHaveCount(0);
}

/**
 * 4 つ選んで「決定」を押すと、送らずにトースト(文は props の tooLarge)が出て、ダイアログと選んだファイルが残ること。
 */
export async function expectBlockedBeforeSending(page: Page, dialog: Locator) {
    const { tooLarge } = await processLimitsOf(page);
    const names = ['a.txt', 'b.txt', 'c.txt', 'd.txt'];
    const posts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST') {
            posts.push(request.url());
        }
    });
    await dialogFileInput(dialog).setInputFiles(names.map(textFile));
    await expect(addedRows(dialog)).toHaveCount(4);
    await decideButton(dialog).click();
    const toast = page.getByText(tooLarge, { exact: true });
    await expect(toast).toBeVisible();
    await expect(toast).toHaveCount(0, { timeout: 15_000 });
    expect(posts).toEqual([]);
    await expect(dialog).toBeVisible();
    await expect(addedRows(dialog)).toHaveCount(4);
    await removeChosen(dialog, names);
}

/**
 * 承認の応答を 413 にして「決定」を押す: トースト・ダイアログと選んだファイルが残る・Inertia の既定のエラーの表示が出ない。
 */
export async function approveAnswered413(page: Page, dialog: Locator) {
    const { tooLarge } = await processLimitsOf(page);
    const url = page.url();
    let answered = 0;
    await page.route('**/workflow/cases/*/approve', async (route) => {
        if (route.request().method() !== 'POST') {
            await route.fallback();

            return;
        }

        answered += 1;
        await route.fulfill({ status: 413, body: '' });
    });
    await dialogFileInput(dialog).setInputFiles(textFile('keep.txt'));
    await decideButton(dialog).click();
    await expect(page.getByText(tooLarge, { exact: true })).toBeVisible();
    expect(answered).toBe(1);
    await expect(page).toHaveURL(url);
    await expect(dialog).toBeVisible();
    await expect(addedRows(dialog)).toHaveCount(1);
    await expect(addedRows(dialog).first()).toContainText('keep.txt');
    await expect(page.locator('dialog, iframe')).toHaveCount(0);
    await page.unroute('**/workflow/cases/*/approve');
    await removeChosen(dialog, ['keep.txt']);
}

/**
 * 形式の合わないファイル(bad.exe)を送ると 422 で、その行の下に誤りが出て、ダイアログとファイルが残ること。
 */
export async function expectRowError(dialog: Locator) {
    await dialogFileInput(dialog).setInputFiles({
        name: 'bad.exe',
        mimeType: 'application/octet-stream',
        buffer: Buffer.from('bad'),
    });
    await decideButton(dialog).click();
    await expect(addedRows(dialog).first().getByRole('alert')).toHaveText(
        'この形式のファイルは添付できません',
    );
    await expect(dialog).toBeVisible();
    await removeChosen(dialog, ['bad.exe']);
}
