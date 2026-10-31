import { expect, test } from './support/test';
import type { Page } from '@playwright/test';
import {
    breadcrumbs,
    escapeRegExp,
    expectSidebarSectionHeadings,
    headerSearch,
    launcherButton,
    launcherPanel,
    launcherSearch,
    launcherTile,
    launcherTiles,
    openApp,
    openLauncher,
    sidebar,
    sidebarHeading,
    sidebarSection,
    sitemapGroup,
} from './support/app-navigation';

// アプリの骨格(docs の 設計/メニュー/表示.md 3.3、3.3.7 の E2E)のうち、ゲストだけで成り立つもの。ランチャー(タイル・検索・開閉と焦点)、
// タイルでアプリへ移ったときのサイドバーの先頭とパンくずのアプリの段、リンクで別のアプリの画面へ移ったときのサイドバーの替わり方。
// 歯車と管理のアプリは、権限の違う利用者の筋が確かめる。画面は PC の幅だけを前提にする。
// E2E の環境では E2E 専用のアプリ(database/seeders/E2eMenuSeeder.php。並び 800)がランチャーのアプリに入る
// (docs の 設計/メニュー/初期データ.md 4 節)ので、タイルの並びは ワークフロー → E2E の入れ子 → 業務。
// 先頭の画面と業務の画面の一覧は、初期データの業務の木を前提にする(同じ組で前に流れる spec が業務の木を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 初期データの業務のアプリの、ゲストに見える画面(木の順。RecordsSeeder のプロジェクト・課題、BuiltinSeeder の会議。
 * 申請書の業務モジュールは非表示で、ワークフローのアプリに出る = docs の 設計/メニュー/初期データ.md 4 節)。
 */
const WORK_SCREENS = ['プロジェクト', '課題', '会議'];

/**
 * ランチャーの検索の結果の群(「アプリ」「画面」)。
 */
function resultGroup(page: Page, name: string) {
    return launcherPanel(page).getByRole('group', { name, exact: true });
}

test('ランチャーのタイルの並びは、業務のアプリが最後(E2E のアプリが入っても)', async ({
    page,
}) => {
    await page.goto('/');
    await openLauncher(page);

    await expect(launcherTiles(page)).toHaveText([
        /^ワークフロー/,
        /^E2E の入れ子/,
        /^業務/,
    ]);
    // 説明があるアプリは名前の下に説明が出る
    await expect(launcherTile(page, 'ワークフロー')).toContainText(
        '申請と承認',
    );
    await expect(launcherTile(page, '業務')).toContainText('業務のテーブル');
});

test('ランチャーのタイルでアプリへ移ると、サイドバーの先頭とパンくずのアプリの段がそのアプリになり、パネルが閉じる', async ({
    page,
}) => {
    await page.goto('/');
    // 全体のホームには今のアプリが無い
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(launcherButton(page)).toBeVisible();

    await openLauncher(page);
    // どのタイルにも「現在のアプリ」の印が無い
    await expect(
        launcherTiles(page).and(page.locator('[aria-current]')),
    ).toHaveCount(0);
    await launcherTile(page, '業務').click();

    // 先頭の画面(初期データの業務の先頭のアイテム = プロジェクトの一覧)へ移る
    await expect(page).toHaveURL('/records/data/project');
    await expect(launcherPanel(page)).toHaveCount(0);
    await expect(sidebarHeading(page)).toHaveText('業務');
    // パンくずの先頭はアプリの段(先頭の画面へのリンク)で、最後の段はリンクにしない
    const steps = breadcrumbs(page).getByRole('listitem');
    await expect(steps.first()).toHaveText('業務');
    const appStep = breadcrumbs(page).locator('a[href]').first();
    await expect(appStep).toHaveText('業務');
    await expect(appStep).toHaveAttribute('href', '/records/data/project');
    await expect(
        breadcrumbs(page).locator('[data-slot="breadcrumb-page"]'),
    ).toHaveCount(1);
});

