import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';
import { submitApplication } from './workflow-operations';
import {
    ADMIN,
    APPLICANT_LABEL,
    APPLY_URL,
    DRAFT_BUTTON,
    MODULE_ADMIN_URL,
    applyForm,
    attachmentSurface,
    chooseUser,
    fileInput,
    openPlanRecord,
    recordAttachmentNames,
    rowsOf,
    surfaceButton,
    textFile,
} from './workflow-apply-attachment';
import { FLOW_NAME } from './workflow-record-apply';

// 申請の画面の添付の筋(zz-workflow-record-apply-2b・2c・2d の spec)の手順。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節。

/** 申請しました のトーストまで待つ */
export async function expectApplied(page: Page) {
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
}

/**
 * 3 つ選び、送る前に 1 つ選び直して 2 つ(first.txt・second.txt)にする。
 */
export async function pickThenTrim(page: Page) {
    const added = rowsOf(attachmentSurface(page), '今回追加するファイル');
    await fileInput(page).setInputFiles(
        ['first.txt', 'second.txt', 'third.txt'].map(textFile),
    );
    await expect(added).toHaveCount(3);
    await expect(added.nth(0)).toContainText('first.txt');
    await expect(added.nth(0)).toContainText('10 B');
    await surfaceButton(page, 'third.txtを削除').click();
    await expect(added).toHaveCount(2);
}

/**
 * 面の一時保存の添付が names だけで、選んだファイルが無いこと。
 */
export async function expectDrafts(page: Page, names: string[]) {
    const surface = attachmentSurface(page);
    const drafts = rowsOf(surface, '一時保存の添付');
    await expect(drafts).toHaveCount(names.length);
    await expect(rowsOf(surface, '今回追加するファイル')).toHaveCount(0);

    for (const name of names) {
        await expect(drafts.filter({ hasText: name })).toHaveCount(1);
    }
}

/**
 * 開き直した一時保存で、first.txt に外す印を付け・元に戻し・また付け(取り消し線と「元に戻す」)、fourth.txt を足して上書きする。
 */
export async function reviseDraft(page: Page, form: Locator, draftUrl: string) {
    const drafts = rowsOf(attachmentSurface(page), '一時保存の添付');
    const struck = drafts
        .filter({ hasText: 'first.txt' })
        .locator('.line-through');
    await page.goto(draftUrl);
    await expect(drafts).toHaveCount(2);
    await surfaceButton(page, 'first.txtを削除').click();
    await expect(struck).toHaveCount(1);
    await surfaceButton(page, 'first.txtを元に戻す').click();
    await expect(struck).toHaveCount(0);
    await surfaceButton(page, 'first.txtを削除').click();
    await expect(struck).toHaveCount(1);
    await fileInput(page).setInputFiles(textFile('fourth.txt'));
    await expect(
        rowsOf(attachmentSurface(page), '今回追加するファイル'),
    ).toHaveCount(1);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', DRAFT_BUTTON),
        draftUrl,
    );
}

/**
 * システム管理者に切り替えて、業務モジュール「プロジェクト計画の申請」の「添付ファイルの追加・削除を許可」を外し、申請者に戻る。
 */
export async function disableModuleAttachment(page: Page) {
    await chooseUser(page, APPLICANT_LABEL, 'システム管理者');
    await page.goto(`${MODULE_ADMIN_URL}/edit`);
    const moduleForm = page.getByRole('form', { name: '業務モジュールの入力' });
    const allowed = moduleForm.getByRole('checkbox', {
        name: '添付ファイルの追加・削除を許可',
        exact: true,
    });
    await expect(allowed).toBeChecked();
    await allowed.click();
    await expect(allowed).not.toBeChecked();
    await submitAndExpectUrl(
        page,
        moduleForm.getByRole('button', { name: '更新', exact: true }),
        MODULE_ADMIN_URL,
    );
    await chooseUser(page, ADMIN, '申請者');
}

/**
 * 許可しない業務モジュールで、新規の申請の画面に面が無く、一時保存の添付が残る画面は行と 1 行だけで、外す印を付けると申請できる。
 */
export async function expectDisabledScreens(page: Page, draftUrl: string) {
    await page.goto(APPLY_URL);
    await expect(applyForm(page)).toBeVisible();
    await expect(attachmentSurface(page)).toHaveCount(0);
    await expect(surfaceButton(page, 'ファイルを選択')).toHaveCount(0);

    await page.goto(draftUrl);
    const surface = attachmentSurface(page);
    await expect(rowsOf(surface, '一時保存の添付')).toHaveCount(1);
    await expect(surface).toContainText(
        'この申請では添付ファイルを使用できません。申請する前に削除してください。',
    );
    await expect(surfaceButton(page, 'ファイルを選択')).toHaveCount(0);
    await surfaceButton(page, 'keep.txtを削除').click();
    await submitApplication(page, applyForm(page));
    await expectApplied(page);
}

