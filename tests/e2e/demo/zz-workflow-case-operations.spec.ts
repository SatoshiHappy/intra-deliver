import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';
import { openAdminMenu, sidebarHeading } from '../support/app-navigation';
import {
    fillPlanApplication,
    openAs,
    submitApplication,
} from '../support/workflow-operations';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、システム管理者の申請操作(一覧と詳細の面)。
// 案件を変える筋は筋の中で申請した案件だけを使う(見本の案件は前の spec が変えた後の状態に依らない)。期限超過の見本は前の spec が承認して無くなりうるので、
// 申請した案件の課長のステップの到達を 10 日前にして作る。筋は前の筋の結果を使うので順に走らせる。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const PLAN_APPLY_URL = '/workflow/flows/project_plan/apply';
const PLAN_FORM = 'プロジェクト計画の承認の入力';
const PROJECT_NAME = 'E2E の申請操作';
const CASE_NAME = `プロジェクト計画の承認 ${PROJECT_NAME}`;

/** 筋の中で申請した案件の詳細の URL(申請の筋で決まり、後の筋が使う) */
let caseUrl = '';

/**
 * システム管理者の詳細で、申請操作の面(見出し「申請操作」の領域)を返す。
 */
async function openOperationSurface(page: Page): Promise<Locator> {
    await openAsAdministrator(page, new URL(caseUrl).pathname);
    const surface = page.getByRole('region', { name: '申請操作', exact: true });
    await expect(surface).toContainText(
        '申請操作の権限がある人だけに表示されます。',
    );

    return surface;
}

/**
 * 人を選ぶ欄で検索の語を入れ、候補の要求の URL を返す(候補を選ぶかどうかは呼ぶ側)。
 */
async function searchUsers(page: Page, field: Locator, word: string) {
    const sent = page.waitForRequest((request) => {
        const url = new URL(request.url());

        return (
            url.pathname === '/workflow/user-candidates' &&
            url.searchParams.get('q') === word
        );
    });
    await field
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill(word);

    return new URL((await sent).url());
}

test('用意: 申請者が申請し、課長のステップの到達を 10 日前にして期限超過にする', async ({
    page,
    artisan,
}) => {
    await openAs(page, 'applicant', PLAN_APPLY_URL);
    const form = page.getByRole('form', { name: PLAN_FORM });
    await fillPlanApplication(page, form, PROJECT_NAME);
    await submitApplication(page, form);
    caseUrl = page.url();

    const caseId = caseUrl.split('/').at(-1) ?? '';
    artisan([
        'tinker',
        '--execute',
        [
            'App\\Models\\Workflow\\WorkflowCaseNode::query()',
            `->where("workflow_case_id", ${Number(caseId)})`,
            '->where("state", "waiting")',
            '->update(["reached_at" => now()->subDays(10)]);',
        ].join(''),
    ]);
});

test('システム管理者: 歯車の「管理」の最後に「申請操作」「代理の管理」が並び、「申請操作」で一覧が開く', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    const menu = await openAdminMenu(page);
    const links = (await menu.getByRole('menuitem').allTextContents()).map(
        (text) => text.trim(),
    );
    expect(links.slice(-2)).toEqual(['申請操作', '代理の管理']);

    await menu.getByRole('menuitem', { name: '申請操作', exact: true }).click();
    await expect(page).toHaveURL(/\/workflow\/admin\/cases$/);
    // /workflow/admin/cases は管理のアイテムの一致が最長(ワークフローのアイテムは /workflow/cases で 1 区分)なので、今のアプリは管理
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(
        page.getByRole('heading', { level: 1, name: '申請操作' }),
    ).toBeVisible();
    await expect(
        page.getByText(
            'すべての申請を参照し、処理担当者の変更や申請の削除などを行います。',
            { exact: true },
        ),
    ).toBeVisible();
});

test('システム管理者: 「期限超過だけ」を入れると行がすべて期限超過の札を持ち、「完了」にすると「期限超過だけ」を押せない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/workflow/admin/cases');

    const overdueOnly = page.getByRole('checkbox', { name: '期限超過だけ' });
    await overdueOnly.click();
    await expect(page).toHaveURL(/overdue=1/);
    const rows = page
        .getByRole('table', { name: '申請の一覧' })
        .getByRole('row')
        .filter({ has: page.getByRole('cell') });
    const row = rows.filter({
        has: page.getByRole('link', { name: CASE_NAME }),
    });
    await expect(row).toHaveCount(1);
    await expect(rows.filter({ hasNotText: '期限超過' })).toHaveCount(0);
    const cells = row.locator('td');
    await expect(cells.nth(0)).toContainText('WF-');
    await expect(cells.nth(0)).toContainText('プロジェクト計画の承認');
    await expect(cells.nth(1)).toHaveText('佐々木 陽太');
    await expect(cells.nth(2)).toHaveText('承認待ち');
    // ステップ名は申請した日の経路の版で決まる(見本の版 1 は「課長の承認」、版 2 以降は「課長」)
    await expect(cells.nth(3)).toHaveText(/^課長(の承認)?$/);
    await expect(cells.nth(4)).toHaveText(/^1[01] 日$/);
    await expect(cells.nth(5)).toContainText('期限超過');

    await page.getByRole('radio', { name: '完了', exact: true }).click();
    await expect(page).toHaveURL(/scope=completed/);
    await expect(overdueOnly).toBeDisabled();
});

