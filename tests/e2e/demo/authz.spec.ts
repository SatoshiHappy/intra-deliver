import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';
import {
    adminMenuButton,
    openFromAdminMenu,
    sidebarLink,
    sidebarHeading,
} from '../support/app-navigation';
import { menuAdminRow } from './authz-editor-support';

// 認可の確認(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 4 節の E2E)のうち、デモユーザー切替でシステム管理者になって確かめるもの
// (切替を使うので demo の持ち物。docs の 設計/デモユーザー切替/デモの取り付け.md 6.1)。画面は PC の幅だけを前提にする。
// 管理は歯車から開く(docs の 設計/メニュー/表示.md 3.3.5)。開いた後のサイドバーの先頭は管理のアプリ。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 利用者メニューを開き、項目を選ぶ。label は押す前の表示(氏名と、あればカレント組織)。
 */
async function choose(page: Page, label: string, item: string) {
    await page
        .getByRole('button', { name: `利用者メニュー：${label}`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: item }),
    );
}

/**
 * 認可設定の格子の、名前で引いた行(先頭の列は th scope="row" で、読み上げの名前はリソース名だけ。URI は画面に無い)。
 */
function resourceRow(page: Page, name: string) {
    return page.locator('tbody tr').filter({
        has: page.getByRole('rowheader', { name, exact: true }),
    });
}

test('システム管理者に切り替えると歯車の「認可設定」から開け、判定する人とリソースの種類を替えられる', async ({
    page,
}) => {
    await page.goto('/');
    await choose(page, 'ゲスト', 'システム管理者');

    // 歯車の「管理」から開くと、サイドバーの先頭が管理のアプリになり、アイテム「認可設定」が現在地になる
    await openFromAdminMenu(page, '認可設定');
    await expect(page).toHaveURL('/authz/overview');
    await expect(sidebarHeading(page)).toHaveText('管理');
    const link = sidebarLink(page, '認可設定');
    await expect(link.locator('svg.lucide-shield-check')).toHaveCount(1);
    await expect(link).toHaveAttribute('aria-current', 'page');
    await expect(
        page.getByRole('heading', { level: 1, name: '認可設定' }),
    ).toBeVisible();
    const judge = page.getByRole('combobox', { name: 'この人として判定' });
    const type = page.getByRole('combobox', { name: 'リソースの種類' });
    await expect(judge).toHaveText('伊藤 涼子');
    await expect(type).toHaveText('画面・処理');

    // アクションが 1 つの種類は、ラベルの横の「?」にフォーカスすると Tooltip が表示される
    await page.getByRole('button', { name: 'リソースの種類の説明' }).focus();
    await expect(page.getByRole('tooltip')).toHaveText(
        '画面・処理のアクションは「実行」の 1 つです',
    );

    // 列の見出し: 判定する人に該当する対象者だけ札「該当」
    const administrator = page.getByRole('columnheader', {
        name: 'システム管理者',
    });
    await expect(administrator.getByText('該当', { exact: true })).toHaveCount(
        1,
    );
    await expect(
        page
            .getByRole('columnheader', { name: 'ゲストユーザ' })
            .getByText('該当', { exact: true }),
    ).toHaveCount(0);

    // 「設定」タブ: アクションが 1 つの種類は、対象者の下にアクションの段を出さない
    await expect(
        page.getByRole('columnheader', { name: '実行', exact: true }),
    ).toHaveCount(0);

    // 「設定」タブ: 認証の束(ゲストユーザ・認証済みユーザ)の右がシステム管理者(認証の束が左)。置き場から継承した許可
    await expect(
        resourceRow(page, '認可設定').locator('td').nth(5),
    ).toContainText('許可(画面・処理から継承)');

    // 「判定」タブ
    await page.getByRole('tab', { name: '判定' }).click();
    const authz = resourceRow(page, '認可設定').locator('td').first();
    await expect(authz).toContainText('許可');
    await expect(authz).toContainText('システム管理者');

    // 判定する人をゲストに替えても、タブは「判定」のまま
    await judge.click();
    await page
        .getByRole('option', {
            name: 'ゲスト(ログインしていない状態)',
            exact: true,
        })
        .click();

    await expect(page).toHaveURL(/\?user=guest$/);
    await expect(judge).toHaveText('ゲスト(ログインしていない状態)');
    await expect(page.getByRole('tab', { name: '判定' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    const chart = resourceRow(page, '組織図').locator('td').first();
    await expect(chart).toContainText('許可');
    await expect(chart).toContainText('ゲストユーザ');

    // 検索: 一致した行とその祖先だけが残り(畳んだ祖先も開いて出す)、検索中は開閉の記号を出さない。
    // 畳んだ状態は検索で変わらず、語を消す(空白だけでも同じ)と検索前の開閉に戻る
    const search = page.getByRole('textbox', { name: 'リソース名で検索' });
    const rowheader = (name: string) =>
        page.getByRole('rowheader', { name, exact: true });
    const toggles = page.getByRole('button', {
        name: /の配下を(非表示|表示)$/,
    });
    await page
        .getByRole('button', { name: '共通マスタの配下を非表示', exact: true })
        .click();
    await expect(rowheader('組織図')).toHaveCount(0);

    await search.fill('組織');
    await expect(rowheader('組織図')).toBeVisible();
    await expect(rowheader('認可設定')).toHaveCount(0);
    await expect(toggles).toHaveCount(0);

    for (const cleared of ['', ' ']) {
        await search.fill('組織');
        await expect(rowheader('組織図')).toBeVisible();
        await search.fill(cleared);
        await expect(rowheader('認可設定')).toBeVisible();
        await expect(rowheader('組織図')).toHaveCount(0);
        await expect(
            page.getByRole('button', {
                name: '共通マスタの配下を表示',
                exact: true,
            }),
        ).toHaveAttribute('aria-expanded', 'false');
    }

    await page
        .getByRole('button', { name: '共通マスタの配下を表示', exact: true })
        .click();
    await expect(rowheader('組織図')).toBeVisible();
    await search.fill('');

    // リソースの種類を替えても、判定する人(user=guest)とタブは残る
    await type.click();
    await page
        .getByRole('option', { name: 'メニューグループ', exact: true })
        .click();

    await expect(page).toHaveURL(/type=im-menu-group/);
    await expect(page).toHaveURL(/user=guest/);
    await expect(page.getByRole('tab', { name: '判定' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    // 「管理」は 2 つある(歯車のアプリのカテゴリ・サイトマップ)ので、親の行「管理メニュー（PC用）」より後で最初の行
    const admin = menuAdminRow(page);
    await expect(admin.locator('td').first()).toContainText('禁止');

    // 「設定」タブ: アクションが 2 つの種類は、対象者の下にアクションの段(「参照」「管理」)を出す
    await page.getByRole('tab', { name: '設定' }).click();
    await expect(page.getByRole('tab', { name: '設定' })).toHaveAttribute(
        'aria-selected',
        'true',
    );
    for (const action of ['参照', '管理']) {
        await expect(
            page
                .getByRole('columnheader', { name: action, exact: true })
                .first(),
        ).toBeVisible();
    }

    await choose(page, '伊藤 涼子（経営企画室）', 'ログアウト');

    // ログアウトするとゲストに戻り、歯車が消える(管理のアプリはシステム管理者だけ)
    await expect(adminMenuButton(page)).toHaveCount(0);
});
