import { expect, test } from '../support/test';
import {
    switchUser,
    openAs,
    basicItem,
    openApplicationTab,
} from '../support/workflow-operations';
import {
    APPLY_FORM,
    CHIEF,
    FLOW_CODE,
    FLOW_NAME,
    MANAGER,
    approve,
    createRecord,
    editAndApply,
    expectHeld,
    expectHoldNotice,
    expectRecordHeadingLine,
    recordIdOf,
} from '../support/workflow-record-apply';
import { toasts } from '../support/toasts';
import { pane, railButton } from '../support/workflow-case-show';

// 既存のデータからの申請の画面(入口が 1 つ)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節。
// 見本に案件の無い申請書のレコードは無いので筋の中で作る。入口が 1 つのうちに流すため、2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** 筋の中で作るレコードの詳細の URL と、申請した案件の詳細の URL(前の筋で決まり、後の筋が使う) */
let recordUrl = '';
let caseUrl = '';

/** 申請した後の現在のステップ(計画のフローの版 2 の「課長」) */
const CHIEF_STEP = '課長';

test('詳細の「申請する」→ 申請の画面(見出しの 1 行・値を直す・一時保存のボタンが無い)→ 申請 → 押せない形', async ({
    page,
}) => {
    const name = 'E2E の既存の計画 A';
    await openAs(page, 'applicant', '/');
    // 申請を閲覧できない人にも業務データの画面で見せる(下の筋)ため、参照制限を外して登録する
    recordUrl = await createRecord(page, name, true);
    // 登録のトースト(数秒で消えるので登録の直後に見る)は右上の「申請する」に重なりうるが、クリックを下へ通し、広げて出る(docs の
    // 設計/画面の意匠/骨格とヘッダー.md の「トーストの置き場」)
    const created = toasts(page).filter({ hasText: '登録しました' });
    await expect(created).toHaveCSS('pointer-events', 'none');
    await expect(created).toHaveAttribute('data-expanded', 'true');
    const main = page.getByRole('main');

    // 入口が 1 つ(初期データ)なので、押すとダイアログを経ずに申請の画面へ。持ち主が無いので理由の 1 行は無く、削除も押せる
    await expect(main.getByText('申請済みのため変更できません')).toHaveCount(0);
    await expect(
        main.getByRole('button', { name: '削除', exact: true }),
    ).toBeEnabled();
    await main.getByRole('link', { name: '申請する', exact: true }).click();
    await expect(page).toHaveURL(
        `/workflow/flows/${FLOW_CODE}/apply?record=${recordIdOf(recordUrl)}`,
    );

    await expect(
        page.getByRole('heading', { level: 1, name: FLOW_NAME, exact: true }),
    ).toBeVisible();
    await expectRecordHeadingLine(page, name);
    const form = page.getByRole('form', { name: APPLY_FORM });
    await expect(
        form.getByRole('textbox', { name: 'プロジェクト名', exact: true }),
    ).toHaveValue(name);
    await expect(
        form.getByRole('button', { name: '一時保存', exact: true }),
    ).toHaveCount(0);
    await expect(
        form.getByRole('link', { name: 'キャンセル', exact: true }),
    ).toHaveAttribute('href', new URL(recordUrl).pathname);

    await editAndApply(page, recordUrl, name);
    caseUrl = page.url();
    await expect(await basicItem(page, '申請者')).toHaveText('佐々木 陽太');
});

test('申請したレコードは、直した値が入り、編集・申請する・削除が押せない形で、理由の 1 行に申請番号のリンク・札・現在のステップが続く', async ({
    page,
}) => {
    const notice = { status: '承認待ち', suffix: CHIEF_STEP };
    await openAs(page, 'applicant', recordUrl);
    await expectHeld(page);
    await expect(
        page.getByRole('main').getByText('150', { exact: true }),
    ).toBeVisible();
    const number = await (await expectHoldNotice(page, notice))?.textContent();

    // 編集の画面のフォームの上の 1 行も同じ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 5.1)
    await page.goto(`${recordUrl}/edit`);
    await expectHoldNotice(page, notice);

    // 更新履歴の申請の版の操作の後に申請番号のリンク(更新履歴.md の 5.1)
    await page.goto(recordUrl);
    await railButton(page, '更新履歴').click();
    await expect(
        pane(page, '更新履歴').getByRole('link', {
            name: number ?? '',
            exact: true,
        }),
    ).toBeVisible();

    await (await expectHoldNotice(page, notice))?.click();
    await expect(page).toHaveURL(caseUrl);
});

test('申請を閲覧できない人には、詳細と編集の画面で理由の 1 行だけが出る', async ({
    page,
}) => {
    await openAs(page, 'concurrent', recordUrl);
    await expectHeld(page);
    await expectHoldNotice(page, null);

    await page.goto(`${recordUrl}/edit`);
    await expectHoldNotice(page, null);
});

test('承認を進めて完了になっても、申請した申請書のレコードは押せない形のまま', async ({
    page,
}) => {
    await openAs(page, 'chief', caseUrl);
    await approve(page);
    await switchUser(page, CHIEF, 'manager');
    await page.goto(caseUrl);
    await approve(page);
    await openApplicationTab(page);
    await expect(page.getByRole('region', { name: '申請情報' })).toContainText(
        '完了(承認)',
    );

    await switchUser(page, MANAGER, 'applicant');
    await page.goto(recordUrl);
    await expectHeld(page);
    await expectHoldNotice(page, { status: '完了(承認)', suffix: null });
});
