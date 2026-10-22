import { expect, test } from './support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './support/submit';

// 関連レコードと複製(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 9 節の E2E、複製.md の 5 節の E2E)。ゲストだけで成り立つ。
// 初期データのプロジェクト「社内ポータル刷新」と、それを参照する課題を使う。筋の中で足した課題は筋の最後に消す(ほかの筋の件数を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

const PROJECT = '社内ポータル刷新';

/** 課題の入口を開いた URL(クエリの並びによらない) */
const ISSUE_ENTRY_URL =
    /^(?=.*[?&]tab=related)(?=.*[?&]related=project_issue\.project)/;

const ADDED = ['E2E の関連レコード 1', 'E2E の関連レコード 2'];

/** 左のタブ「関連レコード」(読み上げは「関連レコード <件数>」) */
function relatedTab(page: Page) {
    return page.getByRole('tab', { name: /^関連レコード \d+$/ });
}

/** タブの件数 */
async function relatedCount(page: Page): Promise<number> {
    const name = (await relatedTab(page).getAttribute('aria-label')) ?? '';

    return Number(name.replace('関連レコード ', ''));
}

/** 詳細タブの先頭のチップ「課題」の件数(読み上げは「課題 <件数> 件」) */
async function issueChipCount(page: Page): Promise<number> {
    const name =
        (await page
            .getByRole('group', { name: '関連レコードの件数' })
            .getByRole('button', { name: /^課題 \d+ 件$/ })
            .getAttribute('aria-label')) ?? '';

    return Number(name.replace(/^課題 (\d+) 件$/, '$1'));
}

/** 一覧からプロジェクト「社内ポータル刷新」の詳細を開く */
async function openProject(page: Page) {
    await page.goto('/records/data/project');
    await page
        .getByRole('link', { name: PROJECT, exact: true })
        .first()
        .click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
}

/** 関連レコードのタブの表の、課題名のリンク */
function issueLink(page: Page, name: string): Locator {
    return page.getByRole('tabpanel').getByRole('link', { name, exact: true });
}

/** 「課題の登録」のダイアログで課題名を入れて「登録して続けて作成」を押し、登録した数の 1 行を待つ */
async function addAndContinue(page: Page, name: string, count: number) {
    const dialog = page.getByRole('dialog', { name: '課題の登録' });
    await dialog
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(name);
    await dialog
        .getByRole('button', { name: '登録して続けて作成', exact: true })
        .click();
    await expect(dialog.getByRole('status')).toHaveText(
        `${count} 件登録しました。`,
    );
    await expect(
        dialog.getByRole('textbox', { name: '課題名', exact: true }),
    ).toHaveValue('');
}

/** 課題の詳細の「削除」で消す(課題の一覧へ戻る) */
async function deleteIssue(page: Page) {
    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
}

/**
 * タブ「関連レコード」にポインタを合わせ、メニューの「課題の新規」のダイアログで 2 件を続けて登録して閉じる(プロジェクトは固定)。
 */
async function addTwoFromMenu(page: Page) {
    await relatedTab(page).hover();
    await page
        .getByRole('menuitem', { name: '課題の新規', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '課題の登録' });
    await expect(dialog).toContainText(
        `プロジェクト「${PROJECT}」の関連レコードとして登録します。`,
    );
    await expect(
        dialog.getByRole('textbox', { name: 'プロジェクト', exact: true }),
    ).toHaveValue(PROJECT);
    await expect(dialog).toContainText(
        '関連レコードとして登録するため、変更できません。',
    );
    await addAndContinue(page, ADDED[0], 1);
    await addAndContinue(page, ADDED[1], 2);
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
}

/** 足した課題を、プロジェクトの関連レコードの表から開いて消す */
async function deleteAdded(page: Page, projectUrl: string) {
    for (const name of ADDED) {
        await page.goto(
            `${projectUrl}?tab=related&related=project_issue.project`,
        );
        await issueLink(page, name).click();
        await expect(page).toHaveURL(/\/records\/data\/project_issue\/\d+$/);
        await deleteIssue(page);
    }
}

test('関連レコードの入口から「新規」で続けて 2 件登録すると、表とタブの件数が増え、チップを押すとその入口の表が出る', async ({
    page,
}) => {
    await openProject(page);
    const projectUrl = page.url();
    const before = await relatedCount(page);
    const issuesBefore = await issueChipCount(page);

    await addTwoFromMenu(page);

    await expect(page).toHaveURL(ISSUE_ENTRY_URL);
    await expect(relatedTab(page)).toHaveAttribute(
        'aria-label',
        `関連レコード ${before + 2}`,
    );
    for (const name of ADDED) {
        await expect(issueLink(page, name)).toBeVisible();
    }

    await page.getByRole('tab', { name: '詳細', exact: true }).click();
    const chips = page.getByRole('group', { name: '関連レコードの件数' });
    await chips
        .getByRole('button', {
            name: `課題 ${issuesBefore + 2} 件`,
            exact: true,
        })
        .click();
    await expect(page).toHaveURL(ISSUE_ENTRY_URL);
    await expect(issueLink(page, ADDED[0])).toBeVisible();

    await deleteAdded(page, projectUrl);
});

test('レコードの詳細の「複製」で登録の画面に値が入り、「登録」で新しい詳細になる', async ({
    page,
}) => {
    await openProject(page);
    // 既定の入口は先頭(会議)で行が無いことがあるので、課題の入口をクエリで開く
    await page.goto(`${page.url()}?tab=related&related=project_issue.project`);
    await page.getByRole('tabpanel').getByRole('link').first().click();
    await expect(page).toHaveURL(/\/records\/data\/project_issue\/\d+$/);
    const sourceUrl = page.url();
    const title = await page.getByRole('heading', { level: 1 }).textContent();

    await page.getByRole('link', { name: '複製', exact: true }).click();
    await expect(page).toHaveURL(
        /\/records\/data\/project_issue\/create\?copy=\d+$/,
    );
    const form = page.getByRole('form', { name: '課題の入力' });
    await expect(
        form.getByRole('textbox', { name: '課題名', exact: true }),
    ).toHaveValue(title ?? '');
    await expect(
        form.getByRole('combobox', { name: 'プロジェクト', exact: true }),
    ).toContainText(PROJECT);

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    expect(page.url()).not.toBe(sourceUrl);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(
        title ?? '',
    );

    await deleteIssue(page);
});
