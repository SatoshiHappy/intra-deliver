import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    adminMenuButton,
    openFromAdminMenu,
    sidebarHeading,
} from '../support/app-navigation';
import {
    addUserColumn,
    ADMINISTRATOR,
    APPLICANT,
    chooseType,
    deleteColumn,
    menuAdminRow,
    signInAs,
    press,
    resourceRow,
    startEditing,
    subjectCell,
    switchTo,
} from './authz-editor-support';

// 認可設定を編集できる人の広げ方(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 6 節・7 節、設計/ワークフロー/申請の周辺/画面.md 9 節)。
// システム管理者が申請者(佐々木 陽太 u0001)の列を作って許可を足し、その人の画面で確かめ、最後に列を削除して元へ戻す。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const APPLICANT_NAME = '佐々木 陽太';

/**
 * システム管理者に切り替えて認可設定を編集中にし、申請者の列を追加する。
 */
async function addApplicantColumn(page: Page, from: string) {
    await switchTo(page, from, ADMINISTRATOR);
    await page.goto('/authz/overview');
    await startEditing(page);
    await addUserColumn(page, '佐々木', APPLICANT_NAME);
}

/**
 * 申請者の列の、行 rowName の action 番目のセルを押して許可にする(編集中の画面で)。
 */
async function permit(page: Page, rowName: string, action = 0) {
    const cell = await subjectCell(
        page,
        resourceRow(page, rowName),
        APPLICANT_NAME,
        action,
    );
    await press(page, cell);
    await expect(cell.getByText('許可', { exact: true })).toBeVisible();
}

// 初期状態で認可設定を開けない人に、2 つの許可(認可設定の画面と、編集の 4 ルートの接頭辞のグループ「認可設定の編集」の「実行」)と
// メニューグループ「管理」(歯車のアプリ)の「参照」を許可すると、その人が開いてセルを保存できる。
test('認可設定を開けない人に 2 つの許可と「管理」の参照を足すと、開いてセルを保存できる', async ({
    page,
}) => {
    // 利用者の切替と列の追加・削除を含む長い一本道で、負荷のある回に既定の 60 秒を超えるため
    test.slow();
    await signInAs(page, APPLICANT);
    const denied = await page.goto('/authz/overview');
    expect(denied?.status()).toBe(403);

    await page.goto('/');
    await addApplicantColumn(page, APPLICANT.label);
    await chooseType(page, '画面・処理');
    await permit(page, '認可設定');
    await permit(page, '認可設定の編集');
    await chooseType(page, 'メニューグループ');
    const cell = await subjectCell(page, menuAdminRow(page), APPLICANT_NAME);
    await press(page, cell);

    await switchTo(page, ADMINISTRATOR.label, APPLICANT);
    // 管理のアプリの参照を許された人には歯車が出て、歯車から開くと今のアプリが管理になる
    await expect(adminMenuButton(page)).toBeVisible();
    await openFromAdminMenu(page, '認可設定');
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(
        page.getByRole('heading', { level: 1, name: '認可設定' }),
    ).toBeVisible();
    await startEditing(page);
    await permit(page, '組織図');

    await switchTo(page, APPLICANT.label, ADMINISTRATOR);
    await page.goto('/authz/overview');
    await startEditing(page);
    await deleteColumn(page, APPLICANT_NAME);
});
