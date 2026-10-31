import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    addedProjectStart,
    fillVersionTwoBaseDate,
} from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';
import * as home from '../support/workflow-home';

// ワークフローの画面(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 9 節の demo)。デモユーザー切替で利用者を替え、初期データの案件の見本(ルートの深さ/初期データ.md 4 節)と、
// 筋の中で申請する案件を使う。画面は PC の幅だけを前提にする。筋は前の筋の結果(申請した案件・処理の後の状態)を使うので、順に走らせる
// (申請はレコードを作り、案件が指すレコードは消せないので、E2E の DB は流すたびに作り直す = scripts/ci/front)。
// 同じ秒の提出と更新で注意が出ないことは Feature テスト(tests/Feature/Queries/Workflow/CaseDetailQueryTest.php)で確かめる。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** デモユーザー切替の候補: 役どころ(切替の項目)と、切り替えた後の利用者メニューの表示 */
const USERS = {
    applicant: ['申請者', '佐々木 陽太（東京営業課）'],
    chief: ['上長(課長)', '中村 美咲（東京営業課）'],
    manager: ['部長', '高橋 誠（第一営業部）'],
    administrator: ['システム管理者', '伊藤 涼子（経営企画室）'],
    concurrent: ['兼務(総務・人事)', '吉田 達也（総務部）'],
    accounting: ['経理', '山田 恵（経理部）'],
} as const;

type DemoUser = keyof typeof USERS;

/** 筋の中で申請するプロジェクトと、その案件名(既定 = <フロー名> <表示の項目の値>) */
const PROJECT_NAME = 'E2E の販促計画';
const CASE_NAME = `プロジェクト計画の承認 ${PROJECT_NAME}`;

/** 筋の中で申請した案件の詳細の URL(申請の筋で決まり、後の筋が使う) */
let caseUrl = '';

/**
 * 利用者メニューを開いて利用者を切り替え、切り替えた後の表示を待つ。from は押す前の利用者メニューの表示。
 */
async function switchUser(page: Page, from: string, to: DemoUser) {
    const [role, label] = USERS[to];
    await page
        .getByRole('button', { name: `利用者メニュー：${from}`, exact: true })
        .click();
    await submitAndExpectUrl(
        page,
        page.getByRole('menu').getByRole('menuitem').filter({ hasText: role }),
    );
    await expect(
        page.getByRole('button', {
            name: `利用者メニュー：${label}`,
            exact: true,
        }),
    ).toBeVisible();
}

/**
 * ゲストから利用者に切り替えて、path を開く。
 */
async function openAs(page: Page, user: DemoUser, path: string) {
    await page.goto('/');
    await switchUser(page, 'ゲスト', user);
    await page.goto(path);
}

/**
 * 案件一覧のタブ(名前の先頭が label のリンク。件数を持つタブは名前の後に件数が続く)。
 */
function caseTab(page: Page, label: string) {
    return page
        .getByRole('navigation', { name: '申請のタブ' })
        .getByRole('link', { name: new RegExp(`^${label}`) });
}

/**
 * 参照の選択欄で、検索する語を打って候補を選び、候補の欄が閉じるのを待つ(閉じる途中の欄が残ったまま次の選択欄を開くと、
 * 検索の入力欄が 2 つになる)。
 */
async function pick(
    page: Page,
    combobox: string,
    query: string,
    option: string,
) {
    await page.getByRole('combobox', { name: combobox, exact: true }).click();
    await page.getByPlaceholder('検索する語').fill(query);
    await page.getByRole('option', { name: option, exact: true }).click();
    await expect(page.getByPlaceholder('検索する語')).toHaveCount(0);
}

/**
 * 案件の詳細の見出しの右のボタンで処理のダイアログを開く。
 */
async function openActionDialog(page: Page, trigger: string, title: string) {
    await page
        .getByRole('main')
        .getByRole('button', { name: trigger, exact: true })
        .click();

    return page.getByRole('dialog', { name: title });
}

/**
 * 案件の詳細のフロー情報を表に切り替えて、その表を返す(既定は図 = ルートの深さ/画面/案件の側の画面.md 7 節)。
 */
async function flowTable(page: Page) {
    await page
        .getByRole('group', { name: 'フロー情報の表示' })
        .getByRole('button', { name: '表', exact: true })
        .click();

    return page.getByRole('table', { name: 'フロー情報' });
}

