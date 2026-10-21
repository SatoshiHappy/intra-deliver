import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from '../support/submit';

// レコードのコメント(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 9 節の E2E): 詳細の帯の「コメント」→ 宛先を選んで投稿 →
// 一覧の先頭に出る → 宛先の人に切り替えると鈴に通知 → 押すとコメントの欄が開いた詳細へ。加えて本人の削除の確かめ → 削除の印。
// 利用者の切替を使うので demo の持ち物。プロジェクトは初期データの「社内ポータル刷新」(見本のコメントが付いている)。
// 書いたコメントはほかの筋に影響しない(件数に依る確かめを置かない)。画面は PC の幅だけを前提にする。
test.use({ viewport: { width: 1280, height: 720 } });

const ADMINISTRATOR = '伊藤 涼子（経営企画室）';
const APPLICANT = '佐々木 陽太（東京営業課）';

function userMenu(page: Page, label: string) {
    return page.getByRole('button', {
        name: `利用者メニュー：${label}`,
        exact: true,
    });
}

/**
 * 利用者メニューを開き、項目を選ぶ(送信)。
 */
async function choose(page: Page, label: string, item: string) {
    await userMenu(page, label).click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: item }),
    );
}

/**
 * ゲストからシステム管理者に切り替え、プロジェクトの「社内ポータル刷新」の詳細でコメントの欄を開く。
 */
async function openCommentsAsAdministrator(page: Page): Promise<Locator> {
    await page.goto('/');
    await choose(page, 'ゲスト', 'システム管理者');
    await expect(userMenu(page, ADMINISTRATOR)).toBeVisible();
    await page.goto('/records/data/project');
    await page
        .getByRole('region', { name: 'プロジェクトの一覧' })
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name: /^コメント/ })
        .click();
    await expect(page).toHaveURL(/\?panel=comments$/);
    const pane = page.getByRole('region', { name: 'コメント', exact: true });
    await expect(pane).toBeVisible();

    return pane;
}

/**
 * コメントの欄の一覧(新しい順)。
 */
function comments(pane: Locator) {
    return pane
        .getByRole('list', { name: 'コメントの一覧' })
        .getByRole('listitem');
}

/**
 * 宛先に「佐々木 陽太」を選び、本文を書いて投稿する(同じ URL へ戻るのを待つ)。
 */
async function postToApplicant(page: Page, pane: Locator, body: string) {
    const form = pane.getByRole('form', { name: 'コメントの入力' });

    await form.getByRole('combobox', { name: '宛先を追加' }).click();
    await page.getByPlaceholder('検索する語').fill('佐々木');
    await page.getByRole('option', { name: /佐々木 陽太/ }).click();
    await expect(form.getByRole('list', { name: '選んだ宛先' })).toContainText(
        '佐々木 陽太',
    );
    await form
        .getByRole('textbox', { name: 'コメント', exact: true })
        .fill(body);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '投稿', exact: true }),
        page.url(),
    );
    await expect(
        form.getByRole('textbox', { name: 'コメント', exact: true }),
    ).toHaveValue('');
}

test('宛先を選んで投稿すると一覧の先頭に出て、宛先の人に切り替えると鈴の通知から、コメントの欄が開いた詳細へ移れる', async ({
    page,
}) => {
    const pane = await openCommentsAsAdministrator(page);

    await postToApplicant(page, pane, `E2E の確認 ${Date.now()}\n2 行目`);

    await expect(
        page.getByText('コメントを投稿しました', { exact: true }),
    ).toBeVisible();
    const latest = comments(pane).first();
    await expect(latest).toContainText('伊藤 涼子');
    await expect(latest).toContainText('E2E の確認');
    await expect(latest).toContainText('2 行目');
    await expect(latest).toContainText('宛先');
    await expect(latest).toContainText('佐々木 陽太');

    await choose(page, ADMINISTRATOR, '申請者');
    await expect(userMenu(page, APPLICANT)).toBeVisible();
    await page.getByRole('button', { name: /^通知/ }).click();
    const notice = page
        .locator('[data-slot="popover-content"]')
        .getByRole('button', {
            name: '伊藤 涼子さんがコメントの宛先にあなたを指定しました。',
        })
        .first();
    await expect(notice).toBeVisible();
    await notice.click();

    await expect(page).toHaveURL(
        /\/records\/data\/project\/\d+\?panel=comments$/,
    );
    await expect(
        page.getByRole('region', { name: 'コメント', exact: true }),
    ).toBeVisible();
});

test('自分のコメントは確かめを見てから削除でき、削除の印が出る', async ({
    page,
}) => {
    const pane = await openCommentsAsAdministrator(page);
    const form = pane.getByRole('form', { name: 'コメントの入力' });
    const detailUrl = page.url();

    await form
        .getByRole('textbox', { name: 'コメント', exact: true })
        .fill('E2E の削除の確かめ');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '投稿', exact: true }),
        detailUrl,
    );
    const latest = comments(pane).first();
    await expect(latest).toContainText('E2E の削除の確かめ');

    await latest
        .getByRole('button', { name: '伊藤 涼子のコメントを削除' })
        .click();
    const dialog = page.getByRole('dialog', { name: 'コメントの削除' });
    await expect(dialog).toContainText(
        'このコメントを削除しますか。削除したことは一覧に表示されます。',
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        detailUrl,
    );

    await expect(
        page.getByText('コメントを削除しました', { exact: true }),
    ).toBeVisible();
    const mark = comments(pane).first();
    await expect(mark).toContainText('このコメントは削除されました。');
    await expect(mark).toContainText('削除した人　伊藤 涼子');
    await expect(mark).not.toContainText('E2E の削除の確かめ');
});
