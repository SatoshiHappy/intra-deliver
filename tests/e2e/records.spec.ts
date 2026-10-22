import { expect, test } from './support/test';
import type { Page } from '@playwright/test';
import { seededDate, shownDate } from './support/seed-dates';
import { submitAndExpectUrl } from './support/submit';

// 業務データの入れ物の業務の画面(docs の 設計/業務データの入れ物/画面/README.md 6 節の main のうち業務の分)。初期データ(RecordsSeeder)の
// プロジェクト・課題を使い、ゲストだけで成り立つ。画面は PC の幅だけを前提にする。ナビの中は見出しでグループを絞ってから引く。
// 書き込む筋は、足したものを同じ筋の中で消して初期データに戻す(ほかの筋の件数を変えない)。
// ワークフローの申請が作り案件が指すレコードは消せないので、プロジェクトの一覧の件数・並びは
// ほかの筋に依らない形(開始日で業務データの入れ物の初期データの 3 件に絞る・初期データの行を名前で確かめる)で確かめる。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 一覧を RecordsSeeder の 3 件に絞る条件(開始日 〜 3 件目。WorkflowSeeder の見本と筋が足すプロジェクトは開始日が後)。URL は一覧の画面と同じ形。
 */
const SEEDED_PROJECTS_FILTER = `filter%5Bstart_date%5D%5Bto%5D=${seededDate('2026-11-01')}`;
const SEEDED_PROJECTS = `/records/data/project?${SEEDED_PROJECTS_FILTER}`;

/**
 * グローバルナビの中の、見出し(h2)が name のグループ。
 */
function navigationGroup(page: Page, name: string) {
    return page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .locator('[data-sidebar="group"]')
        .filter({
            has: page.getByRole('heading', { level: 2, name, exact: true }),
        });
}

/**
 * 一覧の表の面。
 */
function listRegion(page: Page, moduleName: string) {
    return page.getByRole('region', { name: `${moduleName}の一覧` });
}

/**
 * 一覧の検索欄(form role="search")。
 */
function searchForm(page: Page) {
    return page.getByRole('search', { name: 'レコードの検索' });
}

/**
 * 検索欄の面の見出し(押すと開閉する)。
 */
function searchToggle(page: Page) {
    return page.getByRole('button', { name: '検索条件', exact: true });
}

/**
 * 詳細の面(見出し heading の値)のタブで札 name を選び、その中身(tabpanel)を返す(詳細の本文はタブ = 画面/業務の画面.md 2.3)。
 */
async function openDetailTab(page: Page, heading: string, name: string) {
    const values = page.getByRole('region', { name: `${heading}の値` });
    await values.getByRole('tab', { name, exact: true }).click();

    return values.getByRole('tabpanel', { name, exact: true });
}

/**
 * 畳まれている検索欄を、見出しで開く(条件の無い一覧は畳まれて始まる = 画面/業務の画面.md 2.1)。
 */
async function openSearch(page: Page) {
    await searchToggle(page).click();
    await expect(searchForm(page)).toBeVisible();
}

test('サイドバーの「業務」に業務モジュールが並び、「プロジェクト」で一覧が出る', async ({
    page,
}) => {
    await page.goto('/');
    const group = navigationGroup(page, '業務');

    for (const [name, icon] of [
        ['プロジェクト', 'folder-kanban'],
        ['課題', 'list-todo'],
    ]) {
        await expect(
            group
                .getByRole('link', { name, exact: true })
                .locator(`svg.lucide-${icon}`),
        ).toHaveCount(1);
    }

    await group
        .getByRole('link', { name: 'プロジェクト', exact: true })
        .click();

    await expect(page).toHaveURL('/records/data/project');
    await expect(
        page.getByRole('heading', { level: 1, name: 'プロジェクト' }),
    ).toBeVisible();
    await expect(page).toHaveTitle('プロジェクト - イントラデリバー');
    const list = listRegion(page, 'プロジェクト');

    for (const column of ['担当部署', '責任者', '状態']) {
        await expect(
            list.getByRole('columnheader', { name: column }),
        ).toBeVisible();
    }

    // 行数と件数の表示は、ほかの筋が足すプロジェクトに依らないよう、初期データの 3 件に絞って確かめる
    await page.goto(SEEDED_PROJECTS);
    await expect(list.getByRole('row')).toHaveCount(4);
    await expect(list.getByText('全 3 件')).toBeVisible();
});

