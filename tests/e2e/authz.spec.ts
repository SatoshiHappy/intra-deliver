import { expect, test } from './support/test';
import {
    adminMenuButton,
    launcherTiles,
    openLauncher,
    sidebarLink,
    sidebarHeading,
} from './support/app-navigation';

// 認可の確認(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節)のうち、ゲストだけで成り立つもの。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

test('ゲストには歯車もサイドバーの「管理」も無く、認可の画面は 403 になる', async ({
    page,
}) => {
    await page.goto('/');

    // 管理は歯車から開く。歯車のアプリはゲストに表示されないので歯車が無く、アプリの一覧(サイドバー・ランチャー)にも管理が無い
    await expect(adminMenuButton(page)).toHaveCount(0);
    await expect(sidebarHeading(page)).toHaveText('アプリ');
    await expect(sidebarLink(page, '管理')).toHaveCount(0);
    await openLauncher(page);
    await expect(launcherTiles(page).filter({ hasText: /^管理/ })).toHaveCount(
        0,
    );

    const response = await page.goto('/authz/overview');

    expect(response?.status()).toBe(403);
});
