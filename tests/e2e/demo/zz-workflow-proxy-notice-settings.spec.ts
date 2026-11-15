import { expect, test } from '../support/test';
import type { Browser, Locator, Page } from '@playwright/test';
import { openAsAdministrator } from '../support/records-headings';
import { toasts } from '../support/toasts';
import {
    fillPlanApplication,
    openAs,
    submitApplication,
    switchUser,
} from '../support/workflow-operations';

// 通知の受け取りの設定の流れ(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/ルートと文言と検査.md の 8 節の「E2E」)。
// 利用者 A = 課長(中村 美咲)。別の利用者 = 申請者が計画を申請すると、最初のステップの課長に処理依頼が回る。
// 組の中の置き場と、通知の設定を触った後に戻す約束は tests/e2e/demo/groups.ts の demo-workflow のコメント。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

// 途中で落ちても戻す(groups.ts)。触るのは課長(u0002)の処理依頼・担当変更の行と、処理依頼の必須の印だけ
test.afterAll(({ artisan }) => {
    artisan([
        'tinker',
        '--execute',
        [
            'DB::table("notice_opt_outs")->whereIn("type", ["workflow.process_request", "workflow.transfer"])',
            '->whereIn("user_id", DB::table("users")->where("code", "u0002")->select("id"))->delete();',
            'DB::table("notice_required_types")->where("type", "workflow.process_request")->delete();',
        ].join(''),
    ]);
});

const CHIEF = '中村 美咲（東京営業課）';
const APPLICANT = '佐々木 陽太（東京営業課）';
const PLAN_APPLY_URL = '/workflow/flows/project_plan/apply';
const PLAN_FORM = 'プロジェクト計画の承認の入力';
const REQUIRED_TEXT = '管理者が必須に設定しているため、変更できません。';

const bell = (page: Page) => page.getByRole('button', { name: /^通知/ });

// 名前は「通知(未読 n 件)」。未読が無ければ「通知」
async function unreadCount(page: Page): Promise<number> {
    const label = await bell(page).getAttribute('aria-label');

    return Number(/未読 (\d+) 件/.exec(label ?? '')?.[1] ?? 0);
}

function workflowRow(page: Page, switchName: string): Locator {
    return page
        .getByRole('region', { name: 'ワークフロー', exact: true })
        .getByRole('listitem')
        .filter({
            has: page.getByRole('switch', { name: switchName, exact: true }),
        });
}

async function applyThenBackToChief(page: Page, projectName: string) {
    await switchUser(page, CHIEF, 'applicant');
    await page.goto(PLAN_APPLY_URL);
    const form = page.getByRole('form', { name: PLAN_FORM });
    await fillPlanApplication(page, form, projectName);
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await switchUser(page, APPLICANT, 'chief');
    await page.goto('/');
}

// Playwright の click は押せる(disabled でない)まで待つので、保存の間に 2 本目を押せない。ブラウザの中で続けて押す
async function clickSwitchesAtOnce(page: Page, names: string[]) {
    await page.getByRole('switch').evaluateAll((switches, targets) => {
        for (const name of targets) {
            const target = switches.find(
                (element) => element.getAttribute('aria-label') === name,
            );
            if (!(target instanceof HTMLElement)) {
                throw new Error(`スイッチが無い: ${name}`);
            }
            target.click();
        }
    }, names);
}

// 保存の応答を返すと再描画で押せる状態に戻り、前の保存の間に後の保存が出る経路(docs の
// 設計/ワークフロー/申請の周辺/通知の受け取りの設定/利用者と管理の画面.md の 3.3)を確かめられないので、応答を止めておく
async function clickTwoSwitchesWhileHoldingSave(
    page: Page,
    first: string,
    second: string,
) {
    let releaseSave: () => void = () => {};
    const saveHeld = new Promise<void>((resolve) => {
        releaseSave = resolve;
    });
    let saveArrived: () => void = () => {};
    const arrived = new Promise<void>((resolve) => {
        saveArrived = resolve;
    });
    let saveHandled: Promise<void> = Promise.resolve();
    await page.route('**/notices/settings', (route) => {
        if (route.request().method() !== 'PUT') {
            return route.fallback();
        }
        saveArrived();
        saveHandled = (async () => {
            await saveHeld;
            await route.continue();
        })();

        return saveHandled;
    });

    await clickSwitchesAtOnce(page, [first, second]);
    for (const toggle of await page.getByRole('switch').all()) {
        await expect(toggle).toBeDisabled();
    }
    await arrived;
    releaseSave();
    await saveHandled;
    await page.unrouteAll({ behavior: 'wait' });
}

// A のセッションを替えずに管理者が操作するため、別の文脈で開く(開いたままの A の画面は古いまま残る = 利用者と管理の画面.md の 3.3 の同時)
async function toggleRequiredAsAdministrator(
    browser: Browser,
    expectRequired: boolean,
) {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 720 },
    });

    try {
        const admin = await context.newPage();
        await openAsAdministrator(admin, '/notices/admin');
        const row = workflowRow(admin, '処理依頼を必須にする');
        await row.getByRole('switch').click();
        await expect(row.getByRole('switch')).toBeChecked({
            checked: expectRequired,
        });
        await expect(
            row.getByText(expectRequired ? '必須' : '必須でない', {
                exact: true,
            }),
        ).toBeVisible();
    } finally {
        await context.close();
    }
}

