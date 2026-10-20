import { expect, test } from './support/test';

// 認可の確認(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節)のうち、ゲストだけで成り立つもの。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

test('ゲストのサイドバーには「管理」が無く、認可の画面は 403 になる', async ({
    page,
}) => {
    await page.goto('/');
    const navigation = page.getByRole('navigation', { name: 'グローバルナビ' });

    await expect(
        navigation.getByRole('heading', {
            level: 2,
            name: '業務',
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        navigation.getByRole('heading', {
            level: 2,
            name: '管理',
            exact: true,
        }),
    ).toHaveCount(0);

    const response = await page.goto('/authz/overview');

    expect(response?.status()).toBe(403);
});