/**
 * 申請書の業務モジュール「プロジェクト計画の申請」の一覧から、筋で申請した申請書の詳細を開く(docs の 設計/ワークフロー/初期データ.md の 3 節)。
 */
async function openProjectRecord(page: Page) {
    await page.goto('/records/data/project_plan_application');
    await page
        .getByRole('region', { name: 'プロジェクト計画の申請の一覧' })
        .getByRole('link', { name: PROJECT_NAME, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name: PROJECT_NAME }),
    ).toBeVisible();
}

/**
 * ホームの面(h2 が heading の節。表・タイル・経路の面)。
 */
function homePanel(page: Page, heading: string) {
    return page
        .getByRole('main')
        .locator('section')
        .filter({
            has: page.getByRole('heading', {
                level: 2,
                name: heading,
                exact: true,
            }),
        });
}

/**
 * ホームの今月の処理済みの表の、データの行の結果の列(4 列目)のセル。
 */
function resultCells(page: Page, table: ReturnType<Page['getByRole']>) {
    return table
        .getByRole('row')
        .filter({ has: page.getByRole('cell') })
        .locator('td:nth-child(4)');
}

/**
 * ホームの件数カードの 1 枚(名前の先頭が label のリンク)。
 */
function counterCard(page: Page, label: string) {
    return page
        .getByRole('region', { name: '件数' })
        .getByRole('link', { name: new RegExp(`^${label}`) });
}

/**
 * サイドバー(グローバルナビ)の「申請一覧」。
 */
function sidebarCaseList(page: Page) {
    return page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .getByRole('link', { name: '申請一覧', exact: true });
}

test('初期データの見本: 上長のホームに、あいさつ欄・件数カード・未処理の申請と今月の処理済みの表・主な操作が出る', async ({
    page,
}) => {
    await openAs(page, 'chief', '/');

    await expect(
        page.getByRole('heading', { level: 1, name: /^こんにちは、/ }),
    ).toBeVisible();
    // 件数カード(4 枚)。数字と内訳の 1 行(設計/ワークフロー/案件の運用/初期データ.md 4 節: u0002 = 未処理 3(期限超過なし = WF-000005 は当日)・
    // 進行中 0・一時保存 0。今月の処理済みは流す日の日付で変わる = home.chiefProcessedThisMonth)
    await expect(counterCard(page, '未処理の申請')).toHaveText(
        /^未処理の申請3件承認待ち\s*3$/,
    );
    await expect(counterCard(page, '進行中の申請')).toHaveText(
        /^進行中の申請0件$/,
    );
    const thisMonth = home.chiefProcessedThisMonth();
    await expect(counterCard(page, '今月の処理済み')).toHaveText(
        home.processedCardText(thisMonth),
    );
    await expect(counterCard(page, '一時保存')).toHaveText(/^一時保存0件$/);

    // 未処理の申請の表: WF-000002・WF-000005・WF-000015 の申請名が承認待ちで出る
    const pending = page.getByRole('table', { name: '未処理の申請' });
    await expect(pending.getByRole('columnheader')).toHaveText([
        '申請日',
        '申請名',
        '申請者',
        '状態',
        '期限',
    ]);
    await expect(pending.getByRole('row')).toHaveCount(4);
    for (const name of [
        '課題の対応の承認 導入先の部署が決定していない',
        'プロジェクト計画の承認 代理店向け説明会の開催',
        '課題の対応の承認 在庫システムの表示不具合',
    ]) {
        await expect(
            pending
                .getByRole('row')
                .filter({ has: page.getByRole('link', { name, exact: true }) }),
        ).toContainText('承認待ち');
    }

    // 今月の処理済みの表: 先頭の 5 行(履歴の id の降順)。workflow-route.spec.ts の承認が先頭で、続く見本の処理は今月の分だけ。結果の列の札は
    // 承認 = 印 ✓、差戻し = 印なし(申請名にはフロー名の「承認」が入るので、結果の列のセルで確かめる。否認 × は兼務の利用者のホームで確かめる)
    const processed = page.getByRole('table', { name: '今月の処理済み' });
    const latest = home.latestBadges(thisMonth);
    await expect(processed.getByRole('row')).toHaveCount(latest.length + 1);
    await expect(resultCells(page, processed)).toHaveText(latest);

    // 面の頭のリンク(申請一覧)の行き先は、その案件が入る案件一覧のタブ
    await expect(
        homePanel(page, '未処理の申請').getByRole('link', {
            name: /^申請一覧/,
        }),
    ).toHaveAttribute('href', /\/workflow\/cases\?tab=pending$/);
    await expect(
        homePanel(page, '今月の処理済み').getByRole('link', {
            name: /^申請一覧/,
        }),
    ).toHaveAttribute('href', /\/workflow\/cases\?tab=processed$/);

    // 主な操作の 4 枚(行き先のあるものだけ)
    const quick = homePanel(page, '主な操作');
    await expect(quick.getByRole('link')).toHaveText([
        '申請する',
        '承認する',
        '自分の申請',
        '組織図',
    ]);
    await expect(
        quick.getByRole('link', { name: '申請する', exact: true }),
    ).toHaveAttribute('href', /\/workflow\/flows$/);
    await expect(
        quick.getByRole('link', { name: '組織図', exact: true }),
    ).toHaveAttribute('href', /\/master\/organization-chart$/);
});

