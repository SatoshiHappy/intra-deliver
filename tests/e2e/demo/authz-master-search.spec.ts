import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { pickUser } from '../support/workflow-proxies';
import {
    ADMINISTRATOR,
    APPLICANT,
    openOverviewAs,
    press,
    resourceRow,
    startEditing,
    subjectCell,
    switchTo,
} from './authz-editor-support';

// 組織図を許されない人の見出しの検索欄(docs の 設計/共通マスタ/検索と組織図.md 3.2 と 5 節の E2E の最後の項): システム管理者に切り替えて
// 束「共通マスタ」の認証済みユーザの許可を禁止にすると、申請者(u0001)の見出しに検索欄が無く、業務の画面(代理の設定)の人を選ぶ欄では
// 候補が出る。ゲストだけでは作れないので demo の持ち物に置く。筋の最後に認証済みユーザの許可へ戻す(同じ組のほかの spec の前提を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

const AUTHENTICATED = '認証済みユーザ';

const headerSearch = (page: Page) =>
    page.getByRole('combobox', { name: 'ユーザー・組織を検索' });

async function commonMasterCell(page: Page) {
    return subjectCell(page, resourceRow(page, '共通マスタ'), AUTHENTICATED);
}

test('束「共通マスタ」を禁止にした人には見出しの検索欄が無く、業務の画面の人を選ぶ欄では候補が出る', async ({
    page,
}) => {
    // 利用者の切替を 3 回含む一本道で、負荷のある回に既定の 60 秒を超えるため
    test.slow();
    await openOverviewAs(page, ADMINISTRATOR);
    await expect(headerSearch(page)).toBeVisible();
    await startEditing(page);
    const cell = await commonMasterCell(page);
    await expect(cell).toHaveText('許可');
    await press(page, cell);
    await expect(cell).toHaveText('禁止');

    await switchTo(page, ADMINISTRATOR.label, APPLICANT);
    await expect(headerSearch(page)).toHaveCount(0);

    // 業務の画面の人を選ぶ欄(代理の設定の「代理を追加」)は、組織図の許可によらず候補を引ける。追加はしない
    await page.goto('/workflow/proxies');
    await page.getByRole('button', { name: '代理を追加', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: '代理を追加する' });
    await pickUser(page, dialog, '代理先', '高橋 誠');
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toBeHidden();
    await expect(headerSearch(page)).toHaveCount(0);

    // 元に戻す(禁止 → 未設定 → 許可)
    await switchTo(page, APPLICANT.label, ADMINISTRATOR);
    await page.goto('/authz/overview');
    await startEditing(page);
    const restored = await commonMasterCell(page);
    await press(page, restored);
    await press(page, restored);
    await expect(restored).toHaveText('許可');
});