/**
 * 申請書のレコードの詳細で、参照・数・明細の行(完了の真偽を含む)が値の型のまま入っていること(明細は 2 行: 完了 = はい・いいえ)。
 */
export async function expectTypedValues(page: Page, name: string) {
    const values = page.getByRole('region', { name: `${name}の値` });
    await expect(values).toContainText('東京営業課');
    await expect(values).toContainText('中村 美咲');
    await expect(values).toContainText('100');
    await expect(
        values.getByRole('button', { name: /^マイルストーン\s*2 件$/ }),
    ).toHaveAttribute('aria-expanded', 'true');
    const rows = values
        .getByRole('region', { name: 'マイルストーン', exact: true })
        .getByRole('row');
    await expect(rows).toHaveCount(3);
    await expect(rows.nth(1)).toContainText('E2E の節目 1');
    await expect(rows.nth(1)).toContainText('はい');
    await expect(rows.nth(2)).toContainText('E2E の節目 2');
    await expect(rows.nth(2)).toContainText('いいえ');
}

/**
 * 開き直した一時保存の明細の行を全部消し、添付を足して申請する(空の配列が届かないと行が残る)。
 */
export async function clearDetailsAndApply(
    page: Page,
    form: Locator,
    url: string,
) {
    await page.goto(url);
    await expect(
        form.getByRole('textbox', { name: '名称(2 行目)', exact: true }),
    ).toHaveValue('E2E の節目 4');

    for (const row of [2, 1]) {
        await form
            .getByRole('button', { name: `行を削除(${row} 行目)`, exact: true })
            .click();
    }

    await fileInput(page).setInputFiles(textFile('empty-details.txt'));
    await submitApplication(page, form);
    await expectApplied(page);
}

/**
 * 明細の行が全部消えた申請書のレコードに、添付 attached だけが付いていること。
 */
export async function expectNoDetails(
    page: Page,
    name: string,
    attached: string,
) {
    const recordUrl = await openPlanRecord(page, name);
    const values = page.getByRole('region', { name: `${name}の値` });
    await expect(
        values.getByRole('button', { name: /^マイルストーン\s*0 件$/ }),
    ).toBeVisible();
    await expect(
        values.getByText('明細はありません', { exact: true }),
    ).toBeVisible();
    await expect(values).not.toContainText('E2E の節目');
    await expect(await recordAttachmentNames(page, recordUrl)).toHaveText([
        attached,
    ]);
}

/**
 * 申請書のレコードの詳細の添付のパネルで、attached-1.txt・attached-2.txt を付ける。
 */
export async function attachToRecord(page: Page, recordUrl: string) {
    await page.goto(`${recordUrl}?panel=attachments`);
    const panel = page.getByRole('region', {
        name: '添付ファイル',
        exact: true,
    });
    await panel
        .locator('input[type="file"]')
        .setInputFiles(['attached-1.txt', 'attached-2.txt'].map(textFile));
    await expect(
        panel.getByRole('listitem').locator('span.font-medium'),
    ).toHaveCount(2, { timeout: 15_000 });
}

/**
 * 詳細の「申請する」→ フローを選ぶダイアログ(入口が 2 つ)→ 申請の画面(既存のデータ)。
 */
export async function openApplyFromRecord(page: Page, recordUrl: string) {
    await page.goto(recordUrl);
    await page
        .getByRole('main')
        .getByRole('button', { name: '申請する', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '申請するフローを選択' });
    await dialog
        .getByRole('radio', { name: new RegExp(`^${FLOW_NAME}`) })
        .click();
    await dialog.getByRole('button', { name: '選択', exact: true }).click();
    await expect(page).toHaveURL(/\/apply\?record=\d+$/);
}

/**
 * 付いている添付が名前と大きさ(15 B)だけで 2 行出て、「削除」を出さず、「ファイルを選択」は使える。
 */
export async function expectAttachedRows(page: Page) {
    const surface = attachmentSurface(page);
    const attached = rowsOf(surface, '付いている添付');
    await expect(attached).toHaveCount(2);
    const texts = await attached.allTextContents();
    expect(texts.join('\n')).toMatch(/attached-\d\.txt.*15 B/);
    await expect(attached.getByRole('button')).toHaveCount(0);
    await expect(rowsOf(surface, '今回追加するファイル')).toHaveCount(0);
    await expect(surfaceButton(page, 'ファイルを選択')).toBeEnabled();
}
