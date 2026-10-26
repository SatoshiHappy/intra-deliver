import { expect, test } from '../support/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import { openAs } from '../support/workflow-operations';
import {
    CREATE_SECOND_FLOW,
    FLOW_CODE,
    SECOND_FLOW_CODE,
    SECOND_FLOW_NAME,
    createRecord,
    expectFlowDialog,
    expectRecordHeadingLine,
    openApplyScreen,
    recordIdOf,
    updateBudgetInAnotherTab,
} from '../support/workflow-record-apply';

// 既存のデータからの申請の画面(入口が 2 つ以上のダイアログと、開いた後の更新の 422)。筋の正本 = docs の
// 設計/ワークフロー/申請の周辺/画面.md の 9 節と、設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節。
// 2 つ目のフローを足すので、ほかの spec のフローの数の期待を変えないよう組の後ろに置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const STALE_MESSAGE =
    'このレコードは、開いた後に更新されています。画面を開き直してから、もう一度保存してください。';

/** 前の筋で作るレコードの名前と詳細の URL(ダイアログの筋で決まり、次の筋が使う) */
const NAME_B = 'E2E の既存の計画 B';
let recordUrlB = '';

test('フロー 2 つ: 「申請する」でフローを選ぶダイアログが開き(題・本文・行)、行を選ぶまで「選択」は押せず、キャンセルで閉じて選びは空に戻る', async ({
    page,
    artisan,
}) => {
    artisan(['tinker', '--execute', CREATE_SECOND_FLOW]);
    const name = NAME_B;
    await openAs(page, 'applicant', '/');
    const recordUrl = await createRecord(page, name);
    recordUrlB = recordUrl;
    const trigger = page
        .getByRole('main')
        .getByRole('button', { name: '申請する', exact: true });

    // 入口が 2 つなので、押すとダイアログ(題・本文・フローの行は名前と説明)
    await trigger.click();
    const dialog = await expectFlowDialog(page, name);
    const rows = dialog.getByRole('radio');

    // 行を選ぶまで「選択」は押せない。キャンセルで閉じ、開き直すと選びは空に戻る
    const choose = dialog.getByRole('button', { name: '選択', exact: true });
    await expect(choose).toBeDisabled();
    await rows.nth(1).click();
    await expect(choose).toBeEnabled();
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await expect(page).toHaveURL(recordUrl);
    await trigger.click();
    await expect(dialog.getByRole('radio', { checked: true })).toHaveCount(0);
    await expect(choose).toBeDisabled();
});

test('ダイアログで 2 つ目のフローを選んで「選択」を押すと、そのフローの申請の画面(既存のデータ)へ移り、キャンセルでそのレコードの詳細へ戻る', async ({
    page,
}) => {
    const name = NAME_B;
    const recordUrl = recordUrlB;
    await openAs(page, 'applicant', recordUrl);
    await page
        .getByRole('main')
        .getByRole('button', { name: '申請する', exact: true })
        .click();
    const dialog = await expectFlowDialog(page, name);
    const rows = dialog.getByRole('radio');
    const choose = dialog.getByRole('button', { name: '選択', exact: true });
    await rows.nth(1).click();
    await choose.click();
    await expect(page).toHaveURL(
        `/workflow/flows/${SECOND_FLOW_CODE}/apply?record=${recordIdOf(recordUrl)}`,
    );
    await expect(
        page.getByRole('heading', {
            level: 1,
            name: SECOND_FLOW_NAME,
            exact: true,
        }),
    ).toBeVisible();
    await expectRecordHeadingLine(page, name);
    await page
        .getByRole('form', { name: `${SECOND_FLOW_NAME}の入力` })
        .getByRole('link', { name: 'キャンセル', exact: true })
        .click();
    await expect(page).toHaveURL(recordUrl);
});

test('開いた後に業務データの画面で更新 → 基準日を変える → 申請: 422 record が申請内容の上の 1 行とトーストで出て、申請の行は書かれない', async ({
    page,
}) => {
    const name = 'E2E の既存の計画 C';
    await openAs(page, 'applicant', '/');
    const recordUrl = await createRecord(page, name);
    const form = await openApplyScreen(page, recordUrl, name);

    await updateBudgetInAnotherTab(page, recordUrl);

    // 基準日を変える部分の読み直しの後も、クエリ record が残り(既存のデータの画面のまま)、版は開いたときのまま
    await fillVersionTwoBaseDate(form);
    await expect(page).toHaveURL(
        (url) => url.searchParams.get('record') === recordIdOf(recordUrl),
    );
    await expectRecordHeadingLine(page, name);
    await form.getByRole('button', { name: '申請', exact: true }).click();
    await page
        .getByRole('dialog', { name: '申請しますか？' })
        .getByRole('button', { name: '決定', exact: true })
        .click();

    await expect(
        form.getByRole('alert').filter({ hasText: STALE_MESSAGE }),
    ).toHaveCount(1);
    await expect(
        page.locator('[data-sonner-toast]').getByText(STALE_MESSAGE),
    ).toBeVisible();
    await expect(page).toHaveURL(
        new RegExp(`/workflow/flows/${FLOW_CODE}/apply\\?`),
    );

    // 申請の行は書かれず、レコードは持ち主を持たない(押せる)。更新の値は残っている
    await page.goto(recordUrl);
    await expect(
        page.getByRole('main').getByText('申請済みのため変更できません'),
    ).toHaveCount(0);
    await expect(
        page.getByRole('main').getByText('999', { exact: true }),
    ).toBeVisible();
});
