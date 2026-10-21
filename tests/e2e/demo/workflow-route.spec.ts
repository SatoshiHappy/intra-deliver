import { expect, test } from '../support/test';
import type { Browser, Page } from '@playwright/test';
import {
    addedProjectStart,
    hasBaseDate,
    todayInTokyo,
    versionTwoDay,
} from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';

// ワークフローのルートの深さの画面(docs の 設計/ワークフロー/ルートの深さ/画面.md 10 節の demo): 申請の画面の申請基準日、承認のダイアログの
// 指定するステップ、差戻し先の制限、古い画面、申請者承認防止(送る前に知らせる)と、詳細のフロー図(既定)・図と表の切替・合議の進み具合・通らなかった枝。
// 通しの回の分 = 指定の状態の 1 行(省略の知らせ・完了の条件の出す条件)と 0 人の指定の履歴(初期データに無い形は詳細の応答を横取りして書き換える。書き込みはしない)。
// 初期データの版 3(分岐 B = 初期データ.md 3.2)に筋の中で申請する。通らなかった枝(部長を通らない枝)は見本の WF-000010(予算 120)で見る(申請を足すと後の spec の件数が変わるため)。
// 筋は前の筋の結果を使うので順に走らせる。この spec は名前の順で demo/workflow.spec.ts の前に走り、そこで数える件数(u0001 の進行中の申請・u0002 の今月の処理済み)を
// 1 件ずつ増やす(workflow.spec.ts の期待はこの spec の後の値で書く)。合議の子 u0016 はデモユーザー切替の候補に無いので、u0016 の未処理は Feature テストで確かめる。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** デモユーザー切替の候補: 役どころ(切替の項目)と、切り替えた後の利用者メニューの表示 */
const USERS = {
    applicant: ['申請者', '佐々木 陽太（東京営業課）'],
    chief: ['上長(課長)', '中村 美咲（東京営業課）'],
    manager: ['部長', '高橋 誠（第一営業部）'],
    concurrent: ['兼務(総務・人事)', '吉田 達也（総務部）'],
    accounting: ['経理', '山田 恵（経理部）'],
} as const;

type DemoUser = keyof typeof USERS;

/** 筋の中で申請するプロジェクトと、その案件名(既定 = <フロー名> <表示の項目の値>) */
const PROJECT_NAME = 'E2E の合議の計画';
const CASE_NAME = `プロジェクト計画の承認 ${PROJECT_NAME}`;

/** 申請者承認防止で申請者本人だけのときの文言(設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 13 節・画面.md 5 節。課長 = 申請者の所属組織 + 課長 = u0002 だけ) */
const APPLICANT_ONLY =
    '課長の処理対象者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。';

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
 * 別の利用者のブラウザ(別のセッション)で path を開く(同じ案件を 2 人が同時に開く筋)。
 */
async function openInAnotherBrowser(
    browser: Browser,
    user: DemoUser,
    path: string,
): Promise<Page> {
    const context = await browser.newContext({
        viewport: { width: 1280, height: 720 },
    });
    const page = await context.newPage();
    await openAs(page, user, path);

    return page;
}

/**
 * 参照の選択欄で、検索する語を打って候補を選ぶ(業務データの入れ物の欄)。
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
 * 計画のフローの申請の画面で、プロジェクトの欄を埋める(予算で分岐する)。
 */
