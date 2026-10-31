import { expect, test } from '../support/test';
import {
    breadcrumbs,
    launcherTiles,
    openAdminMenu,
    openLauncher,
} from '../support/app-navigation';
import { openAsAdministrator } from '../support/records-headings';

// ワークフローのホームと全体のホーム(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 9 節の demo、設計/メニュー/ホーム.md 6.6・6.7)の
// うち、初期データの上で読むだけのもの。デモユーザー切替で利用者を替える。DB には書かない。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

test('システム管理者のワークフローのホームは、題が「ワークフロー - イントラデリバー」、パンくずが「ワークフロー > ホーム」で日付が出て、全体のホームにはワークフローの節と主な操作が出ない', async ({
    page,
}) => {
    await openAsAdministrator(page, '/workflow');

    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        'ワークフロー',
    );
    await expect(page).toHaveTitle('ワークフロー - イントラデリバー');
    await expect(breadcrumbs(page).getByRole('listitem')).toHaveText([
        'ワークフロー',
        'ホーム',
    ]);
    await expect(
        page
            .getByRole('region', { name: 'ごあいさつ' })
            .getByText(/^\d{4}年\d{1,2}月\d{1,2}日\([日月火水木金土]\)$/),
    ).toBeVisible();

    // 全体のホーム(/)の h1 は利用者のあいさつで、ワークフローの節と主な操作は無い
    await page.goto('/');
    await expect(
        page.getByRole('heading', { level: 1, name: /^こんにちは、/ }),
    ).toBeVisible();
    await expect(page.getByRole('region', { name: '件数' })).toHaveCount(0);

    for (const heading of [
        '未処理の申請',
        '今月の処理済み',
        '進行中の申請',
        '主な操作',
    ]) {
        await expect(
            page
                .getByRole('main')
                .getByRole('heading', { level: 2, name: heading }),
        ).toHaveCount(0);
    }
});

test('システム管理者: ランチャーの先頭は「ワークフロー」で、管理はランチャーに無く歯車から開ける', async ({
    page,
}) => {
    await openAsAdministrator(page, '/');

    // ランチャーのタイルの並びはワークフロー → (E2E のアプリ)→ 業務。管理のアプリはタイルに出ない
    await openLauncher(page);
    await expect(launcherTiles(page).first()).toHaveText(/^ワークフロー/);
    await expect(launcherTiles(page).last()).toHaveText(/^業務/);
    await expect(launcherTiles(page).filter({ hasText: /^管理/ })).toHaveCount(
        0,
    );
    await page.keyboard.press('Escape');

    // 歯車がある。開くと管理のアプリのアイテムが並ぶ
    const menu = await openAdminMenu(page);
    await expect(
        menu.getByRole('menuitem', { name: '申請操作', exact: true }),
    ).toBeVisible();
});