test('ランチャーは今のアプリのタイルだけに「現在のアプリ」の印(aria-current と読み上げの語)を付け、別のタイルを押すとサイドバーが替わる', async ({
    page,
}) => {
    await page.goto('/records/data/project');
    await openLauncher(page);

    const work = launcherTile(page, '業務');
    const workflow = launcherTile(page, 'ワークフロー');
    await expect(work).toHaveAttribute('aria-current', 'true');
    await expect(work).toContainText('(現在のアプリ)');
    await expect(workflow).not.toHaveAttribute('aria-current', 'true');
    await expect(workflow).not.toContainText('(現在のアプリ)');

    await workflow.click();
    await expect(page).toHaveURL('/workflow');
    await expect(sidebarHeading(page)).toHaveText('ワークフロー');
    await expect(breadcrumbs(page).getByRole('listitem').first()).toHaveText(
        'ワークフロー',
    );
});

test('サイドバーのアプリの見出し(h2)の下に、直下のフォルダが区画の見出し(h3)で並ぶ', async ({
    page,
}) => {
    await page.goto('/');
    await openApp(page, 'ワークフロー');

    // 区画の並び(docs の 設計/メニュー/表示.md 3.3.3)
    await expect(sidebar(page).getByRole('heading', { level: 2 })).toHaveText([
        'ワークフロー',
    ]);
    await expectSidebarSectionHeadings(page, [null, '申請と承認', '申請書']);
    await expect(
        sidebar(page)
            .locator('[data-sidebar="group"]')
            .first()
            .getByRole('link'),
    ).toHaveText(['ホーム']);
    await expect(sidebar(page).getByRole('heading', { level: 3 })).toHaveText([
        '申請と承認',
        '申請書',
    ]);
    await expect(
        sidebarSection(page, '申請と承認').getByRole('link'),
    ).toHaveText(['申請する', '申請一覧']);
});

test('リンクで別のアプリの画面へ移ると、サイドバーとパンくずのアプリの段が替わる', async ({
    page,
}) => {
    // E2E のアプリの画面(サイトマップ)から、サイトマップのリンクで業務・ワークフローの画面へ移る
    await page.goto('/menu/sitemap');
    await expect(sidebarHeading(page)).toHaveText('E2E の入れ子');
    await expect(breadcrumbs(page).getByRole('listitem').first()).toHaveText(
        'E2E の入れ子',
    );

    await sitemapGroup(page, '業務')
        .getByRole('link', { name: 'プロジェクト', exact: true })
        .click();
    await expect(page).toHaveURL('/records/data/project');
    await expect(sidebarHeading(page)).toHaveText('業務');
    await expect(breadcrumbs(page).getByRole('listitem').first()).toHaveText(
        '業務',
    );

    // 戻って、ワークフローの画面へ。サイドバーがワークフローになり、見出し「申請と承認」の区画が出る
    await page.goto('/menu/sitemap');
    await sitemapGroup(page, 'ワークフロー')
        .getByRole('link', { name: '申請一覧', exact: true })
        .click();
    await expect(page).toHaveURL('/workflow/cases');
    await expect(sidebarHeading(page)).toHaveText('ワークフロー');
    await expect(
        sidebarSection(page, '申請と承認').getByRole('link', {
            name: '申請一覧',
            exact: true,
        }),
    ).toHaveAttribute('aria-current', 'page');
});

test('ランチャーを開くと検索欄に焦点が移り、Esc は語があっても閉じてボタンへ焦点を戻し、外を押すと押した先に焦点が残る', async ({
    page,
}) => {
    await page.goto('/');
    await openLauncher(page);
    await expect(launcherSearch(page)).toBeFocused();

    await launcherSearch(page).fill('申請');
    await page.keyboard.press('Escape');
    await expect(launcherPanel(page)).toHaveCount(0);
    await expect(launcherButton(page)).toBeFocused();

    // 開き直すと、語は残っている(閉じるだけでは空にしない)
    await openLauncher(page);
    await expect(launcherSearch(page)).toHaveValue('申請');

    // 外(ヘッダーの検索欄)を押すと閉じ、焦点は押した先に残る
    await headerSearch(page).click();
    await expect(launcherPanel(page)).toHaveCount(0);
    await expect(headerSearch(page)).toBeFocused();
});