async function fillPlan(page: Page, budget: string) {
    const form = page.getByRole('form', {
        name: 'プロジェクト計画の承認の入力',
    });
    await form
        .getByRole('textbox', { name: 'プロジェクト名', exact: true })
        .fill(PROJECT_NAME);
    await pick(page, '担当部署', '東京営業', '東京営業課');
    await pick(page, '責任者', '中村', '中村 美咲');
    await form
        .getByRole('group', { name: '開始日', exact: true })
        .locator('input')
        .fill(addedProjectStart());
    await form
        .getByRole('textbox', { name: '予算(万円)', exact: true })
        .fill(budget);

    return form;
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
 * 基本情報の「現在のステップ」の値。
 */
function currentSteps(page: Page) {
    return page
        .getByRole('region', { name: '基本情報' })
        .locator('div')
        .filter({ has: page.getByText('現在のステップ', { exact: true }) })
        .locator('dd');
}

/**
 * 詳細のフロー図(既定の表示)。
 */
function diagram(page: Page) {
    return page.getByRole('group', { name: 'フロー図', exact: true });
}

/**
 * フロー図の中の、名前が name のステップの箱(箱の名前の行は名前だけの文字)。
 */
function diagramStep(page: Page, name: string) {
    return diagram(page)
        .locator('.react-flow__node')
        .filter({ has: page.getByText(name, { exact: true }) });
}

/**
 * フロー図の点線の辺(通らなかった枝とその続き = 画面.md 8 節。辺の線に破線の書式が付く)。
 */
function dashedEdges(page: Page) {
    return diagram(page).locator(
        'path.react-flow__edge-path[style*="stroke-dasharray"]',
    );
}

/**
 * ステップの箱の境界の箱が、図の面の境界の箱の内にある(はみ出して切れていない)。
 */
async function expectInsideDiagram(page: Page, name: string) {
    await expect
        .poll(async () => {
            const outer = await diagram(page).boundingBox();
            const inner = await diagramStep(page, name).boundingBox();

            return (
                outer !== null &&
                inner !== null &&
                inner.x >= outer.x &&
                inner.y >= outer.y &&
                inner.x + inner.width <= outer.x + outer.width &&
                inner.y + inner.height <= outer.y + outer.height
            );
        })
        .toBe(true);
}

/**
 * 合議の進み具合の面(見出し = 縦配置の名前)。
 */
function councilProgress(page: Page) {
    return page.getByRole('region', { name: '関係部署の合議', exact: true });
}

/**
 * 指定するステップの欄で、検索する語を打って候補を選ぶ(候補の名前は「名前 主所属」なので先頭で引く)。
 */
async function addAssignee(
    page: Page,
    section: ReturnType<Page['getByRole']>,
    query: string,
    name: string,
) {
    await section
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill(query);
    await page.getByRole('option', { name: new RegExp(`^${name}`) }).click();
    await expect(page.getByPlaceholder('名前・コードで検索')).toHaveCount(0);
}

/** 詳細の props のうち、横取りで書き換える部分(画面.md 7 節の nodeActions[].designations・pullBackNodes[].blockedReason・histories[].assignments) */
type RewrittenDetailProps = {
    nodeActions: { designations: { kind: string; min: number }[] }[];
    pullBackNodes: { blockedReason: string | null }[];
    histories: {
        action: string;
        assignments: { nodeName: string; people: unknown[] }[];
    }[];
};

/**
 * 詳細の画面の Inertia の応答(X-Inertia の要求 = 一覧のリンクから移るとき)を横取りし、props を change で書き換えて返す(初期データに無い形の描画を確かめる)。
 * 最初の読み込み(X-Inertia の無い要求)はそのまま通す。
 */
async function rewriteDetailProps(
    page: Page,
    detailUrl: string,
    change: (props: RewrittenDetailProps) => void,
) {
    const path = new URL(detailUrl).pathname;
    await page.route(
        (url) => url.pathname === path,
        async (route) => {
            if (route.request().headers()['x-inertia'] === undefined) {
                await route.continue();

                return;
            }

            const response = await route.fetch();
            const json = await response.json();
            change(json.props as RewrittenDetailProps);
            await route.fulfill({ response, json });
        },
    );
}

/**
 * 未処理のタブから、筋の中で申請した案件の詳細へ移る(Inertia の要求で開く = {@link rewriteDetailProps} の横取りが効く)。
 */
async function openCaseFromPending(page: Page) {
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', { name: CASE_NAME, exact: true })
        .click();
    await expect(page).toHaveURL(caseUrl);
}

/**
 * 指定するステップの欄の状態の 1 行(「選択中 {n} 人」と、省略の知らせか完了の条件)。
 */
function assigneeStatus(section: ReturnType<Page['getByRole']>) {
    return section.locator('p[aria-live="polite"]').filter({
        hasText: '選択中',
    });
}

test('申請者: 申請の画面に申請基準日(既定は今日)と担当組織が出て、予算 350 で申請すると詳細に申請基準日と版 3 が出て、課長が承認待ちになる', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/workflow/flows/project_plan/apply');

    const today = todayInTokyo();
    const baseDate = page
        .getByRole('group', { name: '申請基準日', exact: true })
        .locator('input[type="date"]');
    await expect(baseDate).toHaveValue(today);
    await expect(page.getByRole('region', { name: '申請情報' })).toContainText(
        '東京営業課',
    );
    // ラベルの横の「?」にフォーカスすると説明が出る(入力欄の説明の Tooltip = 画面.md 9.2)
    await page
        .getByRole('button', { name: '申請基準日の説明', exact: true })
        .focus();
    await expect(page.getByRole('tooltip')).toContainText(
        '既定値は今日の日付です。',
    );

    // 申請基準日を続けて 2 回変え、1 本目(版 2 の日)の要求を止めておく。2 本目を送る直前に 1 本目が取り消されるので、止めを外しても 1 本目の応答は
    // 画面に届かず、欄の日付(今日 = 版 3)の次の処理対象者と URL が残る(取り消しが壊れていれば、止めを外した後に古い応答が届いて下の確かめが落ちる)。
    // 止めて横取りした要求は取り消されても requestfailed が届かないので、届いた応答の有無で確かめる。版 2 の日 = support の versionTwoDay
    const isFirst = (url: URL) =>
        url.searchParams.get('base_date') === versionTwoDay();
    let releaseFirst: () => void = () => {};
    const firstHeld = new Promise<void>((resolve) => {
        releaseFirst = resolve;
    });
    let firstHandled: Promise<void> = Promise.resolve();
    await page.route(isFirst, (route) => {
        firstHandled = (async () => {
            await firstHeld;
            await route.continue();
        })();

        return firstHandled;
    });
    const firstResponses: string[] = [];
    page.on('response', (response) => {
        if (isFirst(new URL(response.url()))) {
            firstResponses.push(response.url());
        }
    });
    await baseDate.fill(versionTwoDay());
    await baseDate.fill(today);
    releaseFirst();
    await firstHandled;
    await page.unrouteAll({ behavior: 'wait' });
    await expect(page).toHaveURL(hasBaseDate(today));
    expect(firstResponses).toEqual([]);
    await expect(
        page.getByText(
            /申請すると、課長の中村 美咲さんの未処理に表示されます。$/,
        ),
    ).toBeVisible();
    await expect(page.getByText(/課長の承認の/)).toHaveCount(0);

    const form = await fillPlan(page, '350');
    await form.getByRole('button', { name: '申請', exact: true }).click();
    const confirm = page.getByRole('dialog', { name: '申請しますか？' });
    await expect(confirm).toContainText(
        `${CASE_NAME}を申請します。申請すると、課長の中村 美咲さんの未処理に表示されます。`,
    );
    await submitAndExpectUrl(
        page,
        confirm.getByRole('button', { name: '決定', exact: true }),
        /\/workflow\/cases\/\d+$/,
    );
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    caseUrl = page.url();
    const basic = page.getByRole('region', { name: '基本情報' });
    await expect(basic).toContainText(today.replaceAll('-', '/'));
    await expect(basic).toContainText('版 3');
    await expect(currentSteps(page)).toHaveText('課長');

    // フロー情報の既定は図: 凡例の札 3 つ、課長の箱が承認待ち、まだ到達していない責任者、最後の後に「終了」の印(画面.md 7・8 節)
    await expect(
        page.getByRole('list', { name: '凡例' }).getByRole('listitem'),
    ).toHaveText(['処理済み', '承認待ち', '到達していない']);
    await expect(diagramStep(page, '申請')).toContainText('処理済み(申請)');
    await expect(diagramStep(page, '課長')).toContainText('中村 美咲');
    await expect(diagramStep(page, '課長')).toContainText('承認待ち');
    await expect(diagramStep(page, '責任者')).toContainText('課長が指定');
    await expect(diagramStep(page, '責任者')).toContainText('到達していない');
    await expect(
        diagram(page).getByText('終了', { exact: true }),
    ).toBeVisible();
    await expect(councilProgress(page)).toHaveCount(0);
    // 課長がまだ合議の人を指定していないので、合議の枠に指定の前の文(画面.md 8 節)
    await expect(
        diagram(page).getByText('処理対象者はまだ指定されていません', {
            exact: true,
        }),
    ).toBeVisible();

    // 「表」に切り替えると同じ情報を表で見られ(状態の語は図と同じ)、凡例は図のときだけ
    await page
        .getByRole('group', { name: 'フロー情報の表示' })
        .getByRole('button', { name: '表', exact: true })
        .click();
    const rows = page
        .getByRole('table', { name: 'フロー情報' })
        .getByRole('row');
    await expect(rows.filter({ hasText: '課長' }).first()).toContainText(
        '承認待ち',
    );
    await expect(rows.nth(1)).toContainText('処理済み(申請)');
    await expect(rows.filter({ hasText: '予算' })).toContainText(
        '到達していない',
    );
    await expect(diagram(page)).toHaveCount(0);
    await expect(page.getByRole('list', { name: '凡例' })).toHaveCount(0);
});

