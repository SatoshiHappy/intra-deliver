import { expect, test } from './support/test';

// 画面は PC の幅だけを前提にする(docs の 設計/メニュー/表示.md 3.2)。
test.use({ viewport: { width: 1280, height: 720 } });

// main だけでは画面からログインできないので、ゲストの表示だけを確かめる(docs の 設計/デモユーザー切替/今の利用者.md 5.9)。
// 一括検査の設定のオン・オフ(README 2.2)のどちらでも成り立つ。押せるかどうかは確かめない(5.9)。
// 利用者メニューの節があれば開くメニュー(button)になり、開いて「ログアウト」が無いことを確かめる。節が無ければ押せない帯で、開かない。
test('ゲストでは利用者メニューに「ゲスト」が出て、「ログアウト」と「ワークフロー」の節が無い', async ({
    page,
}) => {
    await page.goto('/');

    // 描画を待つ(帯でも button でも見える)。下の count() は待たないので、この後に呼ぶ
    await expect(page.getByText('ゲスト', { exact: true })).toBeVisible();

    // accessible name の完全一致が「ゲストだけ」(カレント組織が無い)の確かめを兼ねる
    const userMenu = page.getByRole('button', {
        name: '利用者メニュー：ゲスト',
        exact: true,
    });

    if ((await userMenu.count()) === 1) {
        await userMenu.click();
        await expect(page.getByRole('menu')).toBeVisible();
        await expect(
            page.getByRole('menuitem', { name: 'ログアウト' }),
        ).toHaveCount(0);
        // ワークフローの節は項目を持たない(ゲストには出さない)ので、見出しも無い
        await expect(
            page.getByRole('menu').getByText('ワークフロー', { exact: true }),
        ).toHaveCount(0);
        await page.keyboard.press('Escape');
    }
});

// ゲストは代理を追加できない(押しても 403 になる)ので、代理の設定に主ボタンを出さず、2 つの表は 0 件の文を出す
test('ゲストの代理の設定には「代理を追加」が無く、2 つの表は 0 件の文を出す', async ({
    page,
}) => {
    await page.goto('/workflow/proxies');

    await expect(
        page.getByRole('heading', { level: 1, name: '代理の設定' }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: '代理を追加' })).toHaveCount(
        0,
    );
    await expect(
        page.getByRole('cell', { name: '代理先はありません', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByRole('cell', { name: '代理元はありません', exact: true }),
    ).toBeVisible();
});