test('一覧の表が広くても、ページは横にはみ出さず、表の入れ物の中でスクロールする', async ({
    page,
}) => {
    await page.goto('/records/data/project');
    await expect(
        page.getByRole('heading', { level: 1, name: 'プロジェクト' }),
    ).toBeVisible();
    await page.addStyleTag({
        content: '[data-slot=table] { min-width: 2200px }',
    });

    const widths = await page.evaluate(() => {
        const container = document.querySelector('[data-slot=table-container]');

        return {
            page: [
                document.documentElement.scrollWidth,
                document.documentElement.clientWidth,
            ],
            container: [
                container?.scrollWidth ?? 0,
                container?.clientWidth ?? 0,
            ],
        };
    });

    expect(widths.page[0]).toBeLessThanOrEqual(widths.page[1]);
    expect(widths.container[0]).toBeGreaterThan(widths.container[1]);
});

test('検索欄の開閉は、解釈された条件と利用者の記憶で決まる', async ({
    page,
}) => {
    const status = page.getByRole('combobox', { name: '状態', exact: true });

    // 1. 条件なしで開くと畳まれている(記憶が無い)
    await page.goto('/records/data/project');

    await expect(searchToggle(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(searchForm(page)).toBeHidden();

    // 2. 見出しで開き(記憶 = 開く)、状態「完了」で検索しても開いたまま
    await openSearch(page);
    await expect(searchToggle(page)).toHaveAttribute('aria-expanded', 'true');
    await status.click();
    await page.getByRole('option', { name: '完了', exact: true }).click();
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(page).toHaveURL(
        /\/records\/data\/project\?filter%5Bstatus%5D=done$/,
    );
    await expect(
        listRegion(page, 'プロジェクト').getByText('全 1 件'),
    ).toBeVisible();
    await expect(searchForm(page)).toBeVisible();

    // 3. 条件を消しても開いたままで、条件なしで再読み込みしても記憶で開く
    await page.getByRole('button', { name: '条件を解除', exact: true }).click();

    await expect(page).toHaveURL('/records/data/project');
    await expect(searchForm(page)).toBeVisible();

    await page.reload();

    await expect(searchToggle(page)).toHaveAttribute('aria-expanded', 'true');
    await expect(searchForm(page)).toBeVisible();

    // 4. 見出しで畳むと(記憶 = 畳む)、再読み込みしても畳まれている
    await searchToggle(page).click();
    await expect(searchForm(page)).toBeHidden();

    await page.reload();

    await expect(searchToggle(page)).toHaveAttribute('aria-expanded', 'false');
    await expect(searchForm(page)).toBeHidden();

    // 5. 条件つきの URL に戻ると、記憶が「畳む」でも開き、欄は URL の条件で作られる(条件 > 記憶)
    await page.goBack();

    await expect(page).toHaveURL(
        /\/records\/data\/project\?filter%5Bstatus%5D=done$/,
    );
    await expect(searchToggle(page)).toHaveAttribute('aria-expanded', 'true');
    await expect(status).toHaveText('完了');
});

test('検索で状態「完了」を選ぶと、該当するレコードだけになり URL のクエリが変わる', async ({
    page,
}) => {
    await page.goto('/records/data/project');

    await openSearch(page);
    await page.getByRole('combobox', { name: '状態', exact: true }).click();
    await page.getByRole('option', { name: '完了', exact: true }).click();
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(page).toHaveURL(
        /\/records\/data\/project\?filter%5Bstatus%5D=done$/,
    );
    const list = listRegion(page, 'プロジェクト');
    await expect(list.getByRole('row')).toHaveCount(2);
    await expect(list.getByRole('row').nth(1)).toContainText(
        '経費精算の電子化',
    );

    await page.getByRole('button', { name: '条件を解除', exact: true }).click();

    // 条件の解除で、状態が完了でない初期データの行が戻る(全体の件数はほかの筋が足すプロジェクトで変わるので、行を名前で確かめる)
    await expect(page).toHaveURL('/records/data/project');
    for (const name of ['社内ポータル刷新', '新規顧客開拓キャンペーン']) {
        await expect(
            list.getByRole('link', { name, exact: true }),
        ).toBeVisible();
    }
});

test('サーバーが捨てた検索条件は、検索の後に欄から消える', async ({ page }) => {
    // 初期データの 3 件に絞った条件から始める(条件つきの URL では検索欄は開いて始まる)
    await page.goto(SEEDED_PROJECTS);
    await expect(searchForm(page)).toBeVisible();
    const name = searchForm(page).getByRole('textbox', {
        name: 'プロジェクト名',
        exact: true,
    });

    await name.fill('   ');
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(name).toHaveValue('');
    await expect(
        listRegion(page, 'プロジェクト').getByText('全 3 件'),
    ).toBeVisible();
});

test('並び替えでは、検索欄の送っていない入力が残る', async ({ page }) => {
    await page.goto('/records/data/project');
    await openSearch(page);
    const name = searchForm(page).getByRole('textbox', {
        name: 'プロジェクト名',
        exact: true,
    });
    const header = listRegion(page, 'プロジェクト').getByRole('columnheader', {
        name: /開始日/,
    });

    await name.fill('ポータル');
    await header.getByRole('button').click();

    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(name).toHaveValue('ポータル');
});

test('列「開始日」を押すと昇順に並び、もう一度押すと降順になる', async ({
    page,
}) => {
    // 並びは初期データの 3 件に絞って確かめる(ほかの筋が足すプロジェクトで降順の先頭が変わらないように)
    await page.goto(SEEDED_PROJECTS);
    const list = listRegion(page, 'プロジェクト');
    const header = list.getByRole('columnheader', { name: /開始日/ });

    await header.getByRole('button').click();

    await expect(page).toHaveURL(`${SEEDED_PROJECTS}&sort=start_date`);
    await expect(header).toHaveAttribute('aria-sort', 'ascending');
    await expect(list.getByRole('row').nth(1)).toContainText(
        '経費精算の電子化',
    );
    await expect(list.getByRole('row').nth(2)).toContainText(
        '社内ポータル刷新',
    );
    await expect(list.getByRole('row').nth(3)).toContainText(
        '新規顧客開拓キャンペーン',
    );

    await header.getByRole('button').click();

    await expect(page).toHaveURL(`${SEEDED_PROJECTS}&sort=-start_date`);
    await expect(header).toHaveAttribute('aria-sort', 'descending');
    await expect(list.getByRole('row').nth(1)).toContainText(
        '新規顧客開拓キャンペーン',
    );
});

test('「社内ポータル刷新」の詳細は札「詳細」で担当部署「開発部」・開始日(初期データの日付)が出て、札「マイルストーン 4」で 4 行が出て、再読み込みでも同じ札', async ({
    page,
}) => {
    await page.goto('/records/data/project');

    await listRegion(page, 'プロジェクト')
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();

    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    await expect(
        page.getByRole('heading', { level: 1, name: '社内ポータル刷新' }),
    ).toBeVisible();
    const values = page.getByRole('region', { name: '社内ポータル刷新の値' });
    const mainTab = values.getByRole('tab', { name: '詳細', exact: true });
    await expect(mainTab).toHaveAttribute('aria-selected', 'true');
    await expect(values.getByText('開発部', { exact: true })).toBeVisible();
    await expect(values.getByText('1,200', { exact: true })).toBeVisible();
    const start = shownDate('2026-07-01');
    await expect(values.getByText(start, { exact: true })).toBeVisible();

    const milestones = await openDetailTab(
        page,
        '社内ポータル刷新',
        'マイルストーン 4',
    );
    await expect(milestones.getByRole('row')).toHaveCount(5);
    await expect(milestones.getByRole('row').nth(2)).toContainText('15.5');
    await expect(values.getByText('開発部', { exact: true })).toBeHidden();

    // 選んだ札は URL のクエリ tab に映す(レコードの共通機能/詳細の画面/README.md の 3 節)
    await expect(page).toHaveURL(/\?tab=details-milestones$/);
    await page.reload();
    await expect(milestones.getByRole('row')).toHaveCount(5);
});

test('課題の詳細の報告日時は YYYY/MM/DD HH:MM で出て、プロジェクトは参照先の詳細へのリンクになる', async ({
    page,
}) => {
    await page.goto('/records/data/project_issue');

    await listRegion(page, '課題')
        .getByRole('link', { name: '検索の応答が遅い', exact: true })
        .click();
    const values = page.getByRole('region', { name: '検索の応答が遅いの値' });
    // 日時は Asia/Tokyo の YYYY/MM/DD HH:MM(画面/README.md 1.3)。初期データの日時は時刻を保って日付だけ流す日からの相対
    await expect(
        values.getByText(`${shownDate('2026-10-05')} 10:15`, { exact: true }),
    ).toBeVisible();
    await values
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();

    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    await expect(
        page.getByRole('heading', { level: 1, name: '社内ポータル刷新' }),
    ).toBeVisible();
});

/**
 * 登録・編集のフォーム。
 */
function recordForm(page: Page, moduleName: string) {
    return page.getByRole('form', { name: `${moduleName}の入力` });
}

/**
 * フォームの項目 1 つ(ラベル・「必須」の印・入力・エラーの組)。
 */
function fieldGroup(page: Page, moduleName: string, fieldName: string) {
    return recordForm(page, moduleName).getByRole('group', {
        name: fieldName,
        exact: true,
    });
}

test('登録のフォームの面は本文の幅いっぱいで、ボタンの列は面の右端に「キャンセル → 登録」の順で並ぶ', async ({
    page,
}) => {
    // 本文が入力の並びの上限(1024px)より広くなる幅で測る(1280px では本文が約 1012px で、面と並びの幅が同じになる)
    await page.setViewportSize({ width: 1920, height: 1080 });
    await page.goto('/records/data/project_issue/create');
    const form = recordForm(page, '課題');
    const submit = form.getByRole('button', { name: '登録', exact: true });
    const row = submit.locator('..');

    // 並びは見た目でなく DOM の順(キャンセル → 主ボタン)
    await expect(row.locator(':scope > *')).toHaveText(['キャンセル', '登録']);

    // ボタンの列は面(枠)の右端に揃う。面は入力の並びの上限(max-w-5xl = 1024px)より広い
    const surface = await form.locator('section').first().boundingBox();
    const button = await submit.boundingBox();

    expect(surface).not.toBeNull();
    expect(button).not.toBeNull();
    expect(surface!.width).toBeGreaterThan(1024);
    expect(button!.x + button!.width).toBeCloseTo(
        surface!.x + surface!.width,
        0,
    );
});

test('参照の選択欄は、開くと語を打たなくても候補の先頭が出て、打つと絞られ、選べる', async ({
    page,
}) => {
    // 他モジュール参照: 開いた直後に候補が出る(件数と並びは、ほかの筋が足すプロジェクトに依るので、先頭を選んで表示を確かめる)
    await page.goto('/records/data/project_issue/create');
    const issueForm = recordForm(page, '課題');
    await issueForm
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    const projects = page.getByRole('option');
    await expect(projects.first()).toBeVisible();
    expect(await projects.count()).toBeLessThanOrEqual(10);
    const firstProject = (await projects.first().textContent()) ?? '';
    await projects.first().click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
    await expect(
        issueForm.getByRole('combobox', { name: 'プロジェクト', exact: true }),
    ).toHaveText(firstProject);

    // ユーザー: 開いた直後に先頭の 10 件が出て、打つと絞られる
    await page.goto('/records/data/project/create');
    const projectForm = recordForm(page, 'プロジェクト');
    await projectForm
        .getByRole('combobox', { name: '責任者', exact: true })
        .click();
    await expect(page.getByRole('option')).toHaveCount(10);
    await page.getByPlaceholder('検索する語').fill('達也');
    await expect(page.getByRole('option')).toHaveText(['吉田 達也']);
    await page.getByRole('option', { name: '吉田 達也', exact: true }).click();
    await expect(
        projectForm.getByRole('combobox', { name: '責任者', exact: true }),
    ).toHaveText('吉田 達也');
});

test('課題を登録すると条件つき必須の印とエラーが出て、直すと登録でき、更新・削除もできる', async ({
    page,
}) => {
    await page.goto('/records/data/project_issue');
    await page.getByRole('link', { name: '登録', exact: true }).click();

    await expect(page).toHaveURL('/records/data/project_issue/create');
    await expect(
        page.getByRole('heading', { level: 1, name: '課題の登録' }),
    ).toBeVisible();
    const form = recordForm(page, '課題');
    const resolution = fieldGroup(page, '課題', '解決内容');

    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill('E2E の課題');
    await form
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    await page.getByPlaceholder('検索する語').fill('社内');
    await page
        .getByRole('option', { name: '社内ポータル刷新', exact: true })
        .click();
    await expect(resolution.getByText('必須', { exact: true })).toHaveCount(0);

    await form.getByRole('combobox', { name: '状態', exact: true }).click();
    await page.getByRole('option', { name: '完了', exact: true }).click();

    await expect(resolution.getByText('必須', { exact: true })).toBeVisible();

    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
    );

    await expect(
        resolution.getByText('状態が完了のときは解決内容を入力してください'),
    ).toBeVisible();
    await expect(resolution.getByRole('textbox')).toBeFocused();

    await resolution.getByRole('textbox').fill('E2E で直した。');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    await expect(page.getByText('登録しました', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E の課題' }),
    ).toBeVisible();

    await page.getByRole('link', { name: '編集', exact: true }).click();

    await expect(page).toHaveURL(/\/records\/data\/project_issue\/\d+\/edit$/);
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E の課題の編集' }),
    ).toBeVisible();

    await recordForm(page, '課題')
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill('E2E の課題(更新)');
    await submitAndExpectUrl(
        page,
        recordForm(page, '課題').getByRole('button', {
            name: '更新',
            exact: true,
        }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    await expect(page.getByText('更新しました', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: 'E2E の課題(更新)' }),
    ).toBeVisible();

    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await expect(dialog).toContainText('E2E の課題(更新)を削除しますか。');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
    await expect(
        listRegion(page, '課題').getByRole('link', {
            name: 'E2E の課題(更新)',
        }),
    ).toHaveCount(0);
});

test('日時の欄は Asia/Tokyo で往復し、一覧の範囲の検索も同じ時刻で当たる', async ({
    page,
}) => {
    // 範囲の時差を確かめる(上限の :59 は単体テスト recordSearch.test.ts が持つ。画面から登録した日時は秒が 0 なので見分けられない)
    const title = `日時の往復 ${Date.now()}`;
    const reportedAt = () =>
        fieldGroup(page, '課題', '報告日時').locator(
            'input[type="datetime-local"]',
        );

    await page.goto('/records/data/project_issue/create');
    const form = recordForm(page, '課題');
    await form
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(title);
    await form
        .getByRole('combobox', { name: 'プロジェクト', exact: true })
        .click();
    await page.getByPlaceholder('検索する語').fill('社内');
    await page
        .getByRole('option', { name: '社内ポータル刷新', exact: true })
        .click();
    await reportedAt().fill('2026-10-11T09:30');
    await expect(reportedAt()).toHaveValue('2026-10-11T09:30');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '登録', exact: true }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    const values = page.getByRole('region', { name: `${title}の値` });
    await expect(
        values.getByText('2026/10/11 09:30', { exact: true }),
    ).toBeVisible();

    // 編集の欄は登録した時刻のまま。何も変えずに更新しても時刻は変わらない
    await page.getByRole('link', { name: '編集', exact: true }).click();
    await expect(reportedAt()).toHaveValue('2026-10-11T09:30');
    await submitAndExpectUrl(
        page,
        recordForm(page, '課題').getByRole('button', {
            name: '更新',
            exact: true,
        }),
        /\/records\/data\/project_issue\/\d+$/,
    );
    await expect(page.getByText('更新しました', { exact: true })).toBeVisible();
    await expect(
        values.getByText('2026/10/11 09:30', { exact: true }),
    ).toBeVisible();
    const detailUrl = page.url();

    // 一覧の検索: 下限・上限ともに登録した時刻なら当たり、上限を 1 分前にすると当たらない
    await page.goto('/records/data/project_issue');
    await openSearch(page);
    const search = searchForm(page);
    await search
        .getByRole('textbox', { name: '課題名', exact: true })
        .fill(title);
    await search.getByLabel('報告日時の下限').fill('2026-10-11T09:30');
    await search.getByLabel('報告日時の上限').fill('2026-10-11T09:30');
    await page.getByRole('button', { name: '検索', exact: true }).click();

    const list = listRegion(page, '課題');
    await expect(page).toHaveURL(/filter%5Breported_at%5D/);
    await expect(list.getByText('全 1 件')).toBeVisible();
    await expect(
        list.getByRole('link', { name: title, exact: true }),
    ).toBeVisible();

    await search.getByLabel('報告日時の上限').fill('2026-10-11T09:29');
    await page.getByRole('button', { name: '検索', exact: true }).click();

    await expect(page).toHaveURL(/09(?:%3A|:)29/);
    await expect(
        list.getByRole('link', { name: title, exact: true }),
    ).toHaveCount(0);

    // 足したレコードを消して初期データに戻す
    await page.goto(detailUrl);
    await page.getByRole('button', { name: '削除', exact: true }).click();
    await submitAndExpectUrl(
        page,
        page
            .getByRole('dialog', { name: 'レコードの削除' })
            .getByRole('button', { name: '削除', exact: true }),
        '/records/data/project_issue',
    );
    await expect(page.getByText('削除しました', { exact: true })).toBeVisible();
});

test('課題から参照されているプロジェクトは削除できず、トーストで知らせて一覧に残る', async ({
    page,
}) => {
    await page.goto('/records/data/project');
    await listRegion(page, 'プロジェクト')
        .getByRole('link', { name: '新規顧客開拓キャンペーン', exact: true })
        .click();
    // 詳細への遷移を待ってから URL を控える(削除に失敗したら詳細に留まる = 画面/業務の画面.md 2.4)
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    const detailUrl = page.url();

    await page.getByRole('button', { name: '削除', exact: true }).click();
    const dialog = page.getByRole('dialog', { name: 'レコードの削除' });
    await expect(dialog).toContainText('明細・添付ファイル・');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '削除', exact: true }),
        detailUrl,
    );
    await expect(
        page.getByText(
            'このレコードはほかのデータから参照されているため削除できません',
            { exact: true },
        ),
    ).toBeVisible();
    await expect(dialog).toBeHidden();

    await page.goto('/records/data/project');
    await expect(
        listRegion(page, 'プロジェクト').getByRole('link', {
            name: '新規顧客開拓キャンペーン',
            exact: true,
        }),
    ).toBeVisible();
});