test('最小 0 の知らせ: 合議の最小を 0 にした詳細の応答で上長が承認のダイアログを開くと、0 人のときは「このステップは省略されます。」が出て、完了の条件は出ない', async ({
    page,
}) => {
    // 初期データに最小 0 のステップが無いので、詳細の応答の縦配置の指定の最小を 0 にして返す(画面.md 6 節、通しの回の T03 = S5)
    await openAs(page, 'chief', '/workflow/cases?tab=pending');
    await rewriteDetailProps(page, caseUrl, (props) => {
        for (const action of props.nodeActions) {
            for (const designation of action.designations) {
                if (designation.kind === 'parallel') {
                    designation.min = 0;
                }
            }
        }
    });
    await openCaseFromPending(page);

    const dialog = await openActionDialog(page, '承認', '承認する');
    const council = dialog.getByRole('region', {
        name: '指定するステップ　縦配置 関係部署の合議',
    });
    await expect(assigneeStatus(council)).toHaveText(
        '選択中 0 人　このステップは省略されます。',
    );
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page.unrouteAll({ behavior: 'wait' });
});

test('上長: 承認のダイアログに指定するステップが 2 つ出て、合議に 3 人・責任者に部長を指定して承認すると、部長が承認待ちになる(予算 300 万円以上)', async ({
    page,
}) => {
    await openAs(page, 'chief', caseUrl);
    const dialog = await openActionDialog(page, '承認', '承認する');
    const council = dialog.getByRole('region', {
        name: '指定するステップ　縦配置 関係部署の合議',
    });
    const owner = dialog.getByRole('region', {
        name: '指定するステップ　承認 責任者',
    });
    await expect(council).toBeVisible();
    await expect(owner).toBeVisible();
    // 完了の条件は 1 人以上かつ選んだ人数が必要な人数以上のときだけ(合議の最小は 2 なので 0 人でも省略の知らせは出ない = 画面.md 6 節、通しの回の T03)
    await expect(assigneeStatus(council)).toHaveText('選択中 0 人');

    await addAssignee(page, council, 'u0009', '吉田 達也');
    await expect(assigneeStatus(council)).toHaveText('選択中 1 人');
    await addAssignee(page, council, 'u0010', '山田 恵');
    await addAssignee(page, council, 'u0016', '清水 美穂');
    await expect(council).toContainText('選択中 3 人');
    await expect(council).toContainText(
        '完了の条件 3 人中 2 人が承認すると、合議は完了します。',
    );
    await addAssignee(page, owner, 'u0003', '高橋 誠');
    await expect(owner).toContainText('選択中 1 人');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
    );

    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();
    await expect(currentSteps(page)).toHaveText('部長');
    await expect(
        page.getByRole('region', { name: '履歴' }).getByText(/^指定: /),
    ).toHaveText([
        '指定: 関係部署の合議 = 吉田 達也、山田 恵、清水 美穂',
        '指定: 責任者 = 高橋 誠',
    ]);
    // 指定した後は、合議に到達する前(子の箱がまだ無い間)でも指定の前の文を出さない
    await expect(diagram(page)).toContainText('縦配置「関係部署の合議」');
    await expect(
        diagram(page).getByText('処理対象者はまだ指定されていません'),
    ).toHaveCount(0);
});

