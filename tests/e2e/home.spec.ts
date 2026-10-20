import { expect, test } from './support/test';
import type { Page } from '@playwright/test';

// 画面は PC の幅だけを前提にする(docs の 設計/メニュー/表示.md 3.2)。768px 以上で、グローバルナビが左のサイドバーに出る
// (docs の 設計/画面の意匠/骨格とヘッダー.md 2 節)。ナビの中は、E2E 専用のグループ(database/seeders/E2eMenuSeeder.php)が増えても
// 通るよう、見出しでグループを絞ってから引く(docs の 設計/画面の意匠/検査と否決案.md 9 節)。
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

/**
 * ヘッダーのサイドバーの開閉ボタン。
 */
function sidebarToggle(page: Page) {
    return page.getByRole('button', { name: 'メニューの開閉', exact: true });
}

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

test('サイドバーのグローバルナビに見出しと現在地のリンクが出て、サイトマップへ移れる', async ({
    page,
}) => {
    await page.goto('/');
    const group = navigationGroup(page, '業務');

    await expect(
        group.getByRole('heading', { level: 2, name: '業務', exact: true }),
    ).toBeVisible();
    await expect(
        group.getByRole('link', { name: 'ホーム', exact: true }),
    ).toHaveAttribute('aria-current', 'page');

    await group
        .getByRole('link', { name: 'サイトマップ', exact: true })
        .click();

    await expect(page).toHaveURL('/menu/sitemap');
    await expect(
        page.getByRole('heading', { level: 1, name: 'サイトマップ' }),
    ).toBeVisible();
});

test('グローバルナビの項目に、初期データのアイコンが出る', async ({ page }) => {
    await page.goto('/');
    const group = navigationGroup(page, '業務');

    // 初期データの icon_32 → 共有プロパティの木 → MenuIcon の許可表(lucide-react は各アイコンに lucide-<名前> の class を付ける)
    await expect(
        group
            .getByRole('link', { name: 'ホーム', exact: true })
            .locator('svg.lucide-house'),
    ).toHaveCount(1);
    await expect(
        group
            .getByRole('link', { name: 'サイトマップ', exact: true })
            .locator('svg.lucide-map'),
    ).toHaveCount(1);
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
    await page.goto('/menu/sitemap');
    const group = navigationGroup(page, 'E2E の入れ子');
    const folder = group.getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
    const childFolder = group.getByRole('button', {
        name: 'E2E 子フォルダ',
        exact: true,
    });

    // /menu/sitemap の「E2E 項目」を子に持つフォルダは開き、/ の項目だけを持つ子フォルダは閉じている
    await expect(folder).toHaveAttribute('aria-expanded', 'true');
    await expect(
        group.getByRole('link', { name: 'E2E 項目', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(childFolder).toHaveAttribute('aria-expanded', 'false');
    await expect(
        group.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toBeHidden();

    await childFolder.click();
    await expect(childFolder).toHaveAttribute('aria-expanded', 'true');
    await expect(
        group.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toBeVisible();

    await folder.click();
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    await expect(
        group.getByRole('link', { name: 'E2E 項目', exact: true }),
    ).toBeHidden();
});

test('現在地が子フォルダの中にあれば、フォルダと子フォルダが開いて始まる', async ({
    page,
}) => {
    await page.goto('/');
    const group = navigationGroup(page, 'E2E の入れ子');

    await expect(
        group.getByRole('button', { name: 'E2E フォルダ', exact: true }),
    ).toHaveAttribute('aria-expanded', 'true');
    await expect(
        group.getByRole('button', { name: 'E2E 子フォルダ', exact: true }),
    ).toHaveAttribute('aria-expanded', 'true');
    await expect(
        group.getByRole('link', { name: 'E2E 子項目', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
});

test('許可表に無いアイコン名の項目は、既定のアイコンで出る', async ({
    page,
}) => {
    await page.goto('/');

    await expect(
        navigationGroup(page, 'E2E の入れ子')
            .getByRole('link', { name: 'E2E 不明アイコン', exact: true })
            .locator('svg.lucide-dot'),
    ).toHaveCount(1);
});

/**
 * サイドバーを畳み、帯の「E2E フォルダ」(グループ「E2E の入れ子」の直下)を返す。
 */
async function collapseAndFindRailFolder(page: Page) {
    await page.goto('/');
    await sidebarToggle(page).click();
    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'false');

    return navigationGroup(page, 'E2E の入れ子').getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
}

/**
 * ヘッダーの検索欄(フォーカスが動かないことの確かめに使う。フォーカスだけでは結果の Popover は開かない)。
 */
function searchBox(page: Page) {
    return page.getByRole('combobox', { name: 'ユーザー・組織を検索' });
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
    await searchBox(page).focus();
    await expect(searchBox(page)).toBeFocused();

    await folder.hover();
    const panel = railPanel(page);
    await expect(panel).toBeVisible();
    const item = panel.getByRole('link', { name: 'E2E 項目', exact: true });
    await expect(item).toBeVisible();
    await expect(searchBox(page)).toBeFocused();
    // パネルは補助で、押す操作の一覧は開かない
    await expect(folder).toHaveAttribute('aria-expanded', 'false');
    await expect(railMenu(page)).toHaveCount(0);

    // 行からパネルへ動く間も、パネルの中に乗っている間も閉じない(閉じるなら閉じ終わる時間だけ待つ)
    await item.hover();
    await page.waitForTimeout(500);
    await expect(panel).toBeVisible();
    await expect(searchBox(page)).toBeFocused();

    // 行とパネルの両方から外れると閉じる
    await page.mouse.move(900, 600);
    await expect(panel).toHaveCount(0);
    await expect(searchBox(page)).toBeFocused();
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
    // 「E2E の入れ子」に現在地を持たない画面(フォルダは閉じて始まる)。パネルは docs の 設計/画面の意匠/サイドバーとアイコン.md 4 節
    await page.goto('/master/organization-chart');
    const folder = navigationGroup(page, 'E2E の入れ子').getByRole('button', {
        name: 'E2E フォルダ',
        exact: true,
    });
    await expect(folder).toHaveAttribute('aria-expanded', 'false');

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

test('サイトマップにグループの見出しとリンクが出る', async ({ page }) => {
    await page.goto('/menu/sitemap');
    const group = page
        .getByRole('main')
        .locator('section')
        .filter({
            has: page.getByRole('heading', {
                level: 2,
                name: '業務',
                exact: true,
            }),
        });

    await expect(
        group.getByRole('link', { name: 'ホーム', exact: true }),
    ).toHaveAttribute('href', '/');
    await expect(
        group.getByRole('link', { name: '組織図', exact: true }),
    ).toHaveAttribute('href', '/master/organization-chart');
    await expect(
        group.getByRole('link', { name: 'サイトマップ', exact: true }),
    ).toHaveAttribute('href', '/menu/sitemap');
    await expect(page).toHaveTitle('サイトマップ - イントラデリバー');
});
