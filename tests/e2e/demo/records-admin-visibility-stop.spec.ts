import { expect, test } from '../support/test';
import { openAsAdministrator } from '../support/records-headings';

// 業務モジュールの編集の画面の表示の止め(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5。ADR 0031)。初期データの申請書の業務モジュールは
// コンテンツが指すので表示を止められる。システム管理者に切り替えるので demo の持ち物。保存しない(DB を変えない)。
test.use({ viewport: { width: 1280, height: 720 } });

test('申請書の業務モジュールの編集では「サイドバーに表示」が外れた形で押せず、止めの理由がチェックの説明として読み上げられる', async ({
    page,
}) => {
    await openAsAdministrator(
        page,
        '/records/admin/modules/project_plan_application/edit',
    );
    const form = page.getByRole('form', { name: '業務モジュールの入力' });
    const flag = form.getByRole('checkbox', {
        name: 'サイドバーに表示',
        exact: true,
    });
    const reason =
        '申請書の業務モジュールは、ワークフローのアプリに表示します。';

    await expect(flag).toBeDisabled();
    await expect(flag).not.toBeChecked();
    await expect(flag).toHaveAccessibleDescription(reason);
    await expect(form.getByText(reason, { exact: true })).toBeVisible();
});
