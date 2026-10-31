import { expect, test } from '../support/test';
import { switchUser, openAs, basicItem } from '../support/workflow-operations';
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
    expectRecordHeadingLine,
    recordIdOf,
} from '../support/workflow-record-apply';

// 既存のデータからの申請の画面(入口が 1 つ)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節。
// 見本に案件の無い申請書のレコードは無いので筋の中で作る。入口が 1 つのうちに流すため、2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** 筋の中で作るレコードの詳細の URL と、申請した案件の詳細の URL(前の筋で決まり、後の筋が使う) */
let recordUrl = '';
let caseUrl = '';

test('詳細の「申請する」→ 申請の画面(見出しの 1 行・値を直す・一時保存のボタンが無い)→ 申請 → 押せない形', async ({
    page,
}) => {
    const name = 'E2E の既存の計画 A';
    await openAs(page, 'applicant', '/');
    recordUrl = await createRecord(page, name);
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
    await expect(basicItem(page, '申請者')).toHaveText('佐々木 陽太');
});

test('申請したレコードは、直した値が入り、編集・申請する・削除が押せない形で理由の 1 行が出る', async ({
    page,
}) => {
    await openAs(page, 'applicant', recordUrl);
    await expectHeld(page);
    await expect(
        page.getByRole('main').getByText('150', { exact: true }),
    ).toBeVisible();
});

test('承認を進めて完了になっても、申請した申請書のレコードは押せない形のまま', async ({
    page,
}) => {
    await openAs(page, 'chief', caseUrl);
    await approve(page);
    await switchUser(page, CHIEF, 'manager');
    await page.goto(caseUrl);
    await approve(page);
    await expect(page.getByRole('region', { name: '申請情報' })).toContainText(
        '完了(承認)',
    );

    await switchUser(page, MANAGER, 'applicant');
    await page.goto(recordUrl);
    await expectHeld(page);
});
