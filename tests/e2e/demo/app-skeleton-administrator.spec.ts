import { expect, test } from '../support/test';
import {
    breadcrumbs,
    adminMenuButton,
    launcherPanel,
    launcherSearch,
    launcherTiles,
    openApp,
    openFromAdminMenu,
    openAdminMenu,
    openLauncher,
    headerSearch,
    sidebar,
    sidebarHeading,
    sidebarLink,
} from '../support/app-navigation';
import { openAsAdministrator } from '../support/records-headings';
import { APPLICANT, signInAs } from './authz-editor-support';

// アプリの骨格(docs の 設計/メニュー/表示.md 3.3、3.3.7 の E2E)のうち、利用者の切替で権限の違う人になって確かめるもの。管理のアプリ(歯車のアプリ)は、
// 初期データではシステム管理者だけが見られ、歯車から開く(ランチャーのタイルにも、サイドバーのアプリの一覧にも、ランチャーの検索にも出ない)。
// DB には書かない。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

test('システム管理者: 全体のホームのサイドバーはアプリの一覧で管理を含まず、歯車のメニューに管理のアプリの木が出る', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');

    // ランチャーのアプリだけが一覧に並ぶ(管理は歯車から)
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(sidebar(page).getByRole('link')).toHaveText([
        'ワークフロー',
        'E2E の入れ子',
        '業務',
    ]);

    // 歯車のメニュー: 歯車のアプリの見出し「管理」の下に、管理のアプリのアイテムが並びの順に出る
    const menu = await openAdminMenu(page);
    await expect(menu.getByText('管理', { exact: true })).toBeVisible();
    await expect(menu.getByRole('menuitem')).toHaveText([
        '認可設定',
        '組織図',
        '業務モジュール',
        'モジュール分類',
        'フロー定義',
        '申請操作',
        '代理の管理',
    ]);
    await page.keyboard.press('Escape');
    await expect(menu).toHaveCount(0);

    // ランチャーのタイルにも管理は出ない(ワークフロー → E2E のアプリ → 業務の順)
    await openLauncher(page);
    await expect(launcherTiles(page)).toHaveText([
        /^ワークフロー/,
        /^E2E の入れ子/,
        /^業務/,
    ]);
});

test('システム管理者が歯車から管理の画面と組織図を開くと、サイドバーの先頭とパンくずのアプリの段が管理になる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');

    await openFromAdminMenu(page, '認可設定');
    await expect(page).toHaveURL('/authz/overview');
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(breadcrumbs(page).getByRole('listitem').first()).toHaveText(
        '管理',
    );
    await expect(sidebarLink(page, '認可設定')).toHaveAttribute(
        'aria-current',
        'page',
    );
    // 歯車のアプリが今のアプリのときは、ランチャーのどのタイルにも「現在のアプリ」の印が付かない
    await openLauncher(page);
    await expect(
        launcherTiles(page).and(page.locator('[aria-current]')),
    ).toHaveCount(0);
    await page.keyboard.press('Escape');
    await expect(launcherPanel(page)).toHaveCount(0);

    // 組織図(管理のアプリのアイテム)も歯車から開く
    await openFromAdminMenu(page, '組織図');
    await expect(page).toHaveURL('/master/organization-chart');
    await expect(sidebarHeading(page)).toHaveText('管理');
    await expect(sidebarLink(page, '組織図')).toHaveAttribute(
        'aria-current',
        'page',
    );
});

test('システム管理者が管理の画面からランチャーのタイルでほかのアプリへ移ると、サイドバーが替わる(歯車は残る)', async ({
    page,
}) => {
    await openAsAdministrator(page, '/authz/overview');
    await expect(sidebarHeading(page)).toHaveText('管理');

    await openApp(page, 'ワークフロー');
    await expect(page).toHaveURL('/workflow');
    await expect(breadcrumbs(page).getByRole('listitem').first()).toHaveText(
        'ワークフロー',
    );
    await expect(adminMenuButton(page)).toBeVisible();

    // 業務の先頭の画面(初期データの先頭のアイテム)へ移り、パンくずのアプリの段のリンクがその先頭の画面を指す
    await openApp(page, '業務');
    await expect(page).toHaveURL('/records/data/project');
    await expect(breadcrumbs(page).locator('a[href]').first()).toHaveAttribute(
        'href',
        '/records/data/project',
    );
});

test('システム管理者が業務の画面からヘッダーの検索の結果(組織)で組織図へ移ると、サイドバーが業務から管理に替わる', async ({
    page,
}) => {
    await openAsAdministrator(page, '/records/data/project');
    await expect(sidebarHeading(page)).toHaveText('業務');

    await headerSearch(page).click();
    await headerSearch(page).fill('human');
    await page
        .getByRole('listbox')
        .getByRole('group', { name: '組織' })
        .getByRole('option', { name: '人事部', exact: true })
        .click();

    await expect(page).toHaveURL(/\/master\/organization-chart\?department=/);
    await expect(sidebarHeading(page)).toHaveText('管理');
});

test('ランチャーの検索は、歯車のアプリ(管理)のアイテムを対象に入れない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');
    await openLauncher(page);

    for (const word of ['組織図', '認可設定', '代理の管理']) {
        await launcherSearch(page).fill(word);
        await expect(
            launcherPanel(page).getByText('該当するアプリ・画面はありません', {
                exact: true,
            }),
        ).toBeVisible();
    }
});

test('申請者(管理のアプリを見られない人)には歯車が無く、組織図の画面のサイドバーはアプリの一覧になる', async ({
    page,
}) => {
    await signInAs(page, APPLICANT);

    await expect(adminMenuButton(page)).toHaveCount(0);

    await page.goto('/master/organization-chart');
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(sidebarLink(page, '組織図')).toHaveCount(0);
    await expect(breadcrumbs(page).getByRole('listitem')).toHaveText([
        '組織図',
    ]);
});
