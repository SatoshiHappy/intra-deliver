import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { todayInTokyo } from '../support/seed-dates';
import { openAsAdministrator } from '../support/records-headings';
import {
    ADD_DIALOG,
    deleteRow,
    inDays,
    pickUser,
    shown,
    submitAdd,
} from '../support/workflow-proxies';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、システム管理者の代理の管理。
// 初期データの見本(設計/ワークフロー/案件の運用/初期データ.md 4.1 の代理)を使い、追加した代理は筋の中で消す。
// 追加した代理先の通知は残るが、同じ組の spec は通知の件数を前提にしない(文で探す)。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * システム管理者に切り替え、サイドバーの「管理」の「代理の管理」を開く。
 */
async function openProxyAdministration(page: Page) {
    await openAsAdministrator(page, '/');
    const group = page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .locator('[data-sidebar="group"]')
        .filter({
            has: page.getByRole('heading', {
                level: 2,
                name: '管理',
                exact: true,
            }),
        });
    await group.getByRole('link', { name: '代理の管理', exact: true }).click();
    await expect(page).toHaveURL(/\/workflow\/admin\/proxies$/);
    await expect(
        page.getByRole('heading', { level: 1, name: '代理の管理' }),
    ).toBeVisible();
}

/**
 * 代理の追加のダイアログに、中村 美咲(代理元)から山田 恵(代理先)への申請の代理(プロジェクト計画の承認だけ・今日から 7 日後まで)を入れる。
 */
async function fillAdminDialog(page: Page, dialog: Locator) {
    await pickUser(page, dialog, '代理元', '中村 美咲');
    await pickUser(page, dialog, '代理先', '山田 恵');
    await dialog.getByRole('radio', { name: '申請', exact: true }).click();
    await dialog
        .getByRole('radio', { name: 'フローを指定', exact: true })
        .click();
    await dialog.getByRole('combobox', { name: '対象のフロー' }).click();
    await page
        .getByRole('option', { name: 'プロジェクト計画の承認', exact: true })
        .click();
    await dialog
        .getByRole('group', { name: '終了日', exact: true })
        .locator('input')
        .fill(inDays(7));
    await expect(dialog).toContainText(
        '山田 恵さんが、中村 美咲さんとして申請できるようになります。山田 恵さんへ通知が届きます。',
    );
}

test('システム管理者の代理の管理: サイドバーから開き、有効な代理だけで終了の行が消え、中村 美咲を代理元に足して消す', async ({
    page,
}) => {
    await openProxyAdministration(page);

    // 見本の終了の行 = 山田 恵 → 中村 美咲
    const table = page.getByRole('table', { name: '代理' });
    const ended = table.getByRole('row').filter({ hasText: '中村 美咲' });
    await expect(ended.getByText('終了', { exact: true })).toBeVisible();
    await page.getByRole('checkbox', { name: '有効な代理だけ' }).click();
    await expect(page).toHaveURL(/active=1/);
    await expect(ended).toHaveCount(0);
    await expect(
        table.getByRole('row').filter({ hasText: '清水 美穂' }),
    ).toContainText('高橋 誠');

    await page.getByRole('button', { name: '代理を追加', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: ADD_DIALOG });
    await fillAdminDialog(page, dialog);
    // 追加の移り先は元の画面(絞り込みを残す)
    await submitAdd(page, dialog, /\/workflow\/admin\/proxies\?active=1$/);

    // 登録した人は判定を求めた管理者(伊藤 涼子)
    const added = table.getByRole('row').filter({
        hasText: `${shown(todayInTokyo())} から ${shown(inDays(7))}`,
    });
    await expect(added).toContainText('中村 美咲');
    await expect(added).toContainText('山田 恵');
    await expect(added).toContainText('プロジェクト計画の承認');
    await expect(added).toContainText('伊藤 涼子');
    await deleteRow(page, added);
});
