import { expect, test } from './support/test';
import type { Page } from '@playwright/test';
import {
    expectSidebarSectionHeadings,
    headerSearch,
    sidebar,
    sidebarHeading,
    sidebarSection,
    sidebarToggle,
    sitemapGroup,
    untitledSections,
} from './support/app-navigation';

// 画面は PC の幅だけを前提にする(docs の 設計/メニュー/表示.md 3.2)。768px 以上で、グローバルナビが左のサイドバーに出る
// (設計/画面の意匠/骨格とヘッダー.md 2 節)。E2E 専用のアプリ(database/seeders/E2eMenuSeeder.php)は、サイトマップ(/menu/sitemap)を開くと
// 今のアプリになる(設計/メニュー/初期データ.md 4 節)ので、入れ子のフォルダの検査はそこで行う。
test.use({ viewport: { width: 1280, height: 720 } });

test('ヘッダーに製品名が出て、ゲストのホームのあいさつ欄に見出し・日付が出て、題とパンくずが合い、主な操作は出ない', async ({
    page,
}) => {
    await page.goto('/');

    // 製品名はヘッダーの印の横(あいさつ欄の見出しにも同じ語があるので、ヘッダーに絞る)
    await expect(
        page.getByRole('banner').getByText('イントラデリバー', { exact: true }),
    ).toBeVisible();
    // ゲストの見出し(h1 はページに 1 つ。docs の 設計/メニュー/ホーム.md 6.1)
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'ようこそ、イントラデリバーへ',
        }),
    ).toBeVisible();
    await expect(page).toHaveTitle('ホーム - イントラデリバー');
    await expect(
        page
            .getByRole('navigation', { name: 'パンくず' })
            .getByText('ホーム', { exact: true }),
    ).toBeVisible();
    // 日付は サーバーの基準日(2026年10月15日(木) の形)。日そのものは実行日で変わるので形だけ確かめる
    await expect(
        page
            .getByRole('region', { name: 'ごあいさつ' })
            .getByText(/^\d{4}年\d{1,2}月\d{1,2}日\([日月火水木金土]\)$/),
    ).toBeVisible();
    // ゲストにはワークフローの主な操作が無い
    await expect(
        page
            .getByRole('main')
            .getByRole('heading', { level: 2, name: '主な操作' }),
    ).toHaveCount(0);
});

test('ヘッダーの製品名の隣に、読み上げから外した印が出る', async ({ page }) => {
    await page.goto('/');
    const brand = page
        .getByRole('link')
        .filter({ has: page.getByText('イントラデリバー', { exact: true }) });

    await expect(brand.locator('svg[aria-hidden="true"]')).toBeVisible();
});

test('favicon と apple-touch-icon のリンク先が画像で返る', async ({
    request,
}) => {
    for (const path of [
        '/favicon.ico',
        '/favicon.svg',
        '/apple-touch-icon.png',
    ]) {
        const response = await request.get(path);

        expect(response.status(), path).toBe(200);
        expect(response.headers()['content-type'], path).toMatch(/^image\//);
    }
});

test('全体のホームのアプリのタイルは、ランチャーと同じ並び(業務が最後)で、押すとそのアプリの先頭の画面へ移る', async ({
    page,
}) => {
    await page.goto('/');
    const tiles = page
        .getByRole('main')
        .locator('section')
        .filter({
            has: page.getByRole('heading', {
                level: 2,
                name: 'アプリ',
                exact: true,
            }),
        })
        .getByRole('link');

    await expect(tiles).toHaveText([/^ワークフロー/, /^E2E の入れ子/, /^業務/]);
    await tiles.filter({ hasText: /^ワークフロー/ }).click();

    // ワークフローの先頭の画面はワークフローのホーム。サイドバーの先頭がそのアプリになる
    await expect(page).toHaveURL('/workflow');
    await expect(sidebarHeading(page)).toHaveText('ワークフロー');
});

test('開閉ボタンでサイドバーを畳むと、再読み込みの後も畳んだまま', async ({
    page,
}) => {
    await page.goto('/');

    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'true');
    await sidebarToggle(page).click();
    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'false');

    // クッキー sidebar_state → 共有プロパティ sidebarOpen → 最初の描画の開き具合
    await page.reload();
    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'false');
});

