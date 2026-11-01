import { expect } from './test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './submit';

// フロー定義の詳細のタブ「設定」の面「添付ファイル」の筋(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節・9 節の「添付(申請)」)が
// 共有する、面の部品と、設定を戻す文。使うのは zz-workflow-admin.attachment と zz-workflow-record-apply-2e の spec。

/** フロー「プロジェクト計画の承認」の詳細 */
export const PLAN_ADMIN_URL = '/workflow/admin/flows/project_plan';

/** 筋の終わりに添付の使用を戻す文(画面で替えた分。画面で戻せなかったときの備え) */
export const RESTORE_FLOW_ATTACHMENT = [
    'DB::table("workflow_flows")->where("code", "project_plan")',
    '->update(["is_attachment_enabled" => true]);',
].join('');

/** タブ「設定」の面「添付ファイル」 */
export function flowAttachmentSurface(page: Page): Locator {
    return page.getByRole('form', { name: '添付ファイル', exact: true });
}

/** 面のラジオ(「使用する」「使用しない」) */
export function useRadio(page: Page, label: '使用する' | '使用しない') {
    return flowAttachmentSurface(page).getByRole('radio', {
        name: label,
        exact: true,
    });
}

/**
 * システム管理者として、タブ「設定」の面「添付ファイル」で使用するかを選んで保存し、トーストと保存後の選択を確かめる。
 */
export async function saveFlowAttachment(page: Page, isUsed: boolean) {
    await page.goto(`${PLAN_ADMIN_URL}?tab=settings`);
    const label = isUsed ? '使用する' : '使用しない';
    await useRadio(page, label).click();
    await submitAndExpectUrl(
        page,
        flowAttachmentSurface(page).getByRole('button', {
            name: '保存',
            exact: true,
        }),
        /tab=settings/,
    );
    await expect(
        page.getByText('添付ファイルの設定を保存しました', { exact: true }),
    ).toBeVisible();
    await expect(useRadio(page, label)).toBeChecked();
}
