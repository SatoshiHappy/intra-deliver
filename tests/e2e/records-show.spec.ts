import { expect, test } from './support/test';
import type { Locator, Page } from '@playwright/test';
import { submitAndExpectUrl } from './support/submit';

// レコードの詳細の枠(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 9 節、更新履歴.md の 9 節の E2E、
// 添付ファイル/レコードの添付.md の 10 節の E2E。コメント・関連レコード・申請の詳細・音声は後の実装)。ゲストだけで成り立つ。
// 書き込む筋は、筋の中で課題を 1 件登録し、最後に消す(添付と更新履歴も一緒に消える。ほかの筋の件数を変えない)。
// 申請済みの「編集」「削除」の押せない形は、申請を作るワークフローの筋(利用者を切り替える側の workflow.spec.ts)が確かめる。
test.use({ viewport: { width: 1280, height: 720 } });

const ISSUE = 'E2E の詳細の枠';

/** 帯(右端の細い帯) */
function rail(page: Page) {
    return page.getByRole('navigation', { name: 'サイドパネル', exact: true });
}

/** 帯の欄のボタン(読み上げは正式の名前で始まり、件数を出す欄は件数が続く) */
function railButton(page: Page, name: string) {
    return rail(page).getByRole('button', { name: new RegExp(`^${name}`) });
}

/** 開いた欄のパネル */
function pane(page: Page, name: string) {
    return page.getByRole('region', { name, exact: true });
}

/** 添付の欄の一覧の名前(上から) */
function attachmentNames(panel: Locator) {
    return panel.getByRole('listitem').locator('span.font-medium');
}

/** 小さなテキストのファイル(名前と中身) */
function textFile(name: string) {
    return { name, mimeType: 'text/plain', buffer: Buffer.from(`${name}\n`) };
}

/**
 * 課題を 1 件登録して詳細を開く(プロジェクトは初期データの「社内ポータル刷新」)。詳細の URL を返す。
 */
