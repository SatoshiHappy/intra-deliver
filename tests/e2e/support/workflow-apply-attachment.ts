import { open } from 'node:fs/promises';
import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { addedProjectStart } from './seed-dates';
import { submitAndExpectUrl } from './submit';
import { fillPlanApplication, openAs } from './workflow-operations';
import { APPLY_FORM, FLOW_CODE } from './workflow-record-apply';

// 申請の画面の添付の面の筋(zz-workflow-record-apply-2b・2c・2d の spec。docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「添付(申請)」
// 「要求の大きさ」)が使う手助け。

export const APPLY_URL = `/workflow/flows/${FLOW_CODE}/apply`;
export const MODULE_ADMIN_URL =
    '/records/admin/modules/project_plan_application';
export const ADMIN = '伊藤 涼子（経営企画室）';
export const APPLICANT_LABEL = '佐々木 陽太（東京営業課）';

export const DRAFT_BUTTON = { name: '一時保存', exact: true } as const;
export const APPLY_BUTTON = { name: '申請', exact: true } as const;
/** 一時保存を作った後の URL(一時保存の編集) */
export const DRAFT_URL = /\/workflow\/drafts\/\d+\/edit$/;

/** 申請の画面の入力のフォーム */
export function applyForm(page: Page): Locator {
    return page.getByRole('form', { name: APPLY_FORM });
}

/** 申請の画面の面「添付ファイル」 */
export function attachmentSurface(page: Page): Locator {
    return page.getByRole('region', { name: '添付ファイル', exact: true });
}

/** 面の中の 1 つの並び(小見出しの名前で引く)の行 */
export function rowsOf(surface: Locator, caption: string): Locator {
    return surface
        .getByRole('heading', { level: 3, name: caption, exact: true })
        .locator('xpath=..')
        .getByRole('listitem');
}

/** 小さなテキストのファイル(名前と中身。大きさは名前の長さ + 改行 1) */
export function textFile(name: string) {
    return { name, mimeType: 'text/plain', buffer: Buffer.from(`${name}\n`) };
}

/**
 * 中身を持たない大きさ bytes のファイル(ブラウザは大きさだけを見て、送る前の確かめで止まる。送るファイルではない)。
 */
export async function sparseFile(path: string, bytes: number) {
    const handle = await open(path, 'w');
    await handle.truncate(bytes);
    await handle.close();

    return path;
}

/** 面に渡した添付の上限(画面を開いた時の props) */
export type Limits = {
    maxFileBytes: number;
    maxRequestBytes: number;
    maxFiles: number;
    tooLarge: string;
};

/**
 * 開いた画面の props の添付の上限(送る前の確かめの上限は配備で替わるので、E2E は props から読んで大きさを決める)。
 */
export async function limitsOf(page: Page): Promise<Limits> {
    const json = await page
        .locator('script[data-page="app"][type="application/json"]')
        .textContent();
    const parsed = JSON.parse(json ?? '{}') as {
        props: { attachment: { limits: Limits } };
    };

    return parsed.props.attachment.limits;
}

/**
 * 利用者メニューを開き、項目を選ぶ。label は押す前の表示(氏名と、あればカレント組織)。
 */
export async function chooseUser(page: Page, label: string, item: string) {
    await page
        .getByRole('button', { name: `利用者メニュー：${label}`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: item }),
    );
}

/**
 * 明細「マイルストーン」に 1 行足して埋める(row = 1 から数える行の番号。done = 完了の欄)。
 */
export async function addMilestone(
    form: Locator,
    row: number,
    name: string,
    done: boolean,
) {
    await form.getByRole('button', { name: '行を追加', exact: true }).click();
    await form
        .getByRole('textbox', { name: `名称(${row} 行目)`, exact: true })
        .fill(name);
    await form
        .getByLabel(`期日(${row} 行目)`, { exact: true })
        .fill(addedProjectStart());

    if (done) {
        await form
            .getByRole('checkbox', { name: `完了(${row} 行目)`, exact: true })
            .check();
    }
}

/**
 * 申請書のレコード(プロジェクト名 name)の詳細を、業務データの一覧から開いて URL を返す。
 */
export async function openPlanRecord(page: Page, name: string) {
    await page.goto('/records/data/project_plan_application');
    await page
        .getByRole('region', { name: 'プロジェクト計画の申請の一覧' })
        .getByRole('link', { name, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name, exact: true }),
    ).toBeVisible();

    return page.url();
}

/**
 * 申請書のレコードの詳細のサイドパネル「添付ファイル」の名前(並びの順)。
 */
export async function recordAttachmentNames(page: Page, recordUrl: string) {
    await page.goto(`${recordUrl}?panel=attachments`);
    const panel = page.getByRole('region', {
        name: '添付ファイル',
        exact: true,
    });
    await expect(panel).toBeVisible();

    return panel.getByRole('listitem').locator('span.font-medium');
}

/** 面の中のファイルを選ぶ入力 */
export function fileInput(page: Page): Locator {
    return attachmentSurface(page).locator('input[type="file"]');
}

/** 面の中のボタン(「削除」などは「<ファイル名>を削除」の名前で引く) */
export function surfaceButton(page: Page, name: string): Locator {
    return attachmentSurface(page).getByRole('button', { name, exact: true });
}

/**
 * 申請者として新規の申請の画面を開き、計画の入力(名前 name・申請基準日)を埋めて、フォームを返す。
 */
export async function startApplication(page: Page, name: string) {
    await openAs(page, 'applicant', APPLY_URL);
    const form = applyForm(page);
    await fillPlanApplication(page, form, name);

    return form;
}

/**
 * 「一時保存」を押して一時保存の編集へ移り、その URL を返す。
 */
export async function saveDraft(page: Page, form: Locator) {
    await submitAndExpectUrl(
        page,
        form.getByRole('button', DRAFT_BUTTON),
        DRAFT_URL,
    );

    return page.url();
}

/**
 * 申請書のレコード(プロジェクト名 name)に付いた添付の名前が names(並びは問わない)であること。
 */
export async function expectRecordAttachments(
    page: Page,
    name: string,
    names: string[],
) {
    const recordUrl = await openPlanRecord(page, name);
    const shown = await recordAttachmentNames(page, recordUrl);
    await expect(shown).toHaveCount(names.length);
    expect((await shown.allTextContents()).sort()).toEqual([...names].sort());
}
