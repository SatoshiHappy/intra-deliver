import { expect, test } from '../support/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import {
    basicItem,
    openAs,
    submitApplication,
} from '../support/workflow-operations';
import {
    APPLY_URL,
    SOURCE,
    applyForm,
    budgetField,
    expectSnapshot,
    expectProjectUntouched,
    openProject,
} from '../support/workflow-target-apply';

// 申請対象からの申請の画面(業務データの詳細から。入口が 1 つ)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「申請対象から」。
// 初期データでプロジェクトを申請対象にするフローは 1 つなので、2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });

test('プロジェクトの詳細の「申請する」→ 申請の画面に値が写る → 値を直して申請 → プロジェクトの詳細は変わらず、押せない形も無い', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/');
    await openProject(page, SOURCE);
    const main = page.getByRole('main');
    await expect(
        main.getByText('1,200', { exact: true }).first(),
    ).toBeVisible();

    // 入口が 1 つ(初期データ)なので、ダイアログを経ずに申請の画面へ(クエリ target = そのレコード)
    await main.getByRole('link', { name: '申請する', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`${APPLY_URL}\\?target=\\d+$`));

    // 見出しの下の 1 行は今の申請対象の欄で決める。申請対象の欄にそのレコードが入り、申請内容に写した値が入る
    await expect(
        page.getByText(`${SOURCE}を申請対象にして申請します。`),
    ).toBeVisible();
    const form = applyForm(page);
    await expectSnapshot(form, { name: SOURCE, budget: '1200', rows: 4 });
    await expect(
        form.getByRole('textbox', { name: '名称(1 行目)', exact: true }),
    ).toHaveValue('要件定義');
    // 新規の申請と同じボタン(キャンセルはレコードの詳細へ。一時保存もある)
    await expect(
        form.getByRole('button', { name: '一時保存', exact: true }),
    ).toBeEnabled();

    // 値を直して申請(申請基準日を変える部分の読み直しの後も、申請対象の欄と見出しの 1 行は残る)
    await budgetField(form).fill('1500');
    await fillVersionTwoBaseDate(form);
    await expect(
        page.getByText(`${SOURCE}を申請対象にして申請します。`),
    ).toBeVisible();
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(basicItem(page, '申請者')).toHaveText('佐々木 陽太');

    // 申請対象のプロジェクトは書き換わらず、押せない形も無い(持ち主は申請書のレコードだけ)
    await openProject(page, SOURCE);
    await expectProjectUntouched(page);
});
