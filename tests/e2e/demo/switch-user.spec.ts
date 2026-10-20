import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// デモユーザー切替の E2E(docs の 設計/デモユーザー切替/README.md 2.2、デモの取り付け.md 6.4)。scripts/ci/front がオンで起動し、
// DatabaseSeeder の後に DemoSeeder を流したテスト用 DB に掛ける。画面は PC の幅だけを前提にする(docs の 設計/メニュー/表示.md 3.2)。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 利用者メニューの押す前の表示。accessible name の完全一致で、氏名と、あればカレント組織を確かめる。
 */
function userMenu(page: Page, label: string) {
    return page.getByRole('button', {
        name: `利用者メニュー：${label}`,
        exact: true,
    });
}

/**
 * 利用者メニューを開き、項目を選ぶ(送信)。url があれば、移り先の URL まで待つ。
 */
async function choose(page: Page, label: string, item: string, url?: string) {
    await userMenu(page, label).click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: item }),
        url,
    );
}

test('ゲストから申請者に切り替え、兼務の利用者でカレント組織を切り替えて、ログアウトする', async ({
    page,
}) => {
    await page.goto('/');

    await userMenu(page, 'ゲスト').click();
    await expect(page.getByText('利用者の切替', { exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    // 閉じるアニメーションの間はメニューが DOM に残り、その間に引き金を押すと開く操作と外側押下の閉じる操作が重なって閉じたままになる。
    // 閉じ終わるのを待ってから開き直す。
    await expect(page.getByRole('menu')).toBeHidden();

    await choose(page, 'ゲスト', '申請者', '/');
    await expect(userMenu(page, '佐々木 陽太（東京営業課）')).toBeVisible();

    await choose(page, '佐々木 陽太（東京営業課）', '兼務(総務・人事)');
    await expect(userMenu(page, '吉田 達也（総務部）')).toBeVisible();

    await userMenu(page, '吉田 達也（総務部）').click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menuitemradio').filter({ hasText: '人事部' }),
    );
    await expect(userMenu(page, '吉田 達也（人事部）')).toBeVisible();

    await choose(page, '吉田 達也（人事部）', 'ログアウト');
    await expect(userMenu(page, 'ゲスト')).toBeVisible();
});

// ゲストのホーム(docs の 設計/デモユーザー切替/デモの取り付け.md 6.4、設計/メニュー/ホーム.md 6.3): 役割カードが候補の数(config/demo.php の 6 人)だけ出る。
// ボタンの名前は、項目の名前を読み上げ用に添えた「この人で始める(<氏名>)」(区切りの空白は描画で変わるので \s* で受ける)。
test('ゲストのホームに役割カードが候補の数だけ出て、主ボタンで役割カードへ移り、「この人で始める」で切り替わる', async ({
    page,
}) => {
    await page.goto('/');

    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'ようこそ、イントラデリバーへ',
        }),
    ).toBeVisible();
    await expect(
        page.getByText(
            '申請 → 課長 → 部長の承認の流れを、利用者を切り替えながら試せます。',
            { exact: true },
        ),
    ).toBeVisible();

    const people = page.getByRole('region', { name: '試せる利用者' });
    await expect(
        people.getByRole('button', { name: /^この人で始める/ }),
    ).toHaveCount(6);
    await expect(
        people.getByText('この人で試せること', { exact: true }),
    ).toHaveCount(6);

    await page
        .getByRole('link', { name: '利用者を選択して試す', exact: true })
        .click();
    await expect(page).toHaveURL(/#demo-people$/);
    await expect(people).toBeInViewport();

    await submitAndExpectUrl(
        page,
        people.getByRole('button', {
            name: /^この人で始める\s*\(佐々木 陽太\)$/,
        }),
        '/',
    );
    await expect(userMenu(page, '佐々木 陽太（東京営業課）')).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: /^こんにちは、/ }),
    ).toBeVisible();
    // 切り替えた後は役割カードも一文も出ない(役割カードを出すのはゲストだけ)
    await expect(people).toHaveCount(0);
});

// CSRF(docs の 設計/デモユーザー切替/今の利用者.md 5.8): ブラウザのセッションの cookie を持っていても、トークンのヘッダーも
// 同じオリジンの印(Sec-Fetch-Site)も無い POST は拒まれ(419)、利用者は変わらない。page.request はブラウザの cookie を共有し、
// Sec-Fetch-Site と X-XSRF-TOKEN を付けない。419 は入力を見る前に決まるので、user_id の値は任意(候補の ID である必要は無い)。
test('トークンもオリジンも合わない切替の POST は拒まれ、利用者は変わらない', async ({
    page,
}) => {
    await page.goto('/');

    const response = await page.request.post('/demo/switch-user', {
        form: { user_id: '1' },
        maxRedirects: 0,
    });

    expect(response.status()).toBe(419);

    await page.reload();
    await expect(userMenu(page, 'ゲスト')).toBeVisible();
});