test('初期データの見本: 兼務の利用者のホームの今月の処理済みに、今月の合議の否認が印 × で出る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/');
    // u0009 = 合議の票(WF-000009 = D − 5・000012 の承認、WF-000011 = D − 12 の否認 = 案件の運用/初期データ.md 4.2)と route の承認。否認は今月の分だけ
    const processed = page.getByRole('table', { name: '今月の処理済み' });
    const rejected = processed.getByRole('row').filter({
        has: page.getByRole('link', {
            name: 'プロジェクト計画の承認 海外展示会への出展',
            exact: true,
        }),
    });
    await expect(rejected.locator('td:nth-child(4)')).toHaveText(
        home.badgesIfThisMonth(12, '否認'),
    );
});

test('初期データの見本: 上長のホームの件数カードを押すと未処理のタブ、タイルの「承認する」「自分の申請」で該当のタブが今のタブになり、サイドバーの「申請一覧」が現在地になる', async ({
    page,
}) => {
    await openAs(page, 'chief', '/');
    // ホームにいる間は「申請一覧」は現在地でない
    await expect(sidebarCaseList(page)).not.toHaveAttribute(
        'aria-current',
        'page',
    );

    await counterCard(page, '未処理の申請').click();
    await expect(page).toHaveURL('/workflow/cases?tab=pending');
    await expect(caseTab(page, '未処理')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(caseTab(page, '未処理')).toHaveText('未処理3');

    // タイルの行き先は引数つき(?tab=…)でも、現在地はパスだけで比べる(表示.md 3.2)
    await page.goto('/');
    await homePanel(page, '主な操作')
        .getByRole('link', { name: '承認する', exact: true })
        .click();
    await expect(page).toHaveURL('/workflow/cases?tab=pending');
    await expect(caseTab(page, '未処理')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(sidebarCaseList(page)).toHaveAttribute('aria-current', 'page');

    await page.goto('/');
    await homePanel(page, '主な操作')
        .getByRole('link', { name: '自分の申請', exact: true })
        .click();
    await expect(page).toHaveURL('/workflow/cases?tab=processed');
    await expect(caseTab(page, '処理済')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(sidebarCaseList(page)).toHaveAttribute('aria-current', 'page');
});

test('初期データの見本: 申請者のホームの進行中の申請に、保留中の案件がノードの状態どおりのステップの点と線で出る', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/');

    // u0001 = 未処理 1・進行中 10(2 段目の 4・版 3 の 2・案件の運用の 3・workflow-route.spec.ts の申請 1)・今月の処理済み 0・一時保存 1
    await expect(counterCard(page, '未処理の申請')).toHaveText(
        /^未処理の申請1件再申請待ち\s*1$/,
    );
    await expect(counterCard(page, '進行中の申請')).toHaveText(
        /^進行中の申請10件承認待ち\s*8・再申請待ち\s*1・保留中\s*1$/,
    );
    await expect(counterCard(page, '今月の処理済み')).toHaveText(
        /^今月の処理済み0件$/,
    );
    await expect(counterCard(page, '一時保存')).toHaveText(/^一時保存1件$/);

    // 経路は先頭の 3 件(更新の新しい順 = route の申請・WF-000013 = D − 1・WF-000014 = D − 2。WF-000009・000004 は出ない)。WF-000013 は部長が
    // 保留中: ステップはノードの状態どおり(通過の分岐は処理済み、保留中は現在のステップ = 案件の運用/認可と一覧.md の 2.4 節)
    const routes = homePanel(page, '進行中の申請');
    await expect(routes.getByRole('list', { name: 'ステップ' })).toHaveCount(3);
    const title = 'プロジェクト計画の承認 新製品の発売計画';
    const item = routes
        .getByRole('listitem')
        .filter({ has: page.getByRole('link', { name: title, exact: true }) });
    await expect(item).toContainText('保留中：部長');
    await expect(
        item.getByRole('list', { name: 'ステップ' }).getByRole('listitem'),
    ).toHaveText([
        /^申請\s*\(処理済み\)$/,
        /^課長\s*\(処理済み\)$/,
        /^予算\s*\(処理済み\)$/,
        /^部長\s*\(保留中\)$/,
        /^分岐終了\s*\(到達していない\)$/,
        /^関係部署の合議\s*\(到達していない\)$/,
        /^責任者\s*\(到達していない\)$/,
    ]);
    await expect(item.locator('li[aria-current="step"]')).toHaveCount(1);
    // 「再申請」は再申請待ちの案件だけ(先頭の 3 件は承認待ちと保留中)
    await expect(routes.getByRole('link', { name: /^再申請/ })).toHaveCount(0);
});

test('初期データの見本: 部長の承認待ちを上長が引戻せる', async ({ page }) => {
    await openAs(page, 'chief', '/workflow/cases?tab=pending');

    await expect(page).toHaveURL('/workflow/cases?tab=pending');
    await expect(caseTab(page, '未処理')).toHaveAttribute(
        'aria-current',
        'page',
    );
    await expect(caseTab(page, '未処理')).toHaveText('未処理3');

    // 引戻しは詳細の見出しから行う(成功は同じ詳細へのリダイレクトで、ページの部品が残る。差戻しの既定が開いた時の候補から作られることを後で確かめる)
    await page.goto('/workflow/cases?tab=processed');
    await page
        .getByRole('table', { name: '処理済の申請' })
        .getByRole('link', {
            name: 'プロジェクト計画の承認 展示会への出展の詳細',
            exact: true,
        })
        .click();
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: 'プロジェクト計画の承認 展示会への出展',
        }),
    ).toBeVisible();
    const detailUrl = page.url();
    const dialog = await openActionDialog(page, '引戻し', '引戻す');
    await expect(dialog).toContainText(
        '次のステップの処理担当者が処理する前に、自分のステップへ戻します。',
    );
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        detailUrl,
    );
    await expect(page.getByText('引戻しました', { exact: true })).toBeVisible();
    await expect(
        (await flowTable(page))
            .getByRole('row')
            .filter({ hasText: '課長の承認' }),
    ).toContainText('承認待ち');

    // 同じ詳細の画面のまま、引戻しで差戻しできるようになった差戻しのダイアログの既定は候補の先頭(案件は変えずにキャンセル)。
    // キャンセルの後は、押したボタンへフォーカスが戻る
    const sendBack = await openActionDialog(page, '差戻し', '差戻しする');
    await expect(
        sendBack.getByRole('combobox', { name: '差戻し先' }),
    ).toHaveText('申請');
    await sendBack
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(sendBack).toHaveCount(0);
    await expect(
        page
            .getByRole('main')
            .getByRole('button', { name: '差戻し', exact: true }),
    ).toBeFocused();
});