test('プロジェクトの編集は終了日を開始日より前にすると相関チェックで止まり、マイルストーンの行を足すと詳細の行が増える', async ({
    page,
}) => {
    await page.goto('/records/data/project');
    await listRegion(page, 'プロジェクト')
        .getByRole('link', { name: '経費精算の電子化', exact: true })
        .click();
    const milestones = await openDetailTab(
        page,
        '経費精算の電子化',
        'マイルストーン 1',
    );
    await expect(milestones.getByRole('row')).toHaveCount(2);
    await page.getByRole('link', { name: '編集', exact: true }).click();
    const form = recordForm(page, 'プロジェクト');
    const endDateField = fieldGroup(page, 'プロジェクト', '終了日');
    // ラベルは項目の組(role="group")も名付けるので、日付の入力は組の中から引く
    const endDate = endDateField.locator('input');

    await endDate.fill(seededDate('2026-03-31'));
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
    );

    await expect(
        endDateField.getByText('終了日は開始日以後の日にしてください'),
    ).toBeVisible();
    await expect(endDate).toBeFocused();

    await endDate.fill(seededDate('2026-09-30'));
    await form.getByRole('button', { name: '行を追加', exact: true }).click();
    await form
        .getByRole('textbox', { name: '名称(2 行目)', exact: true })
        .fill('E2E の節目');
    await form.getByLabel('期日(2 行目)', { exact: true }).fill('2026-09-15');
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
    );

    await expect(page.getByText('更新しました', { exact: true })).toBeVisible();
    const added = await openDetailTab(
        page,
        '経費精算の電子化',
        'マイルストーン 2',
    );
    await expect(added.getByRole('row')).toHaveCount(3);
    await expect(added.getByRole('row').nth(2)).toContainText('E2E の節目');

    await page.getByRole('link', { name: '編集', exact: true }).click();
    await recordForm(page, 'プロジェクト')
        .getByRole('button', { name: '行を削除(2 行目)', exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        recordForm(page, 'プロジェクト').getByRole('button', {
            name: '更新',
            exact: true,
        }),
    );

    const restored = await openDetailTab(
        page,
        '経費精算の電子化',
        'マイルストーン 1',
    );
    await expect(restored.getByRole('row')).toHaveCount(2);
});