test('0 人の指定の履歴: 承認の行の指定のうち人のいない指定は「指定: {ステップ} = 省略」と出る', async ({
    page,
}) => {
    // 初期データに 0 人の指定が無いので、詳細の応答の課長の承認の行の合議の指定を 0 人にして返す(画面.md 7 節、通しの回の T08 = S6)
    await openAs(page, 'manager', '/workflow/cases?tab=pending');
    await rewriteDetailProps(page, caseUrl, (props) => {
        for (const history of props.histories) {
            for (const assignment of history.assignments) {
                if (assignment.nodeName === '関係部署の合議') {
                    assignment.people = [];
                }
            }
        }
    });
    await openCaseFromPending(page);

    await expect(
        page.getByRole('region', { name: '履歴' }).getByText(/^指定: /),
    ).toHaveText(['指定: 関係部署の合議 = 省略', '指定: 責任者 = 高橋 誠']);
    await page.unrouteAll({ behavior: 'wait' });
});

test('引戻しの申請者承認防止: 引戻せるステップに防止の文言がある詳細の応答で上長が引戻しのダイアログを開くと、「引戻しできません」と文言が出て「決定」を押せない', async ({
    page,
}) => {
    // 初期データの版 3 では申請者が防止のステップの処理対象者にならないので、詳細の応答の引戻せるステップに文言を入れて返す(画面.md 6 節、通しの回の T02・解消確認の R09)
    const reason =
        '課長の承認の処理対象者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。';
    await openAs(page, 'chief', '/workflow/cases?tab=processed');
    await rewriteDetailProps(page, caseUrl, (props) => {
        for (const node of props.pullBackNodes) {
            node.blockedReason = reason;
        }
    });
    await page
        .getByRole('main')
        .getByRole('link', { name: CASE_NAME, exact: true })
        .click();
    await expect(page).toHaveURL(caseUrl);

    const dialog = await openActionDialog(page, '引戻し', '引戻す');
    const alert = dialog.getByRole('alert');
    await expect(alert).toContainText('引戻しできません');
    await expect(alert).toContainText(reason);
    await expect(
        dialog.getByRole('button', { name: '決定', exact: true }),
    ).toBeDisabled();
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await page.unrouteAll({ behavior: 'wait' });
});

