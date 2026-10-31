import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { INVENTORY_BUG, openAs } from '../support/workflow-operations';

// 名前の札の「…」の導線(docs の 設計/ワークフロー/申請の周辺/メニューと導線.md 2.3)。初期データの見本の案件の処理のダイアログを開き、
// 人を選んで「組織図で見る」を押すだけで、ダイアログは決定しない(DB に書かない)。デモユーザー切替で利用者を替える。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * u0002(中村 美咲)で WF-000015(課長で承認待ち)の担当変更のダイアログを開き、変更後の処理担当者に u0012(松本 さくら。主所属 = 開発部)を選ぶ。
 */
async function pickInTransferDialog(page: Page) {
    await openAs(page, 'chief', '/workflow/cases');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', { name: INVENTORY_BUG, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name: INVENTORY_BUG }),
    ).toBeVisible();
    await page
        .getByRole('main')
        .getByRole('button', { name: '担当変更', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: '担当変更する',
        exact: true,
    });
    const field = dialog.getByRole('group', {
        name: '変更後の処理担当者',
        exact: true,
    });
    await field
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill('松本');
    await page.getByRole('option', { name: /^松本 さくら/ }).click();

    return { dialog, field };
}

test('処理のダイアログで選んだ人の札の「…」から「組織図で見る」を押すと、新しいタブでその人の主所属の組織図が開き、元のタブのダイアログは残る', async ({
    page,
}) => {
    const { dialog, field } = await pickInTransferDialog(page);
    const detailUrl = page.url();

    await field
        .getByRole('button', { name: '松本 さくらのメニュー', exact: true })
        .click();
    // 新しいタブは context の page のイベントで受ける(target=_blank・rel=noopener)
    const opened = page.context().waitForEvent('page');
    await page
        .getByRole('menuitem', { name: '組織図で見る', exact: true })
        .click();
    const chart = await opened;
    await chart.waitForLoadState();

    await expect(chart).toHaveURL(
        /\/master\/organization-chart\?department=\d+&user=\d+$/,
    );
    await expect(
        chart.getByRole('heading', { level: 1, name: '組織図' }),
    ).toBeVisible();
    await expect(chart.getByRole('region', { name: '開発部' })).toBeVisible();

    // 元のタブは詳細のまま、ダイアログと選んだ人が残る
    expect(page.url()).toBe(detailUrl);
    await expect(dialog).toBeVisible();
    await expect(
        field.getByRole('list', { name: '選んだ変更後の処理担当者' }),
    ).toContainText('松本 さくら');
    await chart.close();
});
