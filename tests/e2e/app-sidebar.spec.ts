import { expect, test } from './support/test';
import {
    breadcrumbs,
    adminMenuButton,
    sidebarLink,
    sidebarToggle,
    launcherPanel,
    openLauncher,
    sidebar,
    sidebarHeading,
} from './support/app-navigation';

// サイドバーの骨格(docs の 設計/メニュー/表示.md 3.3.3、設計/画面の意匠/サイドバーとアイコン.md 4 節)のうち、ゲストだけで成り立つもの。
// 今のアプリが無い画面(全体のホーム)はアプリの一覧、今のアプリがある画面は先頭にアプリの見出し(h2)と区画。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

test('全体のホームのサイドバーはアプリの一覧で、歯車のアプリと現在地の印が無く、パンくずにアプリの段が無い', async ({
    page,
}) => {
    await page.goto('/');

    // 見出し「アプリ」(h2)の下に、ランチャーのアプリがアイコンと名前のリンクで並ぶ(並びは業務が最後。E2E の環境では E2E のアプリが業務の前)
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(sidebar(page).getByRole('link')).toHaveText([
        'ワークフロー',
        'E2E の入れ子',
        '業務',
    ]);
    await expect(
        sidebarLink(page, 'ワークフロー').locator('svg.lucide-send'),
    ).toHaveCount(1);
    await expect(
        sidebarLink(page, '業務').locator('svg.lucide-briefcase'),
    ).toHaveCount(1);
    // 区画の見出し(h3)は今のアプリの木のもので、一覧には無い。今のアプリが無いので現在地の印は無い
    await expect(sidebar(page).getByRole('heading', { level: 3 })).toHaveCount(
        0,
    );
    await expect(sidebar(page).locator('[aria-current]')).toHaveCount(0);
    // 歯車のアプリ(管理)は一覧に出ず、ゲストには歯車も無い
    await expect(sidebarLink(page, '管理')).toHaveCount(0);
    await expect(adminMenuButton(page)).toHaveCount(0);
    // パンくずはページの段だけ(アプリの段が無い)
    await expect(breadcrumbs(page).getByRole('listitem')).toHaveText([
        'ホーム',
    ]);
});

test('ランチャーの下端の「サイトマップ」を押すとサイトマップへ移り、パネルが閉じる', async ({
    page,
}) => {
    await page.goto('/');
    const panel = await openLauncher(page);

    await panel.getByRole('link', { name: /^サイトマップ/ }).click();

    await expect(page).toHaveURL('/menu/sitemap');
    await expect(
        page.getByRole('heading', { level: 1, name: 'サイトマップ' }),
    ).toBeVisible();
    await expect(launcherPanel(page)).toHaveCount(0);
});

test('ワークフローのアプリのサイドバーの項目に、初期データのアイコンが出る', async ({
    page,
}) => {
    await page.goto('/workflow');

    // 初期データの icon_32 → 共有プロパティの木 → MenuIcon の許可表(lucide-react は各アイコンに lucide-<名前> の class を付ける)
    for (const [name, icon] of [
        ['ホーム', 'house'],
        ['申請する', 'send'],
        ['申請一覧', 'inbox'],
    ]) {
        await expect(
            sidebar(page)
                .getByRole('link', { name, exact: true })
                .locator(`svg.lucide-${icon}`),
        ).toHaveCount(1);
    }
});

test('畳んだ帯の先頭は今のアプリのアイコンで、アプリの名前の文字は見た目から隠れ、読み上げの名前がアプリの名前', async ({
    page,
}) => {
    await page.goto('/workflow');
    const heading = sidebarHeading(page);
    const width = () =>
        heading.evaluate((element) => element.getBoundingClientRect().width);

    // 広げたときは名前の文字が見える(幅が 1px を超える)。先頭のアイコンはアプリのアイコン(ワークフロー = send)
    await expect.poll(width).toBeGreaterThan(1);
    await expect(sidebar(page).locator('svg').first()).toHaveClass(
        /lucide-send/,
    );

    await sidebarToggle(page).click();
    await expect(sidebarToggle(page)).toHaveAttribute('aria-expanded', 'false');

    // 帯では h2 を読み上げ用だけにする(sr-only = 幅 1px。Playwright は可視と見なすので幅で見る)。名前は残り、先頭のアイコンも残る
    await expect.poll(width).toBeLessThanOrEqual(1);
    await expect(heading).toBeVisible();
    await expect(heading).toHaveText('ワークフロー');
    await expect(sidebar(page).locator('svg').first()).toBeVisible();
    await expect(sidebar(page).locator('svg').first()).toHaveClass(
        /lucide-send/,
    );
});
