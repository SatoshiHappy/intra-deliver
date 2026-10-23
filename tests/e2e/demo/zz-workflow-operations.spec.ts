import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import { seededDate, shownDate, todayInTokyo } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、申請一覧・一括・ホーム・ページ送り。初期データの見本
// (設計/ワークフロー/案件の運用/初期データ.md 4 節)を使い、案件を変えるので、名前の順で同じ組の spec(workflow-route.spec.ts・workflow.spec.ts)の
// 後に流す。期待は前の spec が案件を変えた後の状態で書き、見本の案件は名指しで探して件数を数えない(数えるのは前の spec が触らない保留中・期限超過だけ)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** デモユーザー切替の候補: 役どころ(切替の項目)と、切り替えた後の利用者メニューの表示 */
const USERS = {
    chief: ['上長(課長)', '中村 美咲（東京営業課）'],
    concurrent: ['兼務(総務・人事)', '吉田 達也（総務部）'],
    accounting: ['経理', '山田 恵（経理部）'],
} as const;

type DemoUser = keyof typeof USERS;

/** 見本の案件(番号は初期データの申請の順) */
const NEW_PRODUCT = 'プロジェクト計画の承認 新製品の発売計画'; // WF-000013(部長 = u0003 が保留中。期限 D − 4 で超過)
const SECOND_QUARTER = 'プロジェクト計画の承認 第二四半期の営業計画'; // WF-000014(部長 = u0003)
const TRAINING = 'プロジェクト計画の承認 社内研修の刷新'; // WF-000010(合議の承認 1 = u0009)
const LOGIN_TEXT = '課題の対応の承認 ログイン画面の文言修正'; // WF-000016(課長の承認 = 担当変更の振替先 u0009)

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
 * 申請一覧の表の、申請名の行(行の選択の欄は申請名で名付く)。
 */
function caseRow(page: Page, table: string, name: string) {
    return page
        .getByRole('table', { name: table })
        .getByRole('row')
        .filter({ has: page.getByRole('link', { name, exact: true }) });
}

/**
 * 未処理の表の、申請名の行の現在のステップ(列の 4 つ目)に step を含み、代理元(5 つ目)が principal。
 */
async function expectPendingStep(
    page: Page,
    name: string,
    step: string,
    principal: string,
) {
    const cells = caseRow(page, '未処理の申請', name).locator('td');
    await expect(cells.nth(3)).toContainText(step);
    await expect(cells.nth(4)).toHaveText(principal);
}

/**
 * 流す日の基準の日付(初期データの値で書いた YYYY-MM-DD)から今日までの日数(経過日数の期待)。
 */
function daysSince(written: string): number {
    return (
        (Date.parse(todayInTokyo()) - Date.parse(seededDate(written))) /
        86_400_000
    );
}

test('u0009 のホーム: 未処理の申請の表に期限の列と保留中・期限超過のセル、件数カードの内訳に保留中と期限超過', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/');

    // 保留中 = WF-000013 だけ。期限超過 = WF-000013(D − 4)だけ(WF-000003 は workflow.spec.ts の引戻しで課長へ戻る)
    await expect(
        page
            .getByRole('region', { name: '件数' })
            .getByRole('link', { name: /^未処理の申請/ }),
    ).toHaveText(/^未処理の申請\d+件承認待ち\s*\d+・保留中\s*1・期限超過\s*1$/);
    const pending = page.getByRole('table', { name: '未処理の申請' });
    await expect(pending.getByRole('columnheader')).toHaveText([
        '申請日',
        '申請名',
        '申請者',
        '状態',
        '期限',
    ]);
    const held = caseRow(page, '未処理の申請', NEW_PRODUCT);
    await expect(held.locator('td').nth(3)).toHaveText('保留中');
    await expect(held.locator('td').nth(4)).toHaveText(
        `${seededDate('2026-10-11')} 期限超過`,
    );
});

