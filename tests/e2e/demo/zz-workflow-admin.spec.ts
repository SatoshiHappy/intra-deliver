import { expect, test } from '../support/test';
import { openAdminMenu, sidebarHeading } from '../support/app-navigation';
import { openAsAdministrator } from '../support/records-headings';
import { todayInTokyo } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';
import {
    bodyRows,
    confirmToggle,
    openAddDialog,
    plusDays,
    tab,
    versionRows,
} from '../support/workflow-admin';

// 定義の管理の画面(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md の 10 節)の筋のうち、システム管理者の
// 一覧・詳細の骨組み・版・参照者。定義を変える筋(版の追加・参照者の保存)があるので、E2E の組の中でいちばん最後に流す
// (名前が zz- で始まり、同じ組の前の spec は定義を前提にしない。組の理由は groups.ts)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const PLAN = '/workflow/admin/flows/project_plan';

test('システム管理者: 歯車の「管理」に「フロー定義」が「申請操作」の前にあり、一覧に 2 つのフローと現在の版が出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    const menu = await openAdminMenu(page);
    const links = (await menu.getByRole('menuitem').allTextContents()).map(
        (text) => text.trim(),
    );
    expect(links.slice(-3)).toEqual(['フロー定義', '申請操作', '代理の管理']);

    await menu
        .getByRole('menuitem', { name: 'フロー定義', exact: true })
        .click();
    await expect(page).toHaveURL(/\/workflow\/admin\/flows$/);
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(
        page.getByRole('heading', { level: 1, name: 'フロー定義' }),
    ).toBeVisible();
    await expect(
        page.getByText(
            'フロー定義の一覧です。版と参照者を確認し、詳細画面から編集できます。',
            { exact: true },
        ),
    ).toBeVisible();

    const rows = bodyRows(
        page.getByRole('table', { name: 'フロー定義の一覧' }),
        page,
    );
    await expect(rows).toHaveCount(2);
    const plan = rows.filter({ hasText: 'プロジェクト計画の承認' });
    await expect(plan).toContainText('版 3');
    await expect(plan).toContainText('終了日なし');
    await expect(plan).toContainText('経理部');
    const issue = rows.filter({ hasText: '課題の対応の承認' });
    await expect(issue).toContainText('版 1');
    await expect(issue).toContainText('部長');
});

test('システム管理者: 「プロジェクト計画の承認」の詳細でフロー図に分岐「予算」と縦配置の枠が出て、版を替えると図が切り替わる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/workflow/admin/flows');
    await page
        .getByRole('link', {
            name: 'プロジェクト計画の承認の詳細',
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(/\/workflow\/admin\/flows\/project_plan$/);
    await expect(
        page.getByRole('heading', { level: 1, name: 'プロジェクト計画の承認' }),
    ).toBeVisible();
    await expect(page.getByText(/現在の版は版 3 です。/)).toBeVisible();
    await expect(tab(page, 'フロー図')).toHaveAttribute('aria-current', 'page');

    const diagram = page.getByRole('group', { name: '版 3 のフロー図' });
    await expect(diagram).toContainText('分岐「予算」');
    await expect(diagram).toContainText('縦配置「関係部署の合議」');
    await expect(diagram).toContainText('承認 3 から 5');
    await expect(diagram).toContainText('任意');
    // 右の欄は、選んでいないときは申請のステップの要約の 1 文
    await expect(
        page.getByRole('heading', { level: 2, name: '申請 申請' }),
    ).toBeVisible();
    await expect(
        page.getByText('一般利用者が申請できます。', { exact: true }),
    ).toBeVisible();

    await page.getByRole('combobox', { name: '版', exact: true }).click();
    await page.getByRole('option', { name: '版 2', exact: true }).click();
    await expect(page).toHaveURL(/version=2/);
    const older = page.getByRole('group', { name: '版 2 のフロー図' });
    await expect(older).toBeVisible();
    await expect(older).not.toContainText('分岐「予算」');
});

