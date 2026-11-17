import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    VIEWERS_NOTE,
    moduleReadEffect,
    pane,
    rail,
    railButton,
    tabsCard,
} from '../support/workflow-case-show';
import { openApplicationTab, openAs } from '../support/workflow-operations';

// 完了した申請を、業務モジュールを見られない申請者が開いた形(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 8 節の
// 「完了の後(E2E)」)。完了の後も未完了の間と同じ中身(詳細タブ・サイドパネル)が出る。初期データの見本の完了した申請 WF-000017 を読むだけで、
// 申請書の業務モジュールの read の許可を筋の前に外し、筋の後に初期の形(ゲストユーザと認証済みユーザへの許可)に戻す。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** 完了した見本の申請 WF-000017(申請者 u0001。申請書のレコードはプロジェクト「新規顧客開拓キャンペーン」の写し) */
const COMPLETED_CASE = 'プロジェクト計画の承認 新規顧客開拓キャンペーン';
const COMPLETED_PROJECT = '新規顧客開拓キャンペーン';

/**
 * 完了した申請の詳細に出ないことを確かめる文の書き出し(完了の後も中身を出す = docs の 仕様/制限事項/レコードの共通機能.md の
 * 「完了した申請の中身」の行)
 */
const COMPLETED_NOTICE_HEAD = '申請が完了したため';

test.beforeAll(({ artisan }) => {
    artisan(['tinker', '--execute', moduleReadEffect('Deny')]);
});

test.afterAll(({ artisan }) => {
    artisan(['tinker', '--execute', moduleReadEffect('Permit')]);
});

/**
 * 申請者が、完了した見本の申請の詳細を開く(完了した申請の一覧から)。見出しを返す。
 */
async function openCompleted(page: Page) {
    await openAs(
        page,
        'applicant',
        '/workflow/cases?tab=processed&scope=completed',
    );
    await page
        .getByRole('main')
        .getByRole('link', { name: COMPLETED_CASE, exact: true })
        .click();
    const heading = page.getByRole('heading', {
        level: 1,
        name: COMPLETED_CASE,
        exact: true,
    });
    await expect(heading).toBeVisible();

    return heading;
}

test('申請書の業務モジュールの参照を持たない申請者が完了した申請を開くと、詳細タブに今の値が出て、サイドパネルの帯から添付とコメントを開ける', async ({
    page,
}) => {
    const heading = await openCompleted(page);

    // 完了は札だけ(「・<現在のステップ>」は出ない)
    const state = heading.locator('xpath=following-sibling::span');
    await expect(state).toContainText('完了(承認)');
    await expect(state).not.toContainText('・');

    const card = tabsCard(page, COMPLETED_CASE);
    await expect(card.getByRole('tab').first()).toHaveAttribute(
        'aria-selected',
        'true',
    );
    const tabs = card.getByRole('tab');
    await expect(tabs.nth(0)).toHaveText('詳細');
    await expect(tabs.nth(1)).toHaveText('申請');
    await expect(tabs.nth(2)).toHaveText('フロー');
    await expect(tabs.nth(3)).toHaveText('参照制限');
    await expect(
        card.getByText(COMPLETED_PROJECT, { exact: true }).first(),
    ).toBeVisible();
    await expect(page.getByText(COMPLETED_NOTICE_HEAD)).toHaveCount(0);

    // サイドパネルの帯は未完了の間と同じに出る
    await expect(rail(page)).toBeVisible();
    await railButton(page, '添付ファイル').click();
    await expect(pane(page, '添付ファイル')).toContainText(
        '申請の詳細では、添付ファイルを表示だけできます。追加は処理のダイアログで行います。',
    );
    await railButton(page, 'コメント').click();
    await expect(pane(page, 'コメント')).toContainText(
        'このコメントはレコードに付き、業務モジュールを参照できる人にも表示されます。',
    );
});

test('申請タブは申請の閲覧の判定のまま見え、タブ「参照制限」は今の参照制限で、「参照者」の注も出る', async ({
    page,
}) => {
    await openCompleted(page);
    const card = tabsCard(page, COMPLETED_CASE);

    await openApplicationTab(page);
    await expect(
        card.getByRole('region', { name: '処理履歴', exact: true }),
    ).toBeVisible();
    await card.getByRole('tab', { name: '参照制限', exact: true }).click();
    await card.getByRole('button', { name: '参照者の注', exact: true }).focus();
    await expect(page.getByRole('tooltip')).toContainText(VIEWERS_NOTE);
});
