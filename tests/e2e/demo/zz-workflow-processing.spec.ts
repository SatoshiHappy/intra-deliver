import { expect, test } from '../support/test';
import type { Locator, Page } from '@playwright/test';
import { shownDate } from '../support/seed-dates';
import { submitAndExpectUrl } from '../support/submit';
import {
    INVENTORY_BUG,
    NEW_PRODUCT,
    SECOND_QUARTER,
    caseRow,
    daysSince,
    openAs,
} from '../support/workflow-operations';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、申請の詳細と処理のダイアログ。初期データの見本
// (設計/ワークフロー/案件の運用/初期データ.md 4 節)の案件を変えるので、名前の順で zz-workflow-operations.spec.ts の後に流す(同じ組)。
// 期待は前の spec までが案件を変えた後の状態で書く。見本の案件の選び方:
// - 代理元として開く詳細・保留・保留解除・代理元としての承認 = NEW_PRODUCT。初期データの筋は WF-000003 だが、workflow.spec.ts の引戻しで課長へ戻り、
//   u0009 は未処理に持たない。NEW_PRODUCT は u0009 が u0003 として保留した案件で、本人としては何も処理できず(代理の 1 行が出る)、
//   保留者側の代理元 u0003 だけが選択肢なので処理担当者の欄が出ない。前の spec は触らない
// - 担当変更 = INVENTORY_BUG(u0002 が課長で承認待ち。前の spec は読むだけ)
// - 確認 = SECOND_QUARTER(zz-workflow-operations.spec.ts の一括承認で部長が処理済みになり、課長・部長の確認が u0010 に開いている)。
//   NEW_PRODUCT は同じ spec の一括確認で確認済みになった
// 連絡と申請の代理の筋(申請の画面)は zz-workflow-proxy.spec.ts。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const PROXY_ACTOR = '吉田 達也(高橋 誠さんの代理)';

/**
 * 一覧の表(既定は未処理)から申請名のリンクを押し、詳細の見出しが出るまで待つ。
 */
async function openCase(
    page: Page,
    name: string,
    table: '未処理の申請' | '未確認の申請' = '未処理の申請',
) {
    await page
        .getByRole('table', { name: table })
        .getByRole('link', { name, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name, exact: true }),
    ).toBeVisible();
}

/**
 * 詳細の見出しの右のボタンで処理のダイアログを開く。
 */
async function openActionDialog(page: Page, trigger: string, title: string) {
    await page
        .getByRole('main')
        .getByRole('button', { name: trigger, exact: true })
        .click();

    return page.getByRole('dialog', { name: title, exact: true });
}

/**
 * ダイアログの「決定」を押して、同じ詳細へ戻る送信を待つ。
 */
async function decide(page: Page, dialog: Locator, detailUrl: string) {
    await submitAndExpectUrl(
        page,
        dialog.getByRole('button', { name: '決定', exact: true }),
        detailUrl,
    );
}

/**
 * 人を選ぶ欄で検索の語を入れ、候補の要求の URL を返す(候補を選ぶかどうかは呼ぶ側)。
 */
async function searchUsers(page: Page, field: Locator, word: string) {
    const sent = page.waitForRequest((request) => {
        const url = new URL(request.url());

        return (
            url.pathname === '/workflow/user-candidates' &&
            url.searchParams.get('q') === word
        );
    });
    await field
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill(word);

    return new URL((await sent).url());
}

/**
 * 基本情報の 1 項目の値(項目名の dt の次の dd)。
 */
function basicItem(page: Page, label: string) {
    return page
        .getByRole('region', { name: '基本情報' })
        .getByText(label, { exact: true })
        .locator('xpath=following-sibling::dd');
}

/**
 * フロー情報を表に切り替えて、その表の行を返す。
 */
async function flowRows(page: Page) {
    await page
        .getByRole('group', { name: 'フロー情報の表示' })
        .getByRole('button', { name: '表', exact: true })
        .click();

    return page.getByRole('table', { name: 'フロー情報' }).getByRole('row');
}