test('システム管理者: 版のタブに版の表が並び、版を追加するとトーストが出て、足した版と前の版の終了日が表に出る', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?tab=versions`);
    await expect(tab(page, '版')).toHaveAttribute('aria-current', 'page');
    const rows = versionRows(page);
    await expect(rows).toHaveCount(3);
    await expect(rows.filter({ hasText: '版 3' })).toContainText('現在');
    await expect(rows.filter({ hasText: '版 1' })).toContainText(
        'ユーザー無効',
    );
    await expect(
        page.getByText(
            '版の期間に重複と空白は設定できません。申請は、申請基準日を含む有効な版で行われます。',
            { exact: true },
        ),
    ).toBeVisible();

    const dialog = page.getByRole('dialog', { name: '版を追加する' });
    const start = await openAddDialog(page, dialog);
    const added = plusDays(todayInTokyo(), 30);
    await start.fill(added);
    await expect(dialog).toContainText(
        `版 3 の終了日が ${plusDays(added, -1)} に変更されます。`,
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        /tab=versions&version=4/,
    );
    await expect(
        page.getByText('版を追加しました', { exact: true }),
    ).toBeVisible();
    await expect(rows).toHaveCount(4);
    const newest = rows.filter({ hasText: '版 4' });
    await expect(newest).toContainText(added);
    await expect(newest).toContainText('終了日なし');
    await expect(rows.filter({ hasText: '版 3' })).toContainText(
        plusDays(added, -1),
    );
});

test('システム管理者: 足した版を無効にして有効に戻すと、確かめのダイアログの後にトーストが出て状態が変わる', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?tab=versions`);
    const newest = versionRows(page).filter({ hasText: '版 4' });
    await newest.getByRole('button', { name: '版 4 を無効にする' }).click();
    await expect(
        page.getByRole('dialog', { name: '版を無効にする' }),
    ).toContainText(
        '版 4 を無効にします。無効にした版の期間には申請できません。',
    );
    await confirmToggle(page, '版を無効にする', '版を無効にしました');
    await expect(newest).toContainText('ユーザー無効');

    await newest.getByRole('button', { name: '版 4 を有効にする' }).click();
    await expect(
        page.getByRole('dialog', { name: '版を有効にする' }),
    ).toContainText('版 4 を有効にします。');
    await confirmToggle(page, '版を有効にする', '版を有効にしました');
    await expect(newest).not.toContainText('ユーザー無効');
});

test('システム管理者: 参照者のタブで行を足して保存するとトーストが出て、未選択の行は保存できず、取消で元に戻る', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN}?tab=referrers`);
    await expect(tab(page, '参照者')).toHaveAttribute('aria-current', 'page');
    await expect(
        page.getByRole('heading', { level: 2, name: '参照者の指定' }),
    ).toBeVisible();
    const rows = bodyRows(page.getByRole('table', { name: '参照者' }), page);
    await expect(rows).toHaveCount(1);
    await expect(rows.first()).toContainText('経理部');

    const add = page.getByRole('button', { name: '行を追加', exact: true });
    const save = page.getByRole('button', { name: '保存', exact: true });
    await add.click();
    await expect(rows).toHaveCount(2);
    const target = page.getByRole('combobox', {
        name: '対象者 2 行目',
        exact: true,
    });
    await target.click();
    await page.getByPlaceholder('名前・コードで検索').fill('達也');
    await page.getByRole('option', { name: /吉田 達也/ }).click();
    await expect(target).toContainText('吉田 達也');
    await submitAndExpectUrl(page, save, /tab=referrers/);
    await expect(
        page.getByText('参照者を保存しました', { exact: true }),
    ).toBeVisible();
    await expect(rows).toHaveCount(2);
    await expect(rows.nth(1)).toContainText('吉田 達也');

    // 相手を選ばない行は保存できず、行の下に誤りが出る。取消で今の値に戻る
    await add.click();
    await expect(rows).toHaveCount(3);
    await submitAndExpectUrl(page, save);
    await expect(
        page.getByText('対象者を選択してください', { exact: true }),
    ).toBeVisible();
    await page.getByRole('button', { name: '取消', exact: true }).click();
    await expect(rows).toHaveCount(2);
    await expect(
        page.getByText('対象者を選択してください', { exact: true }),
    ).toHaveCount(0);
});
