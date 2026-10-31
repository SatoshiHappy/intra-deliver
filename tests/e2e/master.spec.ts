import { expect, test } from './support/test';
import type { Page, Route } from '@playwright/test';
import {
    breadcrumbs,
    adminMenuButton,
    headerSearch,
    sidebarLink,
    sidebarHeading,
} from './support/app-navigation';

// 共通マスタの組織図とヘッダーの検索(docs の 設計/共通マスタ/検索と組織図.md 5 節)。ゲストだけで成り立つので main の E2E に置く(利用者の切替のオン・オフに依らない)。
// 画面は PC の幅だけを前提にする。組織図は管理のアプリの画面なので、ゲストのサイドバーには出ない(歯車から開く筋はシステム管理者の E2E が確かめる)。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 組織図の左の面(組織の木)の、名称が name の組織のリンク。
 */
function departmentLink(page: Page, name: string) {
    return page
        .getByRole('navigation', { name: '組織の木' })
        .getByRole('link', { name, exact: true });
}

/**
 * 検索の欄を開いて語を打つまで(容器に依る手順はここだけ。容器を Dialog に替えるときはこの関数を直す = 設計 3.4)。
 */
async function searchFor(page: Page, text: string) {
    await headerSearch(page).click();
    await headerSearch(page).fill(text);
}

/**
 * 検索の結果の群(「ユーザー」「組織」)。
 */
function resultGroup(page: Page, name: string) {
    return page.getByRole('listbox').getByRole('group', { name });
}

test('ゲストのサイドバーには組織図が出ず(アプリの一覧)、URL では開け、頂点の組織が選ばれている', async ({
    page,
}) => {
    await page.goto('/master/organization-chart');

    // 管理のアプリはゲストに表示されないので、今のアプリは無く、サイドバーはランチャーのアプリの一覧・パンくずにアプリの段が無い・歯車が無い
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(sidebarLink(page, '組織図')).toHaveCount(0);
    await expect(adminMenuButton(page)).toHaveCount(0);
    await expect(breadcrumbs(page).getByRole('listitem')).toHaveText([
        '組織図',
    ]);

    await expect(
        page.getByRole('heading', { level: 1, name: '組織図' }),
    ).toBeVisible();
    await expect(page).toHaveTitle('組織図 - イントラデリバー');
    await expect(departmentLink(page, '株式会社デリバー商事')).toHaveAttribute(
        'aria-current',
        'true',
    );
    await expect(
        page.getByRole('region', { name: '株式会社デリバー商事' }),
    ).toBeVisible();
});

test('木の組織を押すと、URL のクエリと右の表が変わる', async ({ page }) => {
    await page.goto('/master/organization-chart');

    await departmentLink(page, '人事部').click();

    await expect(page).toHaveURL(
        /\/master\/organization-chart\?department=\d+$/,
    );
    await expect(departmentLink(page, '人事部')).toHaveAttribute(
        'aria-current',
        'true',
    );
    await expect(
        departmentLink(page, '株式会社デリバー商事'),
    ).not.toHaveAttribute('aria-current', 'true');
    const humanResources = page
        .getByRole('region', { name: '人事部' })
        .getByRole('row', { name: /吉田 達也/ });
    await expect(humanResources).toContainText('部長');
    await expect(humanResources).toContainText('兼務');

    await departmentLink(page, '総務部').click();

    await expect(departmentLink(page, '総務部')).toHaveAttribute(
        'aria-current',
        'true',
    );
    await expect(
        page
            .getByRole('region', { name: '総務部' })
            .getByRole('row', { name: /吉田 達也/ }),
    ).toContainText('主所属');
});

test('検索の欄で「人事」と打ち、↓と Enter で組織「人事部」を選ぶと組織図へ移る', async ({
    page,
}) => {
    await page.goto('/');
    await searchFor(page, '人事');
    await expect(
        resultGroup(page, '組織').getByRole('option', {
            name: '人事部',
            exact: true,
        }),
    ).toBeVisible();

    await headerSearch(page).press('ArrowDown');
    await headerSearch(page).press('Enter');

    await expect(page).toHaveURL(
        /\/master\/organization-chart\?department=\d+$/,
    );
    await expect(departmentLink(page, '人事部')).toHaveAttribute(
        'aria-current',
        'true',
    );
    await expect(
        page
            .getByRole('region', { name: '人事部' })
            .getByRole('row', { name: /吉田 達也/ }),
    ).toContainText('兼務');
    // 選ぶと欄は空になって閉じる
    await expect(headerSearch(page)).toHaveValue('');
    await expect(page.getByRole('listbox')).toHaveCount(0);
});

test('ユーザーを選ぶと、主所属の組織が選ばれてその人の行が強調される', async ({
    page,
}) => {
    await page.goto('/');
    await searchFor(page, '吉田');

    await resultGroup(page, 'ユーザー')
        .getByRole('option', { name: /^吉田 達也/ })
        .click();

    await expect(page).toHaveURL(
        /\/master\/organization-chart\?department=\d+&user=\d+$/,
    );
    await expect(departmentLink(page, '総務部')).toHaveAttribute(
        'aria-current',
        'true',
    );
    await expect(
        page
            .getByRole('region', { name: '総務部' })
            .getByRole('row', { name: /吉田 達也/ }),
    ).toHaveAttribute('aria-current', 'true');
});