test('代理元として開いた詳細: u0009 が保留中の申請を開くと、代理の 1 行・期限と到達日と経過日数・保留中の札・フロー情報の処理担当者と処理実行者が出る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');
    await openCase(page, NEW_PRODUCT);

    await expect(
        page.getByText('高橋 誠さんの代理として処理します。', { exact: true }),
    ).toBeVisible();
    await expect(basicItem(page, '状態')).toHaveText('保留中');
    await expect(basicItem(page, '現在のステップ')).toContainText('部長');
    await expect(basicItem(page, '期限')).toHaveText(
        new RegExp(`^${shownDate('2026-10-11')}\\s*期限超過$`),
    );
    await expect(basicItem(page, '到達日')).toHaveText(shownDate('2026-10-06'));
    await expect(basicItem(page, '経過日数')).toHaveText(
        `${daysSince('2026-10-06')} 日`,
    );

    const rows = await flowRows(page);
    await expect(rows.first().getByRole('columnheader')).toHaveText([
        'ステップ名',
        '状態',
        '処理担当者',
        '処理実行者',
        '処理日時',
    ]);
    await expect(rows.filter({ hasText: '部長' })).toContainText('保留中');
    await expect(rows.filter({ hasText: '部長' })).toContainText('高橋 誠');
    await expect(rows.filter({ hasText: '課長' }).first()).toContainText(
        '中村 美咲',
    );
});

test('保留と保留解除: u0009 が代理元として保留を解除してもう一度保留すると、状態の札と見出しの右のボタンが替わり、履歴に処理実行者が残る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');
    await openCase(page, NEW_PRODUCT);
    const detailUrl = page.url();
    const main = page.getByRole('main');
    await expect(
        main.getByRole('button', { name: '保留', exact: true }),
    ).toHaveCount(0);

    const release = await openActionDialog(page, '保留解除', '保留を解除する');
    await expect(release).toContainText(
        '保留を解除すると、承認待ちに戻ります。処理担当者の全員が、再び処理できます。',
    );
    await expect(
        release.getByRole('group', { name: '処理担当者', exact: true }),
    ).toHaveCount(0);
    const contact = release.getByRole('group', {
        name: '連絡する人',
        exact: true,
    });
    await expect(contact).toBeVisible();
    // 連絡する人の候補は今日の有効なユーザー(案件を渡さない)
    const contactUrl = await searchUsers(page, contact, '松本');
    expect(contactUrl.searchParams.has('case')).toBe(false);
    await page.keyboard.press('Escape');
    await decide(page, release, detailUrl);
    await expect(
        page.getByText('保留を解除しました', { exact: true }),
    ).toBeVisible();
    await expect(basicItem(page, '状態')).toHaveText('承認待ち');
    await expect(
        main.getByRole('button', { name: '保留解除', exact: true }),
    ).toHaveCount(0);

    const hold = await openActionDialog(page, '保留', '保留する');
    await expect(hold).toContainText('このステップの処理を保留します');
    await expect(hold).toContainText(
        '保留中は、保留した人とその代理関係にある人だけが処理できます。期限と経過日数は、保留中も数えます。',
    );
    await decide(page, hold, detailUrl);
    await expect(page.getByText('保留しました', { exact: true })).toBeVisible();
    await expect(basicItem(page, '状態')).toHaveText('保留中');
    await expect(
        page
            .getByRole('region', { name: '履歴' })
            .getByText(PROXY_ACTOR, { exact: true }),
    ).toHaveCount(3);
});

