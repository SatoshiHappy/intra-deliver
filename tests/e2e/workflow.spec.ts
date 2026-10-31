import { expect, test } from './support/test';
import {
    breadcrumbs,
    adminMenuButton,
    launcherTiles,
    openApp,
    openLauncher,
    sidebarHeading,
    sidebarSection,
    untitledSections,
} from './support/app-navigation';

// ワークフローの画面のうち、ゲストだけで成り立つもの(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 9 節の main)。画面は PC の幅だけを前提にする。
// サイドバーはワークフローのアプリの見出し(h2)と区画の見出し(h3)で引く(E2E 専用のアプリ database/seeders/E2eMenuSeeder.php が増えても通る)。
test.use({ viewport: { width: 1280, height: 720 } });

test('ランチャーの先頭がワークフローで、ゲストには歯車が無く、ワークフローのサイドバーに「申請する」「申請一覧」と申請書がある', async ({
    page,
}) => {
    await page.goto('/');

    // ランチャーのアプリの先頭は「ワークフロー」(並び 50)。歯車のアプリ(管理)はゲストに出ないので歯車が無い
    await openLauncher(page);
    await expect(launcherTiles(page).first()).toHaveText(/^ワークフロー/);
    await expect(adminMenuButton(page)).toHaveCount(0);

    // タイルで移ると、サイドバーの先頭がアプリの名前「ワークフロー」になる
    await launcherTiles(page).first().click();
    await expect(sidebarHeading(page)).toHaveText('ワークフロー');

    // 見出しなしの区画 = ホーム、見出し「申請と承認」の下に「申請する」「申請一覧」、見出し「申請書」の下に申請書の業務モジュール
    await expect(
        untitledSections(page).getByRole('link', {
            name: 'ホーム',
            exact: true,
        }),
    ).toHaveCount(1);
    const applications = sidebarSection(page, '申請と承認');

    for (const [name, icon] of [
        ['申請する', 'send'],
        ['申請一覧', 'inbox'],
    ]) {
        await expect(
            applications
                .getByRole('link', { name, exact: true })
                .locator(`svg.lucide-${icon}`),
        ).toHaveCount(1);
    }

    await expect(sidebarSection(page, '申請書').getByRole('link')).toHaveText([
        'プロジェクト計画の申請',
        '課題の対応の申請',
    ]);
});

test('ワークフローのホームは、h1 がアプリの名前、題が「ワークフロー - イントラデリバー」、パンくずが「ワークフロー > ホーム」で、案内の一文だけが出る', async ({
    page,
}) => {
    await page.goto('/');
    await openApp(page, 'ワークフロー');

    // 先頭の画面はワークフローのホーム
    await expect(page).toHaveURL('/workflow');
    await expect(page).toHaveTitle('ワークフロー - イントラデリバー');
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'ワークフロー',
    );
    await expect(breadcrumbs(page).getByRole('listitem')).toHaveText([
        'ワークフロー',
        'ホーム',
    ]);
    // 今のアプリの段は先頭の画面へのリンク、最後の段「ホーム」はリンクにしない(a が 1 つだけ)
    const steps = breadcrumbs(page).locator('a[href]');
    await expect(steps).toHaveCount(1);
    await expect(steps).toHaveText('ワークフロー');
    await expect(steps).toHaveAttribute('href', '/workflow');
    await expect(
        breadcrumbs(page).locator('[data-slot="breadcrumb-page"]'),
    ).toHaveText('ホーム');

    // ゲストにはあいさつ欄の案内の一文だけが出て、ほかの節と主な操作は出ない
    const main = page.getByRole('main');
    await expect(
        main.getByText('ゲストは申請も処理もできません。', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: '件数' })).toHaveCount(0);

    for (const heading of [
        '未処理の申請',
        '今月の処理済み',
        '進行中の申請',
        '主な操作',
    ]) {
        await expect(
            main.getByRole('heading', { level: 2, name: heading }),
        ).toHaveCount(0);
    }
});

test('ゲストの「申請する」は申請できるフローが無く、ゲストは申請できないと表示される', async ({
    page,
}) => {
    await page.goto('/workflow');
    await sidebarSection(page, '申請と承認')
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
    await page.goto('/workflow');
    await sidebarSection(page, '申請と承認')
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