test('承認のダイアログを開いた直後は、フォーカスがコメントの欄にあり、ヘルプの Tooltip は表示されない', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/cases');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', {
            name: 'プロジェクト計画の承認 代理店向け説明会の開催',
            exact: true,
        })
        .click();

    const dialog = await openActionDialog(page, '承認', '承認する');

    await expect(
        dialog.getByRole('textbox', { name: 'コメント' }),
    ).toBeFocused();
    // Tooltip は描画が 1 フレーム遅れるので、2 フレーム待ってから出ていないことを確かめる
    await page.evaluate(
        () =>
            new Promise((resolve) =>
                requestAnimationFrame(() => requestAnimationFrame(resolve)),
            ),
    );
    await expect(page.getByRole('tooltip')).toHaveCount(0);
});

test('一時保存のタブで、申請できる人の行は申請名がリンクになり、申請できない人の行(can.editDraft が偽)は文字だけになる', async ({
    page,
}) => {
    const draftName = '課題の対応の承認 請求書の様式の変更';
    const draftTable = page.getByRole('table', { name: '一時保存の申請' });

    await openAs(page, 'applicant', '/workflow/cases?tab=draft');
    await expect(
        draftTable.getByRole('link', { name: draftName, exact: true }),
    ).toBeVisible();

    // 初期データに申請できなくなった人の一時保存は無いので、タブを移る時の Inertia の応答の行の can.editDraft を偽にして返す
    await page.goto('/workflow/cases');
    await page.route(
        (url) =>
            url.pathname === '/workflow/cases' &&
            url.searchParams.get('tab') === 'draft',
        async (route) => {
            if (route.request().headers()['x-inertia'] === undefined) {
                await route.continue();

                return;
            }

            const response = await route.fetch();
            const json = await response.json();
            for (const row of json.props.cases.data) {
                row.can.editDraft = false;
            }
            await route.fulfill({ response, json });
        },
    );
    await caseTab(page, '一時保存').click();

    await expect(page).toHaveURL('/workflow/cases?tab=draft');
    await expect(
        draftTable.getByText(draftName, { exact: true }),
    ).toBeVisible();
    await expect(
        draftTable.getByRole('link', { name: draftName, exact: true }),
    ).toHaveCount(0);
});

