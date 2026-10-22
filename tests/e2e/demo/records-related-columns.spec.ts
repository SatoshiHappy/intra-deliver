import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';

// 管理の「関連レコードの列」の選択(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 4 節・7 節の E2E の行):
// 初期データの「課題」の参照の項目「プロジェクト」の列を選んで並べると、行に選んだ列と「<n> / 5 列」が出て、プロジェクトの詳細の関連レコードの表の
// 列になる。6 つ目は選べない。「未選択に戻す」で一覧の列に戻る。システム管理者に切り替えて開く。
test.use({ viewport: { width: 1280, height: 720 } });

const DEFINITION = '/records/admin/modules/project_issue';

/** 面「関連レコードの列」 */
function section(page: Page) {
    return page.getByRole('region', { name: '関連レコードの列', exact: true });
}

/** 「列を選択」の Dialog を開く */
async function openDialog(page: Page) {
    await section(page)
        .getByRole('button', { name: '列を選択(プロジェクト)', exact: true })
        .click();

    return page.getByRole('dialog', { name: '関連レコードの列の選択' });
}

/** 項目のチェックを押す(並びの末尾に足す) */
async function check(dialog: Locator, names: string[]) {
    for (const name of names) {
        await dialog.getByRole('checkbox', { name, exact: true }).click();
    }
}

/** 「保存」で定義の画面へ戻る */
async function save(page: Page, dialog: Locator) {
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '保存', exact: true }),
        DEFINITION,
    );
}

test('列を選んで並べて保存すると、行と関連レコードの表の列になり、6 つ目は選べず、未選択に戻せる', async ({
    page,
}) => {
    await openAsAdministrator(page, DEFINITION);
    await expect(section(page)).toContainText('未選択 一覧の列を使用します。');

    let dialog = await openDialog(page);
    await check(dialog, ['課題名', '状態']);
    await dialog
        .getByRole('button', { name: '上へ(状態)', exact: true })
        .click();
    await save(page, dialog);
    await expect(
        page.getByText('関連レコードの列を保存しました'),
    ).toBeVisible();
    await expect(section(page)).toContainText('2 / 5 列');

    await page.goto('/records/data/project');
    await page
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .first()
        .click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    // 既定の入口は先頭(会議)なので、課題の入口をクエリで開く
    await page.goto(`${page.url()}?tab=related&related=project_issue.project`);
    await expect(
        page.getByRole('tabpanel').getByRole('columnheader'),
    ).toHaveText(['状態', '課題名', '更新日']);

    await page.goto(DEFINITION);
    dialog = await openDialog(page);
    await check(dialog, ['優先度', '期限', '担当者']);
    await expect(
        dialog.getByRole('checkbox', { name: '報告日時', exact: true }),
    ).toBeDisabled();
    await dialog
        .getByRole('button', { name: '未選択に戻す', exact: true })
        .click();
    await save(page, dialog);
    await expect(section(page)).toContainText('未選択 一覧の列を使用します。');
});