test('u0009 の申請一覧の未処理: 代理元・経過日数・期限超過の札・保留中の札が出て、検索の欄で申請番号を入れると行が絞られる', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');

    // 列 = 選択・申請名・申請者・現在のステップ・代理元・経過日数・期限(案件の画面.md の 2 節)
    const cells = caseRow(page, '未処理の申請', NEW_PRODUCT).locator('td');
    await expect(cells.nth(1)).toContainText(
        'WF-000013・プロジェクト計画の承認',
    );
    await expect(cells.nth(3)).toHaveText(/^部長\s*保留中$/);
    await expect(cells.nth(4)).toHaveText('高橋 誠');
    await expect(cells.nth(5)).toHaveText(`${daysSince('2026-10-06')} 日`);
    await expect(cells.nth(6)).toHaveText(
        new RegExp(`^${shownDate('2026-10-11')}\\s*期限超過$`),
    );
    await expect(
        caseRow(page, '未処理の申請', LOGIN_TEXT).locator('td').nth(4),
    ).toHaveText('—');

    await page
        .getByRole('searchbox', { name: '申請番号・申請名で検索' })
        .fill('WF-000013');
    await expect(page).toHaveURL(
        (url) => url.searchParams.get('q') === 'WF-000013',
    );
    const rows = page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('row')
        .filter({ has: page.getByRole('cell') });
    await expect(rows).toHaveCount(1);
    await expect(rows).toContainText(NEW_PRODUCT);
});

test('一括承認: u0009 が未処理で 3 件を選び、ダイアログの表のステップ・代理元を見て決定すると、トーストが出て各行が次のステップへ進む', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');

    for (const name of [SECOND_QUARTER, LOGIN_TEXT, TRAINING]) {
        await page.getByRole('checkbox', { name, exact: true }).check();
    }
    await expect(page.getByText('3 件選択中', { exact: true })).toBeVisible();
    await page
        .getByRole('button', { name: '選択した 3 件を承認', exact: true })
        .click();

    const dialog = page.getByRole('dialog', {
        name: '選択した 3 件を承認する',
    });
    const selected = dialog.getByRole('table', { name: '選択した申請 3 件' });
    // ステップと代理元は行が示した組(WF-000014 = 部長を高橋 誠さんとして、WF-000016 = 課長の承認を本人として)
    await expect(
        selected.getByRole('row').filter({ hasText: SECOND_QUARTER }),
    ).toContainText(/部長\s*高橋 誠$/);
    await expect(
        selected.getByRole('row').filter({ hasText: LOGIN_TEXT }),
    ).toContainText(/課長の承認\s*—$/);
    await expect(dialog).toContainText('1 件ずつ処理します');
    await dialog
        .getByRole('textbox', { name: 'コメント' })
        .fill('内容を確認しました。まとめて承認します。');
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        '/workflow/cases?tab=pending',
    );

    await expect(
        page.getByText('3 件を承認しました', { exact: true }),
    ).toBeVisible();
    await expect(dialog).toHaveCount(0);
    // WF-000010 は合議のほかの子(u0010)が残るので未処理から消える。WF-000014 の次の合議は u0009 が承認者、WF-000016 の次の部長は
    // u0009 の代理元 u0003 なので、2 件は次のステップの行として残る(列 = 案件の画面.md の 2 節)
    await expect(caseRow(page, '未処理の申請', TRAINING)).toHaveCount(0);
    await expectPendingStep(page, SECOND_QUARTER, '関係部署の合議', '—');
    await expectPendingStep(page, LOGIN_TEXT, '部長の承認', '高橋 誠');
    await expect(page.getByText('0 件選択中', { exact: true })).toBeVisible();
});