test('部長: 承認すると合議の 3 人が承認待ちになり、現在のステップが 3 つ並ぶ', async ({
    page,
}) => {
    await openAs(page, 'manager', caseUrl);
    const dialog = await openActionDialog(page, '承認', '承認する');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
    );

    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();
    await expect(currentSteps(page)).toHaveText(
        '関係部署の合議 承認 1、関係部署の合議 承認 2、関係部署の合議 承認 3',
    );

    // 図: 予算 350 なので「何もしない」の枝が通らなかった枝(点線と語)、部長は処理済み、縦配置の枠に完了の条件。合議の進み具合は 0 人が承認・必要 2 人
    await expect(
        diagram(page).getByText('何もしない(通らなかった枝)', { exact: true }),
    ).toBeVisible();
    await expect(diagramStep(page, '部長')).toContainText('処理済み(承認)');
    await expect(diagram(page)).toContainText('縦配置「関係部署の合議」');
    await expect(diagram(page)).toContainText(
        '完了の条件 承認 2 人　現在 0 人',
    );
    await expect(diagramStep(page, '関係部署の合議 承認 1')).toContainText(
        '吉田 達也',
    );
    await expect(diagramStep(page, '関係部署の合議 承認 1')).toContainText(
        '承認待ち',
    );
    const progress = councilProgress(page);
    await expect(progress).toContainText('0 人が承認　必要 2 人');
    await expect(progress).toContainText(
        '未処理 3 人。2 人が承認すると、合議は完了します。',
    );
    await expect(progress.getByRole('listitem')).toHaveCount(3);
    // 承認の後に配置と面の幅(進み具合の面が出る)が変わっても、全体が入る表示をやり直すので、合議の枠の右端の子の箱が図の面の中に見える
    await expectInsideDiagram(page, '関係部署の合議 承認 3');
});

test('差戻し先の制限: 合議の子の差戻しのダイアログの差戻し先は「申請」「課長」だけで、制限の 1 行が出る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', caseUrl);
    const dialog = await openActionDialog(page, '差戻し', '差戻しする');
    const target = dialog.getByRole('combobox', { name: '差戻し先' });
    await expect(target).toHaveText('申請');
    await expect(dialog).toContainText(
        'このステップで差戻しできるのは、定義で許可された申請と課長のみです。',
    );
    await target.click();
    await expect(page.getByRole('option')).toHaveText(['申請', '課長']);
    await page.keyboard.press('Escape');
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
});