test('システム管理者のサイドバーで「ワークフロー」は「業務」の後、「管理」の前に並ぶ', async ({
    page,
}) => {
    await openAs(page, 'administrator', '/');

    const headings = await page
        .getByRole('navigation', { name: 'グローバルナビ' })
        .getByRole('heading', { level: 2 })
        .allTextContents();

    expect(headings.indexOf('業務')).toBeGreaterThanOrEqual(0);
    expect(headings.indexOf('ワークフロー')).toBeGreaterThan(
        headings.indexOf('業務'),
    );
    expect(headings.indexOf('管理')).toBeGreaterThan(
        headings.indexOf('ワークフロー'),
    );
});

test('申請者が一時保存すると一時保存のタブの件数が 1 増え、一時保存から申請すると詳細に移動し、レコードは業務データの画面で変更できない', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/workflow/cases?tab=draft');
    const draftsBefore = Number(
        ((await caseTab(page, '一時保存').textContent()) ?? '').replace(
            /\D/g,
            '',
        ),
    );

    await page.goto('/workflow/flows');
    const flows = page.getByRole('table', { name: '申請できるフロー' });
    await expect(flows.getByRole('row')).toHaveCount(3);
    await flows
        .getByRole('link', {
            name: 'プロジェクト計画の承認を申請する',
            exact: true,
        })
        .click();

    await expect(page).toHaveURL('/workflow/flows/project_plan/apply');
    await expect(
        page.getByRole('heading', { level: 1, name: 'プロジェクト計画の承認' }),
    ).toBeVisible();
    const form = page.getByRole('form', {
        name: 'プロジェクト計画の承認の入力',
    });
    const caseName = form.getByRole('textbox', { name: '申請名' });
    await expect(caseName).toHaveAttribute(
        'placeholder',
        'プロジェクト計画の承認',
    );

    await form
        .getByRole('textbox', { name: 'プロジェクト名', exact: true })
        .fill(PROJECT_NAME);
    await expect(caseName).toHaveAttribute('placeholder', CASE_NAME);
    await pick(page, '担当部署', '東京営業', '東京営業課');
    await pick(page, '責任者', '中村', '中村 美咲');
    await form
        .getByRole('group', { name: '開始日', exact: true })
        .locator('input')
        .fill(addedProjectStart());
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill('100');
    // 申請基準日を 2 段目の見本と同じ版 2 の日にする(一本道 A = 課長の承認・部長の承認。後の筋の差戻し・再申請・承認を 2 段目の形で確かめる)。
    await fillVersionTwoBaseDate(form);
    await submitAndExpectUrl(
        page,
        form.getByRole('button', { name: '一時保存', exact: true }),
        /\/workflow\/drafts\/\d+\/edit$/,
    );
    await expect(
        page.getByText('一時保存しました', { exact: true }),
    ).toBeVisible();
    const draftUrl = page.url();

    await page.goto('/workflow/cases?tab=draft');
    await expect(caseTab(page, '一時保存')).toHaveText(
        `一時保存${draftsBefore + 1}`,
    );

    await page.goto(draftUrl);
    await form.getByRole('button', { name: '申請', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: '申請しますか？' });
    await expect(confirm).toContainText(
        `${CASE_NAME}を申請します。申請すると、課長の承認の中村 美咲さんの未処理に表示されます。`,
    );
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '決定', exact: true }),
        /\/workflow\/cases\/\d+$/,
    );
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { level: 1, name: CASE_NAME }),
    ).toBeVisible();
    caseUrl = page.url();
    await expect(page.getByRole('region', { name: '基本情報' })).toContainText(
        /WF-\d{6}/,
    );
    const flowRows = (await flowTable(page)).getByRole('row');
    await expect(flowRows.nth(1)).toContainText('処理済み(申請)');
    await expect(flowRows.filter({ hasText: '課長の承認' })).toContainText(
        '承認待ち',
    );

    await page.goto('/workflow/cases?tab=processed');
    await expect(
        page
            .getByRole('table', { name: '処理済の申請' })
            .getByRole('button', { name: `${CASE_NAME}の引戻し`, exact: true }),
    ).toBeVisible();

    await openProjectRecord(page);
    await expect(
        page.getByText('申請済みのため変更できません', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: '編集', exact: true }),
    ).toBeDisabled();
    await expect(
        page.getByRole('button', { name: '削除', exact: true }),
    ).toBeDisabled();

    await page.goto(`${page.url()}/edit`);
    await expect(
        page.getByText('申請済みのため変更できません', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('form', { name: 'プロジェクト計画の申請の入力' })
            .getByRole('button', { name: '更新', exact: true }),
    ).toBeDisabled();
});