test('一括確認: u0010 が未確認のタブで 1 件を選んで確認すると、トーストが出て確認済のタブに出る', async ({
    page,
}) => {
    await openAs(page, 'accounting', '/workflow/cases?tab=unconfirmed');

    // 列 = 選択・申請名・申請者・現在のステップ・確認するステップ・確認が開いた日時(WF-000013 は課長の承認の時刻 D − 9 10:30 に開いた)
    const cells = caseRow(page, '未確認の申請', NEW_PRODUCT).locator('td');
    await expect(cells.nth(4)).toHaveText('課長');
    await expect(cells.nth(5)).toHaveText(`${shownDate('2026-10-06')} 10:30`);
    await page
        .getByRole('checkbox', { name: NEW_PRODUCT, exact: true })
        .check();
    await page
        .getByRole('button', { name: '選択した 1 件を確認', exact: true })
        .click();
    const dialog = page.getByRole('dialog', {
        name: '選択した 1 件を確認する',
    });
    await expect(
        dialog
            .getByRole('table', { name: '選択した申請 1 件' })
            .getByRole('row')
            .filter({ hasText: NEW_PRODUCT }),
    ).toContainText(/課長$/);
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        '/workflow/cases?tab=unconfirmed',
    );

    await expect(
        page.getByText('1 件を確認しました', { exact: true }),
    ).toBeVisible();
    await expect(caseRow(page, '未確認の申請', NEW_PRODUCT)).toHaveCount(0);
    await page
        .getByRole('navigation', { name: '申請のタブ' })
        .getByRole('link', { name: '確認済', exact: true })
        .click();
    await expect(page).toHaveURL('/workflow/cases?tab=confirmed');
    await expect(
        caseRow(page, '確認済の申請', NEW_PRODUCT).locator('td').nth(3),
    ).toHaveText('課長');
});

test('検索とタブ: u0009 が未処理で検索の欄に語を打ち、待たずに「未確認」のタブを押すと、未確認のタブが打った語で絞られたまま残る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');

    // 入力の停止の待ち(300ms)の前に押す。待ちが発火しても押したタブを消さない(u0009 は確認する人でないので、未確認は検索の語の 0 件の文になる)
    await page
        .getByRole('searchbox', { name: '申請番号・申請名で検索' })
        .fill('WF-000013');
    const unconfirmed = page
        .getByRole('navigation', { name: '申請のタブ' })
        .getByRole('link', { name: /^未確認/ });
    await unconfirmed.click();

    const isUnconfirmedSearch = (url: URL) =>
        url.searchParams.get('tab') === 'unconfirmed' &&
        url.searchParams.get('q') === 'WF-000013';
    await expect(page).toHaveURL(isUnconfirmedSearch);
    await expect(unconfirmed).toHaveAttribute('aria-current', 'page');
    const table = page.getByRole('table', { name: '未確認の申請' });
    await expect(table).toContainText('該当する申請はありません');

    await page.waitForTimeout(1000);
    await expect(page).toHaveURL(isUnconfirmedSearch);
    await expect(unconfirmed).toHaveAttribute('aria-current', 'page');
    await expect(table).toContainText('該当する申請はありません');
});

test('ページ送り: 通知が 20 件を超えた上長の通知の一覧で、番号の「2」を押すと 2 ページ目に移る', async ({
    page,
    artisan,
}) => {
    // 見本の通知は 1 ページに収まるので、上長の最新の通知を 21 件写して 2 ページにする(この spec の最後の筋なので、ほかの筋の件数を変えない)
    artisan([
        'tinker',
        '--execute',
        [
            '$user = App\\Models\\Master\\User::query()->where("code", "u0002")->sole();',
            '$notice = App\\Models\\Notice\\Notice::query()->where("user_id", $user->id)',
            '->orderByDesc("id")->firstOrFail();',
            'foreach (range(1, 21) as $i) { $notice->replicate()->save(); }',
        ].join(''),
    ]);
    await openAs(page, 'chief', '/notices');

    const pages = page.getByRole('navigation', { name: 'ページ' });
    await pages.getByRole('button', { name: '2', exact: true }).click();
    await expect(page).toHaveURL('/notices?page=2');
    await expect(
        pages.getByRole('button', { name: '2', exact: true }),
    ).toHaveAttribute('aria-current', 'page');
    await expect(
        pages.getByRole('button', { name: '前へ', exact: true }),
    ).toBeEnabled();
});