test('同じプロジェクトを 2 つのページで編集すると、後の保存は開き直すまで拒まれ、先に足した行が残る', async ({
    page,
}) => {
    const stale =
        'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。';
    await page.goto('/records/data/project');
    await listRegion(page, 'プロジェクト')
        .getByRole('link', { name: '経費精算の電子化', exact: true })
        .click();
    await page.getByRole('link', { name: '編集', exact: true }).click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+\/edit$/);
    const editUrl = page.url();
    const other = await page.context().newPage();
    await other.goto(editUrl);
    const otherSubmit = recordForm(other, 'プロジェクト').getByRole('button', {
        name: '更新',
        exact: true,
    });

    const form = recordForm(page, 'プロジェクト');
    await form.getByRole('button', { name: '行を追加', exact: true }).click();
    await form
        .getByRole('textbox', { name: '名称(2 行目)', exact: true })
        .fill('E2E の同時編集');
    await form.getByLabel('期日(2 行目)', { exact: true }).fill('2026-09-15');
    await form.getByRole('button', { name: '更新', exact: true }).click();
    await expect(page.getByText('更新しました', { exact: true })).toBeVisible();

    // 古い画面の送信は拒まれる。そのままもう一度押しても(フォームに残った古い値と開いたときの版)同じく拒まれる
    for (let attempt = 0; attempt < 2; attempt++) {
        const sent = other.waitForRequest(
            (request) => request.method() === 'PUT',
        );
        await otherSubmit.click();
        await (await sent).response();
        await expect(
            other.getByRole('alert').filter({ hasText: stale }),
        ).toBeVisible();
        await expect(other).toHaveURL(editUrl);
    }

    await page.reload();
    const kept = await openDetailTab(
        page,
        '経費精算の電子化',
        'マイルストーン 2',
    );
    await expect(kept.getByRole('row').nth(2)).toContainText('E2E の同時編集');

    await other.reload();
    await otherSubmit.click();
    await expect(
        other.getByText('更新しました', { exact: true }),
    ).toBeVisible();
    await other.close();

    // 足した行を消して初期データに戻す
    await page.goto(editUrl);
    await recordForm(page, 'プロジェクト')
        .getByRole('button', { name: '行を削除(2 行目)', exact: true })
        .click();
    await recordForm(page, 'プロジェクト')
        .getByRole('button', { name: '更新', exact: true })
        .click();
    const restored = await openDetailTab(
        page,
        '経費精算の電子化',
        'マイルストーン 1',
    );
    await expect(restored.getByRole('row')).toHaveCount(2);
});