test('ランチャーの検索は、アプリと画面を群で出し、アプリの名前の一致で画面も出て、大文字と小文字を区別せず、前後の空白を除く', async ({
    page,
}) => {
    await page.goto('/');
    await openLauncher(page);

    // アプリの名前の一致: 群「アプリ」に業務、群「画面」に業務のアプリのすべての画面が、属するアプリの名前「業務」を添えて出る。
    // タイルの格子は検索の結果に替わる
    await launcherSearch(page).fill('業務');
    await expect(resultGroup(page, 'アプリ').getByRole('option')).toHaveText([
        /^業務/,
    ]);
    await expect(resultGroup(page, '画面').getByRole('option')).toHaveText(
        WORK_SCREENS.map(
            (name) => new RegExp(`^${escapeRegExp(name)}\\s*業務$`),
        ),
    );
    await expect(launcherTiles(page)).toHaveCount(0);

    // 画面の名前の一致: 群「アプリ」は出ない
    await launcherSearch(page).fill('申請一覧');
    await expect(resultGroup(page, '画面').getByRole('option')).toHaveText([
        /^申請一覧/,
    ]);
    await expect(resultGroup(page, 'アプリ')).toHaveCount(0);

    // 大文字と小文字の違い・前後の空白
    await launcherSearch(page).fill('  e2e の入れ子 ');
    await expect(resultGroup(page, 'アプリ').getByRole('option')).toHaveText([
        /^E2E の入れ子/,
    ]);

    // 0 件の文
    await launcherSearch(page).fill('存在しない語');
    await expect(
        launcherPanel(page).getByText('該当するアプリ・画面はありません', {
            exact: true,
        }),
    ).toBeVisible();

    // 空白だけの語は入れていない扱い(タイルに戻る)
    await launcherSearch(page).fill('   ');
    await expect(launcherTiles(page)).toHaveCount(3);
});

test('ランチャーの検索の結果は上下と Enter で選べ、移ると閉じて語が空になる', async ({
    page,
}) => {
    await page.goto('/');
    await openLauncher(page);

    // 「申請」を含む画面は、ワークフローの 申請する・申請一覧・申請書(群「アプリ」は出ない)。先頭が選ばれた状態から ↓ で 2 つ目(申請一覧)を選ぶ
    await launcherSearch(page).fill('申請');
    const first = resultGroup(page, '画面').getByRole('option').first();
    await expect(first).toContainText('申請する');
    await expect(first).toHaveAttribute('aria-selected', 'true');
    await launcherSearch(page).press('ArrowDown');
    await launcherSearch(page).press('Enter');

    await expect(page).toHaveURL('/workflow/cases');
    await expect(launcherPanel(page)).toHaveCount(0);
    await expect(sidebarHeading(page)).toHaveText('ワークフロー');

    await openLauncher(page);
    await expect(launcherSearch(page)).toHaveValue('');
});

test('ランチャーのタイルと下端のサイトマップは Tab で順に移れて、Enter で開く', async ({
    page,
}) => {
    await page.goto('/');
    await openLauncher(page);

    // 検索欄から Tab で、タイルを表示の順に 1 つずつ → 下端のサイトマップ(docs の 設計/画面の意匠/骨格とヘッダー.md 3 節の 7)
    const tiles = launcherTiles(page);
    const sitemapLink = launcherPanel(page).getByRole('link', {
        name: /^サイトマップ/,
    });
    await expect(tiles).toHaveText([/^ワークフロー/, /^E2E の入れ子/, /^業務/]);
    await expect(launcherSearch(page)).toBeFocused();
    await page.keyboard.press('Tab');

    for (let index = 0; index < (await tiles.count()); index++) {
        await expect(tiles.nth(index)).toBeFocused();
        await page.keyboard.press('Tab');
    }

    await expect(sitemapLink).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/menu/sitemap');
    await expect(launcherPanel(page)).toHaveCount(0);

    // タイルも Enter で開く(先頭のタイル = ワークフロー)
    await openLauncher(page);
    await expect(launcherSearch(page)).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(tiles.first()).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL('/workflow');
    await expect(launcherPanel(page)).toHaveCount(0);
});