test('上長が差戻し先の「申請」を選択して差戻すと、申請者の未処理に再申請待ちで表示され、再申請の差分に予算の変更前・変更後が表示される', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/cases');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', { name: CASE_NAME, exact: true })
        .click();

    const dialog = await openActionDialog(page, '差戻し', '差戻しする');
    const target = dialog.getByRole('combobox', { name: '差戻し先' });
    await expect(target).toHaveText('申請');
    await target.click();
    await page.getByRole('option', { name: '申請', exact: true }).click();
    await dialog
        .getByRole('textbox', { name: 'コメント' })
        .fill('予算を再検討してください。');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        caseUrl,
    );
    await expect(page.getByText('差戻しました', { exact: true })).toBeVisible();
    // 上長のホームの今月の処理済みの先頭がこの差戻しで、結果の札は印なし
    await page.goto('/');
    const latest = resultCells(
        page,
        page.getByRole('table', { name: '今月の処理済み' }),
    ).first();
    await expect(latest).toHaveText('差戻し');
    await expect(latest.locator('[aria-hidden="true"]')).toHaveCount(0);

    await switchUser(page, USERS.chief[1], 'applicant');
    await page.goto('/workflow/cases');
    // 未処理のタブの現在のステップは「申請」。未処理の行は操作の列を持たないので、再申請・取止めは処理済のタブの行から(案件の画面.md の 2 節)
    const pendingRow = page.getByRole('row').filter({ hasText: CASE_NAME });
    await expect(
        pendingRow.getByRole('cell', { name: '申請', exact: true }),
    ).toBeVisible();
    await page.goto('/workflow/cases?tab=processed');
    const row = page.getByRole('row').filter({ hasText: CASE_NAME });
    await expect(row).toContainText('再申請待ち');
    await expect(
        row.getByRole('button', { name: `${CASE_NAME}の取止め`, exact: true }),
    ).toBeVisible();
    // 同じ利用者の古いタブで、再申請の前の処理済の一覧を開いておく(再申請の後に、行の取止めを改訂番号の不一致にする)
    const stale = await page.context().newPage();
    await stale.goto('/workflow/cases?tab=processed');
    const staleWithdraw = stale
        .getByRole('table', { name: '処理済の申請' })
        .getByRole('row')
        .filter({ hasText: CASE_NAME })
        .getByRole('button', { name: `${CASE_NAME}の取止め`, exact: true });
    await expect(staleWithdraw).toBeVisible();
    await row
        .getByRole('link', { name: `${CASE_NAME}の再申請`, exact: true })
        .click();

    await expect(page).toHaveURL(/\/workflow\/cases\/\d+\/reapply$/);
    await expect(
        page.getByRole('heading', { level: 1, name: `${CASE_NAME}の再申請` }),
    ).toBeVisible();
    const form = page.getByRole('form', { name: `${CASE_NAME}の再申請の入力` });
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill('150');
    await form.getByRole('button', { name: '再申請', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: '再申請しますか？' });
    await expect(confirm).toContainText(
        '課長の承認の中村 美咲さんの未処理に表示されます。',
    );
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '決定', exact: true }),
        caseUrl,
    );
    await expect(
        page.getByText('再申請しました', { exact: true }),
    ).toBeVisible();

    // 古いタブで行の取止めを決定すると、案件は課長の承認へ進んでいるので不一致になり、描き直した行は取止めを持たないが、ダイアログは残って誤りを表示する
    await staleWithdraw.click();
    const staleDialog = stale.getByRole('dialog', { name: '取止める' });
    await submitAndExpectUrl(
        stale,
        staleDialog.getByRole('button', { name: '決定', exact: true }),
    );
    await expect(staleDialog.getByRole('alert')).toContainText(
        '画面を表示した後に申請が変更されました。再表示してください',
    );
    await expect(
        staleDialog.getByRole('link', { name: '再表示', exact: true }),
    ).toBeVisible();
    await expect(staleWithdraw).toHaveCount(0);
    await stale.close();

    await page.getByRole('button', { name: '差分を表示', exact: true }).click();
    const diff = page.getByRole('table', { name: /^直前の申請 .* との差分$/ });
    const budget = diff.getByRole('row').filter({ hasText: '予算(万円)' });
    await expect(budget.getByRole('cell').nth(1)).toHaveText('100');
    await expect(budget.getByRole('cell').nth(2)).toHaveText('150');
    await expect(
        page.getByRole('button', { name: '差分を非表示', exact: true }),
    ).toBeVisible();
});