test('該当しない語では「該当するユーザー・組織はありません」が出て、Esc で閉じる', async ({
    page,
}) => {
    await page.goto('/');
    await searchFor(page, '該当しない語');

    await expect(
        page.getByText('該当するユーザー・組織はありません', { exact: true }),
    ).toBeVisible();

    await headerSearch(page).press('Escape');
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await expect(headerSearch(page)).toBeFocused();
});

test('表示の名称に打った語を含まない項目(コードだけで一致)も出て、選べる', async ({
    page,
}) => {
    await page.goto('/');
    await searchFor(page, 'human');

    await resultGroup(page, '組織')
        .getByRole('option', { name: '人事部', exact: true })
        .click();

    await expect(departmentLink(page, '人事部')).toHaveAttribute(
        'aria-current',
        'true',
    );
});

test('同じ表示の 2 項目を、キー操作でそれぞれ選べる', async ({ page }) => {
    // E2E 専用データを足さず、検索の応答を同じ表示の 2 件に差し替える(設計 5 節)。木に無い ID なので、組織図は頂点を選ぶ
    await page.route(/\/master\/search\?/, (route) =>
        route.fulfill({
            json: {
                users: [],
                departments: [
                    { id: 900001, code: 'same-1', name: '同じ名前の組織' },
                    { id: 900002, code: 'same-2', name: '同じ名前の組織' },
                ],
            },
        }),
    );
    const options = resultGroup(page, '組織').getByRole('option', {
        name: '同じ名前の組織',
        exact: true,
    });

    await page.goto('/');
    await searchFor(page, '同じ');
    await expect(options).toHaveCount(2);
    await headerSearch(page).press('Enter');
    await expect(page).toHaveURL(
        '/master/organization-chart?department=900001',
    );

    await searchFor(page, '同じ');
    await expect(options).toHaveCount(2);
    await headerSearch(page).press('ArrowDown');
    await headerSearch(page).press('Enter');
    await expect(page).toHaveURL(
        '/master/organization-chart?department=900002',
    );
});

test('結果を出したまま、サイドバーのリンクでほかのページへ移ると、欄が空になって閉じる', async ({
    page,
}) => {
    await page.goto('/');
    await searchFor(page, '人事');
    await expect(
        resultGroup(page, '組織').getByRole('option', {
            name: '人事部',
            exact: true,
        }),
    ).toBeVisible();

    await sidebarLink(page, 'ワークフロー').click();

    await expect(page).toHaveURL('/workflow');
    await expect(headerSearch(page)).toHaveValue('');
    await expect(page.getByRole('listbox')).toHaveCount(0);
});

test('Esc で閉じた後、欄を押すか↓で開き直す', async ({ page }) => {
    await page.goto('/');
    await searchFor(page, '人事');
    const option = resultGroup(page, '組織').getByRole('option', {
        name: '人事部',
        exact: true,
    });
    await expect(option).toBeVisible();

    await headerSearch(page).press('Escape');
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await headerSearch(page).click();
    await expect(option).toBeVisible();

    await headerSearch(page).press('Escape');
    await expect(page.getByRole('listbox')).toHaveCount(0);
    await headerSearch(page).press('ArrowDown');
    await expect(option).toBeVisible();
});

test('遅れて届いた古い応答は捨て、最後に打った語の結果だけを出す', async ({
    page,
}) => {
    // 要求を 3 本ためてから、送った順と逆に返す(順に返すと、古い応答を捨てていなくても最後の結果が残って通ってしまう)
    const pending: Route[] = [];
    await page.route(/\/master\/search\?/, (route) => {
        pending.push(route);
    });
    const option = (name: string) =>
        resultGroup(page, '組織').getByRole('option', { name, exact: true });

    await page.goto('/');
    await headerSearch(page).click();
    for (const text of ['一', '二', '三']) {
        await headerSearch(page).fill(text);
        // 遅延(200ms)より長く待ち、打ち替えごとに要求を送らせる
        await page.waitForTimeout(300);
    }
    await expect.poll(() => pending.length).toBe(3);

    for (const route of [...pending].reverse()) {
        const keyword = new URL(route.request().url()).searchParams.get('q');
        await route.fulfill({
            json: {
                users: [],
                departments: [
                    {
                        id: 900001,
                        code: `code-${keyword}`,
                        name: `組織${keyword}`,
                    },
                ],
            },
        });
        await page.waitForTimeout(100);
    }

    await expect(option('組織三')).toBeVisible();
    // 古い応答が後から上書きしないこと(否定の確かめなので、届き終わる時間だけ待つ)
    await page.waitForTimeout(500);
    await expect(option('組織三')).toBeVisible();
    await expect(option('組織一')).toHaveCount(0);
    await expect(option('組織二')).toHaveCount(0);
});
