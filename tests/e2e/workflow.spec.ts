import { expect, test } from './support/test';
import type { Page } from '@playwright/test';

// ワークフローの画面のうち、ゲストだけで成り立つもの(docs の 設計/ワークフロー/画面.md 9 節の main)。画面は PC の幅だけを前提にする。
// ナビの中は、E2E 専用のグループ(database/seeders/E2eMenuSeeder.php)が増えても通るよう、見出しでグループを絞ってから引く。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * グローバルナビの中の、見出し(h2)が name のグループ。
 */
function navigationGroup(page: Page, name: string) {
    return page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .locator('[data-sidebar="group"]')
        .filter({
            has: page.getByRole('heading', { level: 2, name, exact: true }),
        });
}

test('サイドバーの「ワークフロー」に「申請する」「申請一覧」があり、グループは「業務」の後に並ぶ(ゲストには「管理」が無い)', async ({
    page,
}) => {
    await page.goto('/');
    const group = navigationGroup(page, 'ワークフロー');

    for (const [name, icon] of [
        ['申請する', 'send'],
        ['申請一覧', 'inbox'],
    ]) {
        await expect(
            group
                .getByRole('link', { name, exact: true })
                .locator(`svg.lucide-${icon}`),
        ).toHaveCount(1);
    }

    const headings = await page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .getByRole('heading', { level: 2 })
        .allTextContents();

    expect(headings.indexOf('業務')).toBeGreaterThanOrEqual(0);
    expect(headings.indexOf('ワークフロー')).toBeGreaterThan(
        headings.indexOf('業務'),
    );
    expect(headings).not.toContain('管理');
});

test('ゲストの「申請する」は申請できるフローが無く、ゲストは申請できないと表示される', async ({
    page,
}) => {
    await page.goto('/');
    await navigationGroup(page, 'ワークフロー')
        .getByRole('link', { name: '申請する', exact: true })
        .click();

    await expect(page).toHaveURL('/workflow/flows');
    await expect(
        page.getByRole('heading', { level: 1, name: '申請の種類を選ぶ' }),
    ).toBeVisible();
    await expect(page).toHaveTitle('申請の種類を選ぶ - イントラデリバー');
    await expect(
        page.getByText('申請できるフローはありません', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('ゲストは申請できません', { exact: true }),
    ).toBeVisible();
});

test('ゲストの「申請一覧」は未処理のタブが空で、タブの件数が 0', async ({
    page,
}) => {
    await page.goto('/');
    await navigationGroup(page, 'ワークフロー')
        .getByRole('link', { name: '申請一覧', exact: true })
        .click();

    await expect(page).toHaveURL('/workflow/cases');
    await expect(
        page.getByRole('heading', { level: 1, name: '申請一覧' }),
    ).toBeVisible();
    const tabs = page.getByRole('navigation', { name: '申請のタブ' });
    const pending = tabs.getByRole('link', { name: /^未処理/ });
    await expect(pending).toHaveAttribute('aria-current', 'page');
    await expect(pending).toHaveText('未処理0');
    await expect(tabs.getByRole('link', { name: /^一時保存/ })).toHaveText(
        '一時保存0',
    );
    await expect(
        page
            .getByRole('table', { name: '未処理の申請' })
            .getByText('処理を待っている申請はありません', { exact: true }),
    ).toBeVisible();
});

test('ゲストのホームにはワークフローの節(件数・未処理の申請・今月の処理済み・進行中の申請)が表示されない', async ({
    page,
}) => {
    await page.goto('/');

    // ホームの見出し(h1)は tests/e2e/home.spec.ts が確かめる。ここは節が無いこと
    await expect(
        page.getByRole('region', { name: 'ごあいさつ' }),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: '件数' })).toHaveCount(0);

    const main = page.getByRole('main');

    for (const heading of ['未処理の申請', '今月の処理済み', '進行中の申請']) {
        await expect(
            main.getByRole('heading', { level: 2, name: heading }),
        ).toHaveCount(0);
    }
});