test('上長・部長が承認すると完了(承認)になり、完了の後もレコードを業務データの画面で変更できず、経理部長の参照(完了)に表示される', async ({
    page,
}) => {
    await openAs(page, 'chief', caseUrl);
    let dialog = await openActionDialog(page, '承認', '承認する');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
    );
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();

    await switchUser(page, USERS.chief[1], 'manager');
    await page.goto(caseUrl);
    dialog = await openActionDialog(page, '承認', '承認する');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
    );
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: '基本情報' })).toContainText(
        '完了(承認)',
    );

    // 申請した申請書のレコードは完了の後も持ち主が外れない(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節)
    await openProjectRecord(page);
    await expect(
        page.getByText('申請済みのため変更できません', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        page.getByRole('button', { name: '編集', exact: true }),
    ).toBeDisabled();

    await switchUser(page, USERS.manager[1], 'accounting');
    await page.goto('/workflow/cases');
    await caseTab(page, '参照').click();
    await page
        .getByRole('navigation', { name: '未完了と完了の切替' })
        .getByRole('link', { name: '完了', exact: true })
        .click();

    // クエリの並びは URL の組み立てに任せる(タブと切替の値だけを見る)
    await expect(page).toHaveURL(
        (url) =>
            url.pathname === '/workflow/cases' &&
            url.searchParams.get('tab') === 'referenced' &&
            url.searchParams.get('scope') === 'completed',
    );
    await expect(
        page
            .getByRole('table', { name: '参照の申請' })
            .getByRole('link', { name: CASE_NAME, exact: true }),
    ).toBeVisible();
});

test('古い画面からの承認は、申請が変更されたと表示され再表示のリンクが表示される', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/cases');
    const href = await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', {
            name: 'プロジェクト計画の承認 代理店向け説明会の開催',
            exact: true,
        })
        .getAttribute('href');
    const url = new URL(href ?? '', page.url()).pathname;
    const stale = await page.context().newPage();
    await page.goto(url);
    await stale.goto(url);

    const dialog = await openActionDialog(page, '承認', '承認する');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
    );
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();

    const staleDialog = await openActionDialog(stale, '承認', '承認する');
    await submitAndExpectUrl(
        stale,
        staleDialog.getByRole('button', { name: '決定', exact: true }),
    );

    await expect(staleDialog.getByRole('alert')).toContainText(
        '画面を表示した後に申請が変更されました。再表示してください',
    );
    await expect(
        staleDialog.getByRole('link', { name: '再表示', exact: true }),
    ).toBeVisible();
});
