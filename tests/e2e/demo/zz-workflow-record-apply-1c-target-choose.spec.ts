import { expect, test } from '../support/test';
import { openAs } from '../support/workflow-operations';
import {
    APPLY_URL,
    CONFIRM_TITLE,
    OTHER,
    SOURCE,
    APPLICATION_TARGET_ERROR,
    applyForm,
    budgetField,
    expectConfirmRows,
    expectCopied,
    milestoneRows,
    nameField,
    pickApplicationTarget,
    applicationTargetField,
} from '../support/workflow-target-apply';
import { RECORD_FORM } from '../support/workflow-record-apply';

// 申請対象からの申請の画面(申請の画面で申請対象を選ぶ・選び直す)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「申請対象から」。
// 写した値は DB に書かない(申請しない)ので、順に依らない。2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });

test('新規の申請で申請対象を選ぶと値が写り、入力済みの値が変わるときは確かめのダイアログ(キャンセルで何も変わらず、反映で変わる)', async ({
    page,
}) => {
    await openAs(page, 'applicant', APPLY_URL);
    const form = applyForm(page);
    await expect(
        page.getByText('内容を入力して申請します。', { exact: false }),
    ).toBeVisible();

    // 入力済みの値が無いので、確かめずに写す。見出しの下の 1 行は
    // 業務レコードの詳細から開いた画面だけが替わる
    await pickApplicationTarget(page, '新規', OTHER);
    await expectCopied(form, { name: OTHER, budget: '300', rows: 2 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        page.getByText('内容を入力して申請します。', { exact: false }),
    ).toBeVisible();

    // 値を直してから別の申請対象を選ぶと、入力済みの値が変わるので確かめる
    await nameField(form).fill('E2E で直した名前');
    await pickApplicationTarget(page, '社内', SOURCE);
    const dialog = page.getByRole('dialog', { name: CONFIRM_TITLE });
    await expectConfirmRows(page, dialog);

    // 「キャンセル」: 申請対象も欄の値も変えない
    await dialog
        .getByRole('button', { name: 'キャンセル', exact: true })
        .click();
    await expect(dialog).toHaveCount(0);
    await expect(applicationTargetField(form)).toContainText(OTHER);
    await expect(nameField(form)).toHaveValue('E2E で直した名前');
    await expect(budgetField(form)).toHaveValue('300');
    await expect(milestoneRows(form)).toHaveCount(3);

    // 選び直して「反映」: 申請対象も欄の値も写しの値に変わる
    await pickApplicationTarget(page, '社内', SOURCE);
    await dialog.getByRole('button', { name: '反映', exact: true }).click();
    await expect(dialog).toHaveCount(0);
    await expectCopied(form, { name: SOURCE, budget: '1200', rows: 4 });
});

test('何も入れていない新規の申請で、状態が既定値(計画中)と違うプロジェクトを選んでも、確かめずに写す', async ({
    page,
}) => {
    await openAs(page, 'applicant', APPLY_URL);
    const form = applyForm(page);

    await pickApplicationTarget(page, '社内', SOURCE);

    await expectCopied(form, { name: SOURCE, budget: '1200', rows: 4 });
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('業務データの画面で申請対象の項目を選んでも、値は写らない', async ({
    page,
}) => {
    await openAs(
        page,
        'applicant',
        '/records/data/project_plan_application/create',
    );
    const form = page.getByRole('form', { name: RECORD_FORM });
    await pickApplicationTarget(page, '社内', SOURCE);

    await expect(applicationTargetField(form)).toContainText(SOURCE);
    await expect(nameField(form)).toHaveValue('');
    await expect(budgetField(form)).toHaveValue('');
    await expect(page.getByRole('dialog')).toHaveCount(0);
});

test('写しの値の入口が 404 のとき、トーストが出て、申請対象だけが最後に決まった申請対象へ戻り、ほかの欄は変わらない', async ({
    page,
}) => {
    await openAs(page, 'applicant', APPLY_URL);
    const form = applyForm(page);
    await pickApplicationTarget(page, '新規', OTHER);
    await expectCopied(form, { name: OTHER, budget: '300', rows: 2 });
    await nameField(form).fill('写しの後に直した名前');

    await page.route('**/workflow/target-values/*', (route) =>
        route.fulfill({
            status: 404,
            contentType: 'application/json',
            body: '{"message":"Not Found"}',
        }),
    );
    await pickApplicationTarget(page, '社内', SOURCE);

    await expect(
        page.getByText(APPLICATION_TARGET_ERROR, { exact: true }),
    ).toBeVisible();
    await expect(applicationTargetField(form)).toContainText(OTHER);
    await expect(nameField(form)).toHaveValue('写しの後に直した名前');
    await expect(budgetField(form)).toHaveValue('300');
    await expect(page.getByRole('dialog')).toHaveCount(0);
    await expect(
        form.getByRole('button', { name: '申請', exact: true }),
    ).toBeEnabled();
});
