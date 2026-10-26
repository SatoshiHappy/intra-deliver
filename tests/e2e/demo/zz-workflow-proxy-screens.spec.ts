import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { shownDate, todayInTokyo } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';
import {
    ADD_DIALOG,
    deleteRow,
    pickUser,
    shown,
    submitAdd,
} from '../support/workflow-proxies';
import { openAs } from '../support/workflow-operations';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、代理の設定と鈴の「代理」の行。
// 初期データの見本(設計/ワークフロー/案件の運用/初期データ.md 4.1 の代理)を使い、追加した代理は筋の中で消す。
// 追加した代理先の通知は残るが、同じ組の spec は通知の件数を前提にしない(文で探す)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/**
 * u0003 の利用者メニューの「ワークフロー」の節から「代理の設定」を開く。
 */
async function openProxySettings(page: Page) {
    await openAs(page, 'manager', '/');
    await page
        .getByRole('button', {
            name: '利用者メニュー：高橋 誠（第一営業部）',
            exact: true,
        })
        .click();
    const menu = page.getByRole('menu');
    await expect(menu.getByText('ワークフロー', { exact: true })).toBeVisible();
    await menu
        .getByRole('menuitem', { name: '代理の設定', exact: true })
        .click();
    await expect(page).toHaveURL(/\/workflow\/proxies$/);
    await expect(
        page.getByRole('heading', { level: 1, name: '代理の設定' }),
    ).toBeVisible();
}

/**
 * u0003 の見本: 自分の代理先 = u0009(承認・すべてのフロー・D − 2 から終了日なし)、自分の代理元 = u0016(承認・プロジェクト計画の承認・D − 7 から D + 3 まで。操作の列なし)。
 */
async function expectSeededRows(page: Page) {
    const given = page
        .getByRole('table', { name: '自分の代理先' })
        .getByRole('row')
        .filter({ hasText: '吉田 達也' });
    await expect(given).toContainText('承認');
    await expect(given).toContainText('すべてのフロー');
    await expect(given).toContainText(
        `${shownDate('2026-10-13')} から 終了日なし`,
    );
    await expect(given).toContainText('有効');
    const received = page
        .getByRole('table', { name: '自分の代理元' })
        .getByRole('row')
        .filter({ hasText: '清水 美穂' });
    await expect(received).toContainText('プロジェクト計画の承認');
    await expect(received).toContainText(
        `${shownDate('2026-10-08')} から ${shownDate('2026-10-18')}`,
    );
    await expect(received.getByRole('button', { name: '削除' })).toHaveCount(0);
}

test('u0003 の代理の設定: 利用者メニューから開き、見本の代理を見て、u0001 を承認の代理先に足して消す', async ({
    page,
}) => {
    await openProxySettings(page);
    await expectSeededRows(page);

    await page.getByRole('button', { name: '代理を追加', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: ADD_DIALOG });
    await pickUser(page, dialog, '代理先', '佐々木 陽太');
    await expect(dialog).toContainText(
        '佐々木 陽太さんの未処理に、高橋 誠さんの承認待ちの申請が表示されます。佐々木 陽太さんへ通知が届きます。',
    );
    await submitAdd(page, dialog, /\/workflow\/proxies$/);

    const added = page
        .getByRole('table', { name: '自分の代理先' })
        .getByRole('row')
        .filter({ hasText: '佐々木 陽太' });
    await expect(added).toContainText(
        `${shown(todayInTokyo())} から 終了日なし`,
    );
    await deleteRow(page, added);
});

test('u0009 の鈴: 「代理」の行が出て、押すと代理の設定へ移り、自分の代理元に高橋 誠が出る', async ({
    page,
    artisan,
}) => {
    // 見本の通知の作成時刻は D − 16・D − 2 で、前の spec が作る通知より古く、鈴の最新 10 件から外れうる。作成時刻を今にして最新にそろえる
    artisan([
        'tinker',
        '--execute',
        [
            '$user = App\\Models\\Master\\User::query()->where("code", "u0009")->sole();',
            'App\\Models\\Notice\\Notice::query()->where("user_id", $user->id)',
            '->where("type", "workflow.proxy")->update(["created_at" => now()]);',
        ].join(''),
    ]);
    await openAs(page, 'concurrent', '/');

    await page.getByRole('button', { name: /^通知/ }).click();
    const row = page
        .locator('[data-slot="popover-content"]')
        .getByRole('listitem')
        .filter({ hasText: '高橋 誠さんの承認の代理先に設定されました。' });
    await expect(row).toContainText('代理');
    await expect(row).toContainText(
        `期間は ${shownDate('2026-10-13')} から、終了日なしです。`,
    );
    await submitAndExpectUrl(
        page,
        row.getByRole('button', {
            name: /^高橋 誠さんの承認の代理先に設定されました。/,
        }),
        /\/workflow\/proxies$/,
    );

    await expect(
        page.getByRole('heading', { level: 1, name: '代理の設定' }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('table', { name: '自分の代理元' })
            .getByRole('row')
            .filter({ hasText: '高橋 誠' }),
    ).toContainText('有効');
});
