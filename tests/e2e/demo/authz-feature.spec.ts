import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { ADMINISTRATOR, openOverviewAs } from './authz-editor-support';

// 認可の確認(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節の E2E)のうち、機能の絞り込み(認可設定の画面.md 2.3 の「機能」)。
// システム管理者に切り替えて確かめる。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

/** 「認可」(http-services.authz)で絞ったときの URL のクエリ */
const AUTHZ_QUERY = /\?feature=http-services\.authz$/;

const featureSelect = (page: Page) =>
    page.getByRole('combobox', { name: '機能' });

const rowheader = (page: Page, name: string) =>
    page.getByRole('rowheader', { name, exact: true });

async function chooseFeature(page: Page, label: string) {
    await featureSelect(page).click();
    await page.getByRole('option', { name: label, exact: true }).click();
    await expect(featureSelect(page)).toHaveText(label);
}

/**
 * 「認可」で絞った格子: 束「認可設定」が残り、束「共通マスタ」(深さ 1 のほかの行)が消える。
 */
async function expectAuthzOnly(page: Page) {
    await expect(featureSelect(page)).toHaveText('認可');
    await expect(rowheader(page, '認可設定')).toBeVisible();
    await expect(rowheader(page, '共通マスタ')).toHaveCount(0);
}

test('先頭の行を畳んでから機能を選ぶと、選んだ行と配下と祖先だけが両方のタブに出て、先頭の行に開閉の記号が無い', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await expect(featureSelect(page)).toHaveText('すべて');

    await page
        .getByRole('button', { name: '画面・処理の配下を非表示', exact: true })
        .click();
    await expect(rowheader(page, '認可設定')).toHaveCount(0);
    await chooseFeature(page, '認可');

    await expect(page).toHaveURL(AUTHZ_QUERY);
    await expectAuthzOnly(page);
    await expect(rowheader(page, '画面・処理')).toBeVisible();
    await expect(
        page.getByRole('button', { name: /^画面・処理の配下を/ }),
    ).toHaveCount(0);
    await expect(
        page.getByRole('button', { name: '認可の配下を非表示', exact: true }),
    ).toBeVisible();

    // タブを替えても同じ絞り込み
    await page.getByRole('tab', { name: '判定' }).click();
    await expectAuthzOnly(page);
    await page.getByRole('tab', { name: '設定' }).click();

    // 検索は範囲(選んだ行と配下)の中で行う(範囲の外の「共通マスタ」に一致しても出さない)
    await page.getByRole('textbox', { name: 'リソース名で検索' }).fill('共通');
    await expect(
        page.getByText('検索条件に一致するリソースはありません'),
    ).toBeVisible();
});

test('機能を選ぶと履歴に積まれ、戻る操作で前の絞り込みに戻り、再読み込みしても同じ絞り込み', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await chooseFeature(page, '認可');
    await expect(page).toHaveURL(AUTHZ_QUERY);

    await page.goBack();
    await expect(page).toHaveURL('/authz/overview');
    await expect(featureSelect(page)).toHaveText('すべて');
    await expect(rowheader(page, '共通マスタ')).toBeVisible();

    await page.goForward();
    await expect(page).toHaveURL(AUTHZ_QUERY);
    await expectAuthzOnly(page);

    await page.reload();
    await expectAuthzOnly(page);
});

test('判定する人を替えても feature は残り、リソースの種類を替えると外れて「すべて」', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);
    await chooseFeature(page, '認可');

    await page.getByRole('combobox', { name: 'この人として判定' }).click();
    await page
        .getByRole('option', {
            name: 'ゲスト(ログインしていない状態)',
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(/user=guest/);
    await expect(page).toHaveURL(/feature=http-services\.authz/);
    await expectAuthzOnly(page);

    await page.getByRole('combobox', { name: 'リソースの種類' }).click();
    await page
        .getByRole('option', { name: 'メニューグループ', exact: true })
        .click();
    await expect(page).toHaveURL(/type=im-menu-group/);
    await expect(page).not.toHaveURL(/feature=/);
    await expect(featureSelect(page)).toHaveText('すべて');
});

test('feature 付きの URL を開くと同じ絞り込みで、深さ 1 の行に無い値は「すべて」', async ({
    page,
}) => {
    await openOverviewAs(page, ADMINISTRATOR);

    await page.goto('/authz/overview?feature=http-services.authz');
    await expectAuthzOnly(page);

    await page.goto('/authz/overview?feature=abc');
    await expect(featureSelect(page)).toHaveText('すべて');
    await expect(rowheader(page, '共通マスタ')).toBeVisible();
});
