import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// 画面内通知の鈴と一覧(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.5)。認証済みの利用者になるのに利用者の切替を使うので、
// 切替の持ち物の置き場に置く(docs の 設計/デモユーザー切替/デモの取り付け.md 6.1)。
// 通知を送る機能が入っても落ちないよう、利用者の通知の件数に依らない確かめにする。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * ゲストから、利用者メニューで申請者に切り替える。
 */
async function switchToApplicant(page: Page) {
    await page.goto('/');
    await page
        .getByRole('button', { name: '利用者メニュー：ゲスト', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('menu')
            .getByRole('menuitem')
            .filter({ hasText: '申請者' }),
        '/',
    );
}

test('ゲストには鈴が出ない', async ({ page }) => {
    await page.goto('/');

    await expect(
        page.getByRole('button', {
            name: '利用者メニュー：ゲスト',
            exact: true,
        }),
    ).toBeVisible();
    await expect(page.getByRole('button', { name: /^通知/ })).toHaveCount(0);
});

test('鈴を開いて最新の通知を取得し、通知の一覧へ移る', async ({ page }) => {
    await switchToApplicant(page);

    // 通知の件数は初期データに依らないよう、鈴の名前は先頭一致で引き、件数を決めて確かめない。
    await page.getByRole('button', { name: /^通知/ }).click();
    const popover = page.locator('[data-slot="popover-content"]');
    await expect(
        popover.getByRole('heading', { name: '通知', exact: true }),
    ).toBeVisible();
    // 取得が済む(仮の行が消える)のを待ち、失敗の 1 行が出ず、行か「通知はありません」のどちらかが出ることで、口の認可と取得を通ったことを確かめる。
    await expect(popover.locator('[data-slot="skeleton"]')).toHaveCount(0);
    await expect(
        popover.getByText('通知を取得できませんでした', { exact: true }),
    ).toHaveCount(0);
    await expect(
        popover
            .getByRole('listitem')
            .or(popover.getByText('通知はありません', { exact: true }))
            .first(),
    ).toBeVisible();

    await popover
        .getByRole('link', { name: 'すべて見る', exact: true })
        .click();
    await expect(page).toHaveURL(/\/notices$/);
    await expect(
        page.getByRole('heading', { level: 1, name: '通知', exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('あなた宛の通知を、新しい順に表示します。', {
            exact: true,
        }),
    ).toBeVisible();
});

test('通知の一覧ですべて既読にして、未読の 0 件を見る', async ({ page }) => {
    await switchToApplicant(page);
    await page.goto('/notices');

    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: 'すべて既読にする', exact: true }),
        /\/notices$/,
    );
    await expect(
        page.getByText('すべて既読にしました', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: /^未読\s*0$/ })).toBeVisible();

    await page.getByRole('link', { name: /^未読\s*0$/ }).click();
    await expect(page).toHaveURL(/filter=unread/);
    await expect(
        page.getByRole('cell', { name: '未読の通知はありません', exact: true }),
    ).toBeVisible();
});
