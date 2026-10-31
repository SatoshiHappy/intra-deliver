import { join } from 'node:path';
import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';
import { submitApplication, switchUser } from './workflow-operations';
import { startApplication } from './workflow-apply-attachment';
import { APPLICANT, CHIEF } from './workflow-record-apply';

// 処理のダイアログの添付の欄の筋(zz-workflow-record-apply-4 の spec。docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節・9 節の
// 「添付(処理)」)が使う手助け。申請の画面の手助け(workflow-apply-attachment.ts)と同じ申請書・フロー project_plan を使う。

/** 処理のダイアログ(承認)の題 */
const APPROVE_TITLE = '承認する';

/** 案件の詳細の props の添付の上限(処理のダイアログの案内・送る前の確かめの文) */
export type ProcessLimits = {
    maxFiles: number;
    guide: string;
    tooLarge: string;
};

/**
 * 申請者として申請書を申請し、案件の詳細の URL を返す(申請者の利用者メニューのまま)。
 */
export async function applyCase(page: Page, name: string): Promise<string> {
    const form = await startApplication(page, name);
    await submitApplication(page, form);

    return page.url();
}

/**
 * 利用者を課長(中村 美咲。申請者の次の承認)に替えて、案件の詳細の「承認」で処理のダイアログを開く。
 */
export async function openApproveAsChief(
    page: Page,
    caseUrl: string,
): Promise<Locator> {
    await switchUser(page, APPLICANT, 'chief');
    await page.goto(caseUrl);
    await page
        .getByRole('main')
        .getByRole('button', { name: '承認', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: APPROVE_TITLE });
    await expect(dialog).toBeVisible();

    return dialog;
}

/**
 * 利用者を申請者に戻す(課長として開いていた画面から)。
 */
export async function backToApplicant(page: Page) {
    await switchUser(page, CHIEF, 'applicant');
}

/** ダイアログの面「添付ファイル」 */
export function attachmentField(dialog: Locator): Locator {
    return dialog.getByRole('group', { name: '添付ファイル', exact: true });
}

/** ダイアログの中のファイルを選ぶ入力 */
export function dialogFileInput(dialog: Locator): Locator {
    return attachmentField(dialog).locator('input[type="file"]');
}

/** 今回追加するファイルの行 */
export function addedRows(dialog: Locator): Locator {
    return attachmentField(dialog)
        .getByRole('heading', {
            level: 3,
            name: '今回追加するファイル',
            exact: true,
        })
        .locator('xpath=..')
        .getByRole('listitem');
}

/**
 * 開いた案件の詳細の props の添付の上限(送る前の確かめの上限は配備で替わるので、E2E は props から読む)。
 */
export async function processLimitsOf(page: Page): Promise<ProcessLimits> {
    const json = await page
        .locator('script[data-page="app"][type="application/json"]')
        .textContent();
    const parsed = JSON.parse(json ?? '{}') as {
        props: { attachmentLimits: ProcessLimits | null };
    };

    const limits = parsed.props.attachmentLimits;

    if (limits === null) {
        throw new Error('案件の詳細の props に添付の上限が無い');
    }

    return limits;
}

/** 「決定」(ダイアログの下のボタン) */
export function decideButton(dialog: Locator): Locator {
    return dialog.getByRole('button', { name: '決定', exact: true });
}

/**
 * 「決定」を押して、同じ詳細へ戻る送信を待つ。
 */
export async function decide(page: Page, dialog: Locator, caseUrl: string) {
    await submitAndExpectUrl(page, decideButton(dialog), caseUrl);
}

/**
 * 目視用の画面の写し(環境変数 E2E_SHOT_DIR を渡したときだけ撮る)。
 */
export async function shoot(page: Page, name: string) {
    const dir = process.env.E2E_SHOT_DIR;

    if (dir !== undefined && dir !== '') {
        await page.screenshot({ path: join(dir, `${name}.png`) });
    }
}
