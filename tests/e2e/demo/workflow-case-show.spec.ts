import { expect, test } from '../support/test';
import type { Page } from '@playwright/test';
import {
    VIEWERS_NOTE,
    pane,
    railButton,
    tabsCard,
} from '../support/workflow-case-show';
import {
    SECOND_QUARTER,
    openApplicationTab,
    openAs,
} from '../support/workflow-operations';

// 申請の詳細の左のタブとサイドパネル(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 8 節の「E2E」「写し(E2E)」)。
// 初期データの見本の案件を読むだけ(処理のダイアログは開いて閉じる)。写しの筋は workflow-case-show-snapshot.spec.ts。
test.use({ viewport: { width: 1280, height: 720 } });

/**
 * 部長(承認待ちのステップの処理担当者)が、未処理の見本の申請 WF-000014 の詳細を開く。左のタブを入れるカードと見出しを返す。
 */
async function openAsManager(page: Page) {
    await openAs(page, 'manager', '/workflow/cases');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', { name: SECOND_QUARTER, exact: true })
        .click();
    const heading = page.getByRole('heading', {
        level: 1,
        name: SECOND_QUARTER,
        exact: true,
    });
    await expect(heading).toBeVisible();

    return { heading, card: tabsCard(page, SECOND_QUARTER) };
}

test('処理担当者: 見出しの横に状態の札と現在のステップが出て、開くと詳細タブで値が見え、タブは 詳細 / 申請 / 参照制限 の順', async ({
    page,
}) => {
    const { heading, card } = await openAsManager(page);

    const state = heading.locator('xpath=following-sibling::span');
    await expect(state).toContainText('承認待ち');
    await expect(state).toContainText('・部長');
    const tabs = card.getByRole('tab');
    await expect(tabs.nth(0)).toHaveText('詳細');
    await expect(tabs.nth(1)).toHaveText('申請');
    await expect(tabs.nth(2)).toHaveText('参照制限');
    await expect(tabs.nth(0)).toHaveAttribute('aria-selected', 'true');
    await expect(
        card.getByText('プロジェクト名', { exact: true }),
    ).toBeVisible();
});

test('処理担当者: 帯の「添付ファイル」で表示だけの添付の欄が開き、コメントの欄の先頭に 1 行が出る', async ({
    page,
}) => {
    await openAsManager(page);

    await railButton(page, '添付ファイル').click();
    const attachments = pane(page, '添付ファイル');
    await expect(attachments).toContainText(
        '申請の詳細では、添付ファイルを表示だけできます。追加は処理のダイアログで行います。',
    );
    await expect(
        attachments.getByText('第二四半期の営業計画の概要.pdf', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        attachments.getByRole('button', { name: 'ファイルを追加' }),
    ).toHaveCount(0);
    await expect(
        attachments.getByRole('button', { name: /を削除$/ }),
    ).toHaveCount(0);

    await railButton(page, 'コメント').click();
    await expect(pane(page, 'コメント')).toContainText(
        'このコメントはレコードに付き、業務モジュールを参照できる人にも表示されます。',
    );
});

test('処理担当者: 申請タブに面が決まった順で並び、タブ「参照制限」の項目「参照者」の注がフォーカスで出る', async ({
    page,
}) => {
    const { card } = await openAsManager(page);

    await openApplicationTab(page);
    await expect(card.getByRole('heading', { level: 2 })).toHaveText([
        '申請情報',
        'フロー情報',
        '処理履歴',
        '確認',
        'つながる申請',
    ]);
    await expect(
        card.getByRole('region', { name: '処理履歴', exact: true }),
    ).toContainText('申請');

    await card.getByRole('tab', { name: '参照制限', exact: true }).click();
    await card.getByRole('button', { name: '参照者の注', exact: true }).focus();
    await expect(page.getByRole('tooltip')).toContainText(VIEWERS_NOTE);
});

test('処理担当者: 画面の下の帯の「承認」で承認のダイアログが開く(決定しない)', async ({
    page,
}) => {
    await openAsManager(page);

    await page
        .getByRole('main')
        .getByRole('button', { name: '承認', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '承認する' });
    await expect(dialog).toBeVisible();
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
});

test('業務データの画面の申請書のレコードのタブ「参照制限」には、「参照者」の注は出ない(申請の詳細にだけ出る)', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/records/data/project_plan_application');
    await page
        .getByRole('region', { name: 'プロジェクト計画の申請の一覧' })
        .getByRole('link', { name: '第二四半期の営業計画', exact: true })
        .click();
    const values = page.getByRole('region', {
        name: '第二四半期の営業計画の値',
        exact: true,
    });

    await values.getByRole('tab', { name: '参照制限', exact: true }).click();

    await expect(values.getByText('参照者', { exact: true })).toBeVisible();
    await expect(
        values.getByRole('button', { name: '参照者の注', exact: true }),
    ).toHaveCount(0);
});