test('現在地を子孫に持つフォルダは開いて始まり、フォルダと子フォルダを開閉できる', async ({
    page,
}) => {
    // E2E のアプリはサイトマップが先頭の画面で、サイトマップを開くと今のアプリになる(サイドバーの先頭が E2E のアプリ)
    await page.goto('/menu/sitemap');
    await expect(sidebarHeading(page)).toHaveText('E2E の入れ子');
    // 直下のフォルダ「E2E 見出し」(h3)は区画の見出しで、開閉しない。その中のフォルダを開閉する
    const section = sidebarSection(page, 'E2E 見出し');
    const folder = section.getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
    const childFolder = section.getByRole('button', {
        name: 'E2E 子フォルダ',
        exact: true,
    });

    // /menu/sitemap の「E2E 項目」を子に持つフォルダは開き、/ の項目だけを持つ子フォルダは閉じている
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    await expect(
        section.getByRole('link', { name: 'E2E 項目', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(childFolder).toHaveAttribute('aria-expanded', 'false');
    await expect(
        section.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toBeHidden();

    await childFolder.click();
    await expect(childFolder).toHaveAttribute('aria-expanded', 'true');
    // 「/」を指す項目は今のアプリも現在地も決めないので、開いても印は付かない(docs の 設計/メニュー/表示.md 3.3.2)
    await expect(
        section.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toBeVisible();
    await expect(
        section.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).not.toHaveAttribute('aria-current', 'page');

    await folder.click();
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    await expect(
        section.getByRole('link', { name: 'E2E 項目', exact: true }),
    ).toBeHidden();
});

test('許可表に無いアイコン名の項目は、既定のアイコンで出て、見出しの区画の後に続く直下の項目は見出しなしの区画になる', async ({
    page,
}) => {
    await page.goto('/menu/sitemap');
    const unknown = untitledSections(page).getByRole('link', {
        name: 'E2E 不明アイコン',
        exact: true,
    });

    await expect(unknown.locator('svg.lucide-dot')).toHaveCount(1);
    // 区画の並び(docs の 設計/メニュー/表示.md 3.3.3)
    await expectSidebarSectionHeadings(page, ['E2E 見出し', null]);
    // 見出しの区画(E2E 見出し)の中には入らない
    await expect(
        sidebarSection(page, 'E2E 見出し').getByRole('link', {
            name: 'E2E 不明アイコン',
            exact: true,
        }),
    ).toHaveCount(0);
    // E2E 項目のアイコン
    await expect(
        sidebarSection(page, 'E2E 見出し')
            .getByRole('link', { name: 'E2E 項目', exact: true })
            .locator('svg.lucide-map'),
    ).toHaveCount(1);
});

/**
 * E2E のアプリの画面(サイトマップ)でサイドバーを畳み、帯の「E2E フォルダ」(アプリの直下のフォルダ「E2E 見出し」の中)を返す。
 */
async function collapseAndFindRailFolder(page: Page) {
    await page.goto('/menu/sitemap');
    await sidebarToggle(page).click();
    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'false');

    return sidebar(page).getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
}

/**
 * 畳んだ帯のフォルダのマウスオーバーのパネル(広げたサイドバーと同じ部品。役割 dialog、名前 = フォルダの表示名)。
 */
function railPanel(page: Page) {
    return page.getByRole('dialog', { name: 'E2E フォルダ' });
}

/**
 * 畳んだ帯のフォルダを押して開く一覧(DropdownMenu。役割 menu)。
 */
function railMenu(page: Page) {
    return page.getByRole('menu');
}

/**
 * フォーカスが一覧(menu)の中にある。
 */
async function expectFocusInMenu(page: Page) {
    await expect
        .poll(() =>
            page.evaluate(
                () => document.activeElement?.closest('[role="menu"]') !== null,
            ),
        )
        .toBe(true);
}

// 畳んだ帯のフォルダは、広げたサイドバーの閉じたフォルダと同じ 2 つの操作を持つ: 乗せる(マウス 300ms)と右にパネル(焦点を移さない)、
// 押すと右に一覧(DropdownMenu。Radix の既定のまま)。docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節。
// タッチの文脈(hasTouch)は作らない: 押す操作は Radix の既定のままで、マウスの押下と同じ経路(pointerdown)に乗るので ② で代える。
test('① 畳んだ帯でフォルダにマウスを乗せると右のパネルが出て、フォーカスを動かさず、外れると閉じる', async ({
    page,
}) => {
    const folder = await collapseAndFindRailFolder(page);
    await headerSearch(page).focus();
    await expect(headerSearch(page)).toBeFocused();

    await folder.hover();
    const panel = railPanel(page);
    await expect(panel).toBeVisible();
    const item = panel.getByRole('link', { name: 'E2E 項目', exact: true });
    await expect(item).toBeVisible();
    await expect(headerSearch(page)).toBeFocused();
    // パネルは補助で、押す操作の一覧は開かない
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    await expect(railMenu(page)).toHaveCount(0);

    // 行からパネルへ動く間も、パネルの中に乗っている間も閉じない(閉じるなら閉じ終わる時間だけ待つ)
    await item.hover();
    await page.waitForTimeout(500);
    await expect(panel).toBeVisible();
    await expect(headerSearch(page)).toBeFocused();

    // 行とパネルの両方から外れると閉じる
    await page.mouse.move(900, 600);
    await expect(panel).toHaveCount(0);
    await expect(headerSearch(page)).toBeFocused();
});

test('② 畳んだ帯でフォルダを押すと右に一覧が開き、もう一度押すと閉じる', async ({
    page,
}) => {
    const folder = await collapseAndFindRailFolder(page);

    await folder.click();
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    const menu = railMenu(page);
    await expect(menu.getByText('E2E フォルダ', { exact: true })).toBeVisible();
    await expect(
        menu.getByRole('menuitem', { name: 'E2E 項目', exact: true }),
    ).toBeVisible();
    await expect(
        menu.getByRole('menuitem', { name: 'E2E 子フォルダ', exact: true }),
    ).toBeVisible();

    await folder.click();
    await expect(menu).toHaveCount(0);
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    // 押して閉じた後に、乗せたままでもパネルは出ない(否定の確かめなので、出るなら出る時間だけ待つ)
    await page.waitForTimeout(500);
    await expect(railPanel(page)).toHaveCount(0);
});

test('③ 畳んだ帯でフォルダにフォーカスして Enter で開くと一覧の中へフォーカスが移り、Escape で閉じると引き金へ戻る', async ({
    page,
}) => {
    const folder = await collapseAndFindRailFolder(page);

    await folder.focus();
    await page.keyboard.press('Enter');
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    await expect(railMenu(page)).toBeVisible();
    await expectFocusInMenu(page);

    await page.keyboard.press('Escape');
    await expect(railMenu(page)).toHaveCount(0);
    await expect(folder).toBeFocused();
});

test('④ 畳んだ帯でパネルが出ている間にフォルダを押すと、パネルが閉じて一覧が開く', async ({
    page,
}) => {
    const folder = await collapseAndFindRailFolder(page);

    await folder.hover();
    await expect(railPanel(page)).toBeVisible();

    await folder.click();
    await expect(railPanel(page)).toHaveCount(0);
    await expect(railMenu(page)).toBeVisible();
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
});

test('⑤ 畳んだ帯で一覧の外を押して閉じた後も、Enter で開いて Escape で閉じると引き金へ戻る', async ({
    page,
}) => {
    const folder = await collapseAndFindRailFolder(page);

    await folder.click();
    await expect(railMenu(page)).toBeVisible();
    await page.mouse.click(900, 600);
    await expect(railMenu(page)).toHaveCount(0);

    await folder.focus();
    await page.keyboard.press('Enter');
    await expect(railMenu(page)).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(railMenu(page)).toHaveCount(0);
    await expect(folder).toBeFocused();
});

test('⑥ 畳んだ帯でフォルダの一覧が開いている間は、帯のフォルダに乗せてもパネルが出ない', async ({
    page,
}) => {
    // E2E の木には帯の直下のフォルダが 1 つしか無いので、同じフォルダに乗せ直す(出さない条件は帯のどれかの一覧が開いていること = 帯の全体で 1 つ)
    const folder = await collapseAndFindRailFolder(page);

    await folder.click();
    await expect(railMenu(page)).toBeVisible();

    await page.mouse.move(900, 600);
    await folder.hover();
    // 否定の確かめなので、出るなら出る時間だけ待つ
    await page.waitForTimeout(500);
    await expect(railPanel(page)).toHaveCount(0);
    await expect(railMenu(page)).toBeVisible();
});

test('広げたサイドバーで閉じたフォルダにマウスを乗せると右のパネルに中身が出て、子のフォルダは見出しと字下げで出る。押して開いたフォルダでは出ない', async ({
    page,
}) => {
    // 現在地を子孫に持つフォルダは開いて始まるので、押して閉じてから乗せる。パネルは docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節
    await page.goto('/menu/sitemap');
    const folder = sidebarSection(page, 'E2E 見出し').getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    await folder.click();
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    await page.mouse.move(900, 600);

    await folder.hover();
    const panel = page.getByRole('dialog', { name: 'E2E フォルダ' });
    await expect(panel).toBeVisible();
    await expect(
        panel.getByRole('link', { name: 'E2E 項目', exact: true }),
    ).toBeVisible();
    // 子のフォルダはサイトマップのカードと同じ描き方(見出しと字下げした中身)
    await expect(
        panel.getByText('E2E 子フォルダ', { exact: true }),
    ).toBeVisible();
    await expect(
        panel.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toBeVisible();

    await folder.click();
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    await expect(panel).toHaveCount(0);

    // 開いたフォルダに乗せ直しても出ない(否定の確かめなので、開くなら開き終わる時間だけ待つ)
    await page.mouse.move(0, 0);
    await folder.hover();
    await page.waitForTimeout(500);
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('サイトマップにアプリの見出しとリンクが出る(管理のアプリはゲストに出ない)', async ({
    page,
}) => {
    await page.goto('/menu/sitemap');
    await expect(
        sitemapGroup(page, 'ワークフロー').getByRole('link', {
            name: 'ホーム',
            exact: true,
        }),
    ).toHaveAttribute('href', '/workflow');
    await expect(
        sitemapGroup(page, 'E2E の入れ子').getByRole('link', {
            name: 'E2E 項目',
            exact: true,
        }),
    ).toHaveAttribute('href', '/menu/sitemap');
    await expect(
        sitemapGroup(page, '業務').getByRole('link', {
            name: '組織図',
            exact: true,
        }),
    ).toHaveCount(0);
    await expect(sitemapGroup(page, '管理')).toHaveCount(0);
    await expect(page).toHaveTitle('サイトマップ - イントラデリバー');
});