test('古い画面: 合議の 2 人が同じ詳細を開き、先に 1 人が承認した後にもう 1 人が承認すると変更されたと出て、再表示すると承認でき、合議が完了して責任者が承認待ちになる', async ({
    page,
    browser,
}) => {
    await openAs(page, 'concurrent', caseUrl);
    const stale = await openInAnotherBrowser(browser, 'accounting', caseUrl);

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
    await staleDialog
        .getByRole('link', { name: '再表示', exact: true })
        .click();

    // 再表示した詳細の合議の進み具合: 1 人(吉田 達也)が承認・必要 2 人、自分(山田 恵)の行に「自分」の札(画面.md 10 節)
    const progress = councilProgress(stale);
    await expect(progress).toContainText('1 人が承認　必要 2 人');
    await expect(progress).toContainText(
        '未処理 2 人。2 人が承認すると、合議は完了します。',
    );
    await expect(
        progress.getByRole('listitem').filter({ hasText: '吉田 達也' }),
    ).toContainText('処理済み(承認)');
    await expect(
        progress.getByRole('listitem').filter({ hasText: '山田 恵' }),
    ).toContainText('自分');

    const again = await openActionDialog(stale, '承認', '承認する');
    await submitAndExpectUrl(
        stale,
        again.getByRole('button', { name: '決定', exact: true }),
    );
    await expect(
        stale.getByText('承認しました', { exact: true }),
    ).toBeVisible();
    await expect(currentSteps(stale)).toHaveText('責任者');
    // 可決: 合議は完了し(残りの子 = 清水 美穂は省略)、責任者の箱が承認待ち
    await expect(councilProgress(stale)).toContainText('2 人が承認　必要 2 人');
    await expect(councilProgress(stale)).toContainText('合議は完了しました。');
    await expect(
        councilProgress(stale)
            .getByRole('listitem')
            .filter({ hasText: '清水 美穂' }),
    ).toContainText('省略');
    await expect(diagramStep(stale, '責任者')).toContainText('承認待ち');
    await stale.context().close();
});

test('申請者承認防止: 上長が版 3 の申請の画面を開くと、課長の処理対象者が申請者本人だけのため、送る前に申請情報の下に文言が出て「申請」を押せない', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/flows/project_plan/apply');
    const form = await fillPlan(page, '120');

    await expect(page.getByRole('region', { name: '申請情報' })).toContainText(
        APPLICANT_ONLY,
    );
    await expect(
        form.getByRole('button', { name: '申請', exact: true }),
    ).toBeDisabled();
});

test('通らなかった枝: 予算 120 の見本 WF-000010 の詳細の図で、部長は省略、分岐の「何もしない」の枝を通り、課長の承認の後の合議が承認待ち', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases?tab=pending');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', {
            name: 'プロジェクト計画の承認 社内研修の刷新',
            exact: true,
        })
        .click();

    await expect(diagramStep(page, '課長')).toContainText('処理済み(承認)');
    await expect(diagramStep(page, '部長')).toContainText('省略');
    await expect(
        diagram(page).getByText('予算 300 万円以上(通らなかった枝)', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        diagram(page).getByText('何もしない', { exact: true }),
    ).toBeVisible();
    // 点線 = 通らなかった枝(予算 → 部長)と、省略の部長から続く辺(部長 → 分岐終了)の 2 本。分岐終了から先は実線
    await expect(dashedEdges(page)).toHaveCount(2);
    await expect(diagramStep(page, '関係部署の合議 承認 1')).toContainText(
        '吉田 達也(自分)',
    );
    await expect(diagramStep(page, '関係部署の合議 承認 1')).toContainText(
        '承認待ち',
    );
    await expect(councilProgress(page)).toContainText('0 人が承認　必要 2 人');
});

test('否認の票と通らなかった枝: 見本 WF-000011(合議が可決できずに否認で完了)の図で、否認した子は「処理済み(否認)」、残りの子と合議は省略、点線は「何もしない」の枝の 1 本だけ', async ({
    page,
}) => {
    await openAs(
        page,
        'concurrent',
        '/workflow/cases?tab=processed&scope=completed',
    );
    await page
        .getByRole('main')
        .getByRole('link', {
            name: 'プロジェクト計画の承認 海外展示会への出展',
            exact: true,
        })
        .click();

    await expect(diagramStep(page, '関係部署の合議 承認 1')).toContainText(
        '処理済み(否認)',
    );
    await expect(diagramStep(page, '関係部署の合議 承認 2')).toContainText(
        '処理済み(否認)',
    );
    await expect(diagramStep(page, '関係部署の合議 承認 3')).toContainText(
        '省略',
    );
    await expect(diagram(page)).toContainText('縦配置「関係部署の合議」');
    await expect(diagramStep(page, '責任者')).toContainText('到達していない');
    // 完了の時の片付けで省いた合議の前後の辺は実線のまま(点線は分岐の「何もしない」の 1 本)
    await expect(
        diagram(page).getByText('何もしない(通らなかった枝)', { exact: true }),
    ).toBeVisible();
    await expect(dashedEdges(page)).toHaveCount(1);
    // 省略の合議には進み具合の面を出さず、指定の前の文も出さない
    await expect(councilProgress(page)).toHaveCount(0);
    await expect(
        diagram(page).getByText('処理対象者はまだ指定されていません'),
    ).toHaveCount(0);
});