test('日付の欄はクリックでカレンダーを開き、Tab で入っただけでは開かず、打ち直して更新できる', async ({
    page,
}) => {
    const pageErrors: Error[] = [];
    page.on('pageerror', (error) => pageErrors.push(error));
    // ネイティブのカレンダーは開かず、showPicker() が呼ばれた回数だけを数える(DateInput は例外を握るので、回数で挙動を確かめる)
    await page.addInitScript(() => {
        const counter = { calls: 0 };
        Object.assign(window, { showPickerCounter: counter });
        HTMLInputElement.prototype.showPicker = () => {
            counter.calls += 1;
        };
    });
    const pickerCalls = () =>
        page.evaluate(
            () =>
                (
                    window as unknown as {
                        showPickerCounter: { calls: number };
                    }
                ).showPickerCounter.calls,
        );

    await page.goto('/records/data/project');
    await listRegion(page, 'プロジェクト')
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();
    await page.getByRole('link', { name: '編集', exact: true }).click();
    const form = recordForm(page, 'プロジェクト');
    const startDate = fieldGroup(page, 'プロジェクト', '開始日').locator(
        'input',
    );
    const original = await startDate.inputValue();

    // 直前の止まり先(開始日の見出し「期間と予算」のボタン)から Tab で入っただけでは開かない
    await form.getByRole('button', { name: /^期間と予算/ }).focus();
    await page.keyboard.press('Tab');
    await expect(startDate).toBeFocused();
    expect(await pickerCalls()).toBe(0);

    await startDate.click();
    expect(await pickerCalls()).toBe(1);

    await startDate.fill(original);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '更新', exact: true }),
    );

    await expect(page.getByText('更新しました', { exact: true })).toBeVisible();
    expect(pageErrors).toHaveLength(0);
});

test('ゲストのサイドバーに「管理」は無く、管理の業務モジュールの一覧とモジュール分類の画面は 403', async ({
    page,
}) => {
    await page.goto('/');

    await expect(
        page
            .getByRole('navigation', { name: 'グローバルナビ' })
            .getByRole('heading', { level: 2, name: '管理', exact: true }),
    ).toHaveCount(0);

    const response = await page.goto('/records/admin/modules');

    expect(response?.status()).toBe(403);

    const categories = await page.goto('/records/admin/categories');

    expect(categories?.status()).toBe(403);
});