test('代理元としての承認: u0009 が高橋 誠さんの代理として部長のステップを承認すると、処理担当者の欄は出ず、履歴とフロー情報の処理実行者に代理と出る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', '/workflow/cases');
    await openCase(page, NEW_PRODUCT);
    const detailUrl = page.url();

    const approve = await openActionDialog(page, '承認', '承認する');
    await expect(
        approve.getByRole('group', { name: '処理担当者', exact: true }),
    ).toHaveCount(0);
    await decide(page, approve, detailUrl);
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();

    const latest = page
        .getByRole('region', { name: '履歴' })
        .getByRole('listitem')
        .last();
    await expect(latest).toContainText('部長');
    await expect(latest).toContainText('承認');
    await expect(latest).toContainText(PROXY_ACTOR);
    const rows = await flowRows(page);
    await expect(rows.filter({ hasText: '部長' })).toContainText(PROXY_ACTOR);
    await expect(rows.filter({ hasText: '部長' })).toContainText(
        '処理済み(承認)',
    );
});

test('担当変更: u0002 が課長のステップを松本 さくらへ変更すると、履歴に変更後の処理担当者が出て、自分の未処理から消える', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/cases');
    await openCase(page, INVENTORY_BUG);
    const detailUrl = page.url();

    const dialog = await openActionDialog(page, '担当変更', '担当変更する');
    await expect(dialog).toContainText('担当変更後は取り消せません');
    const field = dialog.getByRole('group', {
        name: '変更後の処理担当者',
        exact: true,
    });
    // 変更後の処理担当者の候補は申請基準日の有効なユーザー(案件を渡す)
    const transferUrl = await searchUsers(page, field, '松本');
    expect(transferUrl.searchParams.has('case')).toBe(true);
    await page.getByRole('option', { name: /^松本 さくら/ }).click();
    await expect(
        field.getByRole('list', { name: '選んだ変更後の処理担当者' }),
    ).toContainText('松本 さくら');
    // 欄を足しても題と「決定」は画面の中に残る(欄だけが中でスクロールする)
    await expect(
        dialog.getByRole('heading', { name: '担当変更する', exact: true }),
    ).toBeInViewport();
    await expect(
        dialog.getByRole('button', { name: '決定', exact: true }),
    ).toBeInViewport();
    await decide(page, dialog, detailUrl);

    await expect(
        page.getByText('担当を変更しました', { exact: true }),
    ).toBeVisible();
    await expect(
        page
            .getByRole('region', { name: '履歴' })
            .getByText('担当変更: 松本 さくら', { exact: true }),
    ).toBeVisible();
    await page.goto('/workflow/cases');
    await expect(caseRow(page, '未処理の申請', INVENTORY_BUG)).toHaveCount(0);
});

test('確認の面: u0010 が未確認の申請の詳細で確認すると、トーストが出て、確認する人の状態が確認済みになり、確認済のタブに出る', async ({
    page,
}) => {
    await openAs(page, 'accounting', '/workflow/cases?tab=unconfirmed');
    await openCase(page, SECOND_QUARTER, '未確認の申請');
    const detailUrl = page.url();
    const panel = page.getByRole('region', { name: '確認', exact: true });
    await expect(panel).toContainText(
        '確認は処理に影響しません。何度でも確認でき、確認した日時が残ります。',
    );
    const rows = panel
        .getByRole('table', { name: '確認の状況' })
        .getByRole('row');
    for (const step of ['課長', '部長']) {
        await expect(rows.filter({ hasText: step })).toContainText('山田 恵');
        await expect(rows.filter({ hasText: step })).toContainText('自分');
        await expect(rows.filter({ hasText: step })).toContainText('未確認');
    }

    await submitAndExpectUrl(
        page,
        panel.getByRole('button', { name: '確認', exact: true }),
        detailUrl,
    );
    await expect(page.getByText('確認しました', { exact: true })).toBeVisible();
    await expect(rows.filter({ hasText: '課長' })).toContainText('確認済');
    await page.goto('/workflow/cases?tab=confirmed');
    await expect(caseRow(page, '確認済の申請', SECOND_QUARTER)).toHaveCount(1);
});
