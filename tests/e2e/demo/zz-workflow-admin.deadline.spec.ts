import { expect, test } from '../support/test';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';
import { PLAN_ADMIN_URL } from '../support/workflow-flow-settings';

// 定義の管理の画面のカード「処理期限」の誤りの入力(docs の 設計/ワークフロー/案件の運用/定義の管理.md の 5 節)。
// zz-workflow-admin(版を足す)の前に流れる(名前の順)。保存は 422 で終わるので、定義を変えない。
test.use({ viewport: { width: 1280, height: 720 } });

test('システム管理者: 処理期限に数と読めない文字を入れて保存すると欄の下に誤りが出て、期限なしとして保存されない', async ({
    page,
}) => {
    const council = `${PLAN_ADMIN_URL}?node=council`;
    const deadline = page.getByRole('textbox', {
        name: '処理期限',
        exact: true,
    });
    await openAsAdministrator(page, council);
    await deadline.fill('1e');
    await submitAndExpectUrl(
        page,
        page.getByRole('button', { name: '保存', exact: true }),
    );

    await expect(deadline).toHaveAccessibleDescription(
        '処理期限は 0 から 99 までの日数を入力してください',
    );
    await expect(
        page.getByText('ステップの設定を保存しました', { exact: true }),
    ).toHaveCount(0);
    await page.goto(council);
    await expect(deadline).toHaveValue('3');
});