async function createIssue(page: Page): Promise<string> {
    await page.goto('/records/data/project_issue/create');
    const form = page.getByRole('form', { name: '課題の入力' });
    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(ISSUE);
    await form
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    await page.getByPlaceholder('検索する語').fill('社内');
    await page
        .getByRole('option', { name: '社内ポータル刷新', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );

    return page.url();
}

/**
 * 添付の欄で、ファイルを追加して一覧の先頭に出るまで待つ(1 回に 3 つまで)。
 */
async function addFiles(page: Page, names: string[]) {
    const panel = pane(page, '添付ファイル');
    await panel
        .locator('input[type="file"]')
        .setInputFiles(names.map(textFile));
    await expect(attachmentNames(panel).first()).toHaveText(
        names.at(-1) ?? '',
        {
            timeout: 15_000,
        },
    );
}

/**
 * 添付の欄で 1 つを外す(確かめの文を見てから)。
 */
async function detachFile(page: Page, name: string) {
    await pane(page, '添付ファイル')
        .getByRole('button', { name: `${name}を削除`, exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '添付ファイルの削除' });
    await expect(dialog).toContainText(
        `${name}を削除しますか。削除したファイルは、更新履歴から表示できます。`,
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
    );
}

/**
 * 更新履歴の先頭の「編集」の削除の行のリンクから、外した時点のファイルを開ける(テキストはプレビューの型なのでプレビューの口。
 * 中身が外したファイルのまま)。
 */
async function expectRemovedFileOpens(page: Page, name: string) {
    await railButton(page, '更新履歴').click();
    const latest = pane(page, '更新履歴').getByRole('listitem').first();
    await expect(latest).toContainText('編集');
    await expect(latest).toContainText('外した時点のファイルを表示できます。');
    const href = await latest
        .getByRole('link', { name, exact: true })
        .getAttribute('href');
    expect(href).toMatch(/\/revision-files\/\d+\/preview$/);
    const file = await page.request.get(href ?? '');
    expect(file.status()).toBe(200);
    expect(file.headers()['content-type']).toBe('text/plain; charset=UTF-8');
    expect(await file.text()).toBe(`${name}\n`);
}

/**
 * 詳細の「削除」で、確かめの文を見てからレコードを消す。
 */
async function deleteIssue(page: Page) {
    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await expect(dialog).toContainText(
        `${ISSUE}を削除しますか。添付ファイル・コメント・更新履歴も一緒に削除されます。この操作は取り消せません。`,
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
}

test('詳細は帯だけで開き、欄を押すとパネルが開いて URL に panel が付き、切り替え・同じアイコンで閉じる・再読み込みで開いたまま', async ({
    page,
}) => {
    const detailUrl = await createIssue(page);

    await expect(rail(page)).toBeVisible();
    await expect(pane(page, '添付ファイル')).toHaveCount(0);

    await railButton(page, '添付ファイル').click();
    await expect(page).toHaveURL(/\?panel=attachments$/);
    await expect(
        pane(page, '添付ファイル').getByRole('button', {
            name: 'ファイルを追加',
            exact: true,
        }),
    ).toBeVisible();
    await expect(railButton(page, '添付ファイル')).toHaveAttribute(
        'aria-pressed',
        'true',
    );

    await railButton(page, '更新履歴').click();
    await expect(page).toHaveURL(/\?panel=revisions$/);
    await expect(pane(page, '更新履歴')).toContainText('登録');
    await expect(pane(page, '添付ファイル')).toHaveCount(0);
    await expect(railButton(page, '更新履歴')).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await expect(railButton(page, '添付ファイル')).toHaveAttribute(
        'aria-pressed',
        'false',
    );

    await railButton(page, '更新履歴').click();
    await expect(page).toHaveURL(detailUrl);
    await expect(pane(page, '更新履歴')).toHaveCount(0);

    await railButton(page, '更新履歴').click();
    await expect(page).toHaveURL(/\?panel=revisions$/);
    await page.reload();
    await expect(pane(page, '更新履歴')).toContainText('登録');
    await pane(page, '更新履歴')
        .getByRole('button', { name: 'サイドパネルを非表示にする' })
        .click();
    await expect(page).toHaveURL(detailUrl);

    await deleteIssue(page);
});

test('添付を足すと同じ欄が開いたまま先頭に出て、「さらに表示」で続きが足され、削除すると消え、更新履歴の削除の行から外した時点のファイルを開ける', async ({
    page,
}) => {
    await createIssue(page);
    await railButton(page, '添付ファイル').click();
    const panel = pane(page, '添付ファイル');

    await addFiles(page, ['a.txt', 'b.txt', 'c.txt']);
    await expect(page).toHaveURL(/\?panel=attachments$/);
    await addFiles(page, ['d.txt', 'e.txt', 'f.txt']);
    await expect(attachmentNames(panel)).toHaveText([
        'f.txt',
        'e.txt',
        'd.txt',
        'c.txt',
        'b.txt',
    ]);
    await expect(railButton(page, '添付ファイル')).toHaveAccessibleName(
        '添付ファイル　6 件',
    );

    const urlBeforeMore = page.url();
    await panel
        .getByRole('button', { name: 'さらに表示', exact: true })
        .click();
    await expect(attachmentNames(panel)).toHaveText([
        'f.txt',
        'e.txt',
        'd.txt',
        'c.txt',
        'b.txt',
        'a.txt',
    ]);
    expect(page.url()).toBe(urlBeforeMore);

    await detachFile(page, 'f.txt');
    await expect(attachmentNames(panel).first()).toHaveText('e.txt');
    await expect(panel.getByText('f.txt', { exact: true })).toHaveCount(0);
    await expectRemovedFileOpens(page, 'f.txt');

    await deleteIssue(page);
});

test('項目を変えて保存すると、更新履歴の先頭に「編集」と変化が出る', async ({
    page,
}) => {
    const detailUrl = await createIssue(page);

    await page.getByRole('link', { name: '編集', exact: true }).click();
    const form = page.getByRole('form', { name: '課題の入力' });
    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(`${ISSUE}(改)`);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
        detailUrl,
    );

    await railButton(page, '更新履歴').click();
    const latest = pane(page, '更新履歴').getByRole('listitem').first();
    await expect(latest).toContainText('編集');
    await expect(latest).toContainText('課題名');
    await expect(latest).toContainText(ISSUE);
    await expect(latest).toContainText(`${ISSUE}(改)`);

    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
});

test('ほかの画面から来ると詳細タブで、左のタブは 詳細 / 参照制限 / 関連レコードだけ(狭い幅でも「その他」が出ない)で、明細は詳細タブの帯に出る', async ({
    page,
}) => {
    // サイドバーを表示したまま幅を狭め、パネルを開いて左の面を狭くしても、タブは全部帯に並ぶ(あふれたときの形は置かない)
    await page.setViewportSize({ width: 960, height: 720 });
    await page.goto('/records/data/project');
    await page
        .getByRole('region', { name: 'プロジェクトの一覧' })
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    const values = page.getByRole('region', { name: '社内ポータル刷新の値' });
    // ほかの画面から来ると詳細タブ(詳細の画面/README.md の 3 節)
    await expect(
        values.getByRole('tab', { name: '詳細', exact: true }),
    ).toHaveAttribute('aria-selected', 'true');
    await railButton(page, '更新履歴').click();

    await expect(values.getByRole('tab')).toHaveText([
        '詳細',
        '参照制限',
        /^関連レコード/,
    ]);
    await expect(values.getByRole('button', { name: 'その他' })).toHaveCount(0);
    await expect(
        values.getByRole('button', { name: /^マイルストーン\s*4 件$/ }),
    ).toBeVisible();
});