test('ゲストの通知の設定の画面は、保存の案内に代えて「設定できる通知はありません」を出し、面とスイッチを出さない', async ({
    page,
}) => {
    await page.goto('/notices/settings');

    await expect(
        page.getByRole('heading', { level: 1, name: '通知の設定' }),
    ).toBeVisible();
    await expect(
        page.getByText('設定できる通知はありません', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('switch')).toHaveCount(0);
});

test('A が処理依頼を受け取らないにすると(保存の間は全スイッチが押せない)、別の利用者の申請で処理依頼が回っても鈴の件数が増えない', async ({
    page,
}) => {
    await openAs(page, 'chief', '/notices/settings');
    const row = workflowRow(page, '処理依頼を受け取る');
    await expect(row.getByRole('switch')).toBeChecked();
    await expect(row).toContainText('受け取る');

    // 要求の数では確かめない(守りが外れても、Inertia が前の要求を送る前に止めるので 1 本に見える)。1 本目の行の値が残り、2 本目の行が変わらないことで確かめる
    const other = workflowRow(page, '担当変更を受け取る');
    await expect(other.getByRole('switch')).toBeChecked();
    await clickTwoSwitchesWhileHoldingSave(
        page,
        '処理依頼を受け取る',
        '担当変更を受け取る',
    );
    await expect(row.getByRole('switch')).not.toBeChecked();
    await expect(row.getByRole('switch')).toBeEnabled();
    await expect(row).toContainText('受け取らない');
    await expect(other.getByRole('switch')).toBeChecked();
    // 成功のトーストは出さない(スイッチの表示が結果)
    await expect(toasts(page)).toHaveCount(0);

    await page.goto('/');
    const before = await unreadCount(page);
    const projectName = 'E2E の通知の設定(受け取らない)';
    await applyThenBackToChief(page, projectName);

    expect(await unreadCount(page)).toBe(before);
    await page.goto('/notices');
    await expect(
        page
            .getByRole('table', { name: '通知' })
            .getByRole('row')
            .filter({ hasText: projectName }),
    ).toHaveCount(0);
});

test('システム管理者が処理依頼に必須を付けると、開いたままの A の画面は保存が 422 で拒まれて行が必須の見え方になり、A に処理依頼が届く', async ({
    page,
    browser,
}) => {
    await openAs(page, 'chief', '/notices/settings');
    const row = workflowRow(page, '処理依頼を受け取る');
    await expect(row.getByRole('switch')).not.toBeChecked();

    await toggleRequiredAsAdministrator(browser, true);

    // 開いたままの画面(必須の前の見え方)でクリックすると、サーバーが拒み、トーストと行の作り直しで必須の見え方になる
    await row.getByRole('switch').click();
    await expect(
        toasts(page).getByText(REQUIRED_TEXT, {
            exact: true,
        }),
    ).toBeVisible();
    await expect(row.getByRole('switch')).toBeChecked();
    await expect(row.getByRole('switch')).toBeDisabled();
    await expect(
        row.getByText('必須(管理者の設定)', { exact: true }),
    ).toBeVisible();
    await expect(row).toContainText('受け取る');

    // 読み込み直しても同じ(本人の設定の行は受け取らないのまま、必須の間は効かない)
    await page.reload();
    await expect(row.getByRole('switch')).toBeDisabled();
    await expect(
        row.getByText('必須(管理者の設定)', { exact: true }),
    ).toBeVisible();

    await page.goto('/');
    const before = await unreadCount(page);
    await applyThenBackToChief(page, 'E2E の通知の設定(必須)');

    expect(await unreadCount(page)).toBe(before + 1);
});

test('必須を外すと、A の行は受け取らないに戻る', async ({ page, browser }) => {
    await toggleRequiredAsAdministrator(browser, false);

    await openAs(page, 'chief', '/notices/settings');
    const row = workflowRow(page, '処理依頼を受け取る');
    await expect(row.getByRole('switch')).not.toBeChecked();
    await expect(row.getByRole('switch')).toBeEnabled();
    await expect(row).toContainText('受け取らない');
    await expect(row.getByText('必須(管理者の設定)')).toHaveCount(0);
});

test('A が受け取るに戻すと、次の処理依頼は届く(終わりは A が受け取る)', async ({
    page,
}) => {
    await openAs(page, 'chief', '/notices/settings');
    const row = workflowRow(page, '処理依頼を受け取る');
    await row.getByRole('switch').click();
    await expect(row.getByRole('switch')).toBeChecked();
    await expect(row).toContainText('受け取る');

    await page.goto('/');
    const before = await unreadCount(page);
    await applyThenBackToChief(page, 'E2E の通知の設定(受け取る)');

    expect(await unreadCount(page)).toBe(before + 1);
});