test('システム管理者: 詳細の申請操作の面から処理担当者を変更すると、トーストが出て履歴に「処理担当者の変更」の行が付く', async ({
    page,
}) => {
    const surface = await openOperationSurface(page);
    await surface
        .getByRole('button', { name: '処理担当者の変更', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '処理担当者を変更する' });
    await expect(dialog).toContainText(`${CASE_NAME}　課長`);
    await expect(
        dialog.getByRole('list', { name: '現在の処理担当者' }),
    ).toContainText('中村 美咲');
    await expect(dialog).toContainText('処理担当者が替わります');

    const field = dialog.getByRole('group', {
        name: '変更後の処理担当者',
        exact: true,
    });
    // 変更後の処理担当者の候補は申請基準日の有効なユーザー(案件を渡す)
    const candidates = await searchUsers(page, field, '山田');
    expect(candidates.searchParams.has('case')).toBe(true);
    await page.getByRole('option', { name: /^山田 恵/ }).click();
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        caseUrl,
    );

    await expect(
        page.getByText('処理担当者を変更しました', { exact: true }),
    ).toBeVisible();
    const history = page.getByRole('region', { name: '履歴' });
    await expect(history).toContainText('処理担当者の変更');
    await expect(
        history.getByText('処理担当者: 山田 恵', { exact: true }),
    ).toBeVisible();
});

test('システム管理者: ステップの移動(戻る)で申請のステップへ戻すと、トーストが出て履歴に「ステップの移動(戻る)」と「戻り先」が付く', async ({
    page,
}) => {
    const surface = await openOperationSurface(page);
    await surface
        .getByRole('button', { name: 'ステップの移動', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: 'ステップを移動する' });
    await expect(dialog).toContainText(CASE_NAME);
    // 戻るが既定で、戻り先の既定は直前に当たる最後の候補(申請)。注意の囲みは移動の種類で替わる
    await expect(
        dialog.getByRole('radio', { name: '戻る', exact: true }),
    ).toBeChecked();
    await expect(
        dialog.getByRole('combobox', { name: '戻り先', exact: true }),
    ).toHaveText('申請');
    await expect(dialog).toContainText(
        '戻り先以降のステップは、未到達に戻ります',
    );
    await dialog.getByRole('radio', { name: '終了', exact: true }).click();
    await expect(dialog).toContainText('申請を完了します');
    await dialog.getByRole('radio', { name: '戻る', exact: true }).click();

    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        caseUrl,
    );
    await expect(
        page.getByText('ステップを移動しました', { exact: true }),
    ).toBeVisible();
    const history = page.getByRole('region', { name: '履歴' });
    await expect(history).toContainText('ステップの移動(戻る)');
    await expect(
        history.getByText('戻り先: 申請', { exact: true }),
    ).toBeVisible();
});

test('システム管理者: 申請の削除は確かめのダイアログを経て一覧へ移り、トーストが出て、一覧にも詳細にも残らない', async ({
    page,
}) => {
    const surface = await openOperationSurface(page);
    await surface
        .getByRole('button', { name: '申請の削除', exact: true })
        .click();
    const confirm = page.getByRole('dialog', { name: '申請の削除' });
    await expect(confirm).toContainText(
        'この申請を削除しますか。申請書(申請内容・添付ファイル・コメント・更新履歴)も一緒に削除されます。この操作は取り消せません。',
    );
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '削除', exact: true }),
        /\/workflow\/admin\/cases$/,
    );

    await expect(
        page.getByText('申請を削除しました', { exact: true }),
    ).toBeVisible();
    await page.goto(
        `/workflow/admin/cases?q=${encodeURIComponent(PROJECT_NAME)}`,
    );
    await expect(
        page.getByRole('cell', {
            name: '該当する申請はありません',
            exact: true,
        }),
    ).toBeVisible();
    const gone = await page.goto(caseUrl);
    expect(gone?.status()).toBe(404);
});
