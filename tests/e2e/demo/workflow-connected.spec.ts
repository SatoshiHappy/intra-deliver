import { expect, test } from '../support/test';
import { openAs } from '../support/workflow-operations';

// 詳細のつながる申請(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節の例)。初期データの見本の案件を読むだけで、DB に書かない。
test.use({ viewport: { width: 1280, height: 720 } });

test('初期データの見本: 課題の対応の申請につながる申請が出る', async ({
    page,
}) => {
    await openAs(page, 'chief', '/workflow/cases?tab=pending');
    await expect(page).toHaveURL('/workflow/cases?tab=pending');

    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', {
            name: '課題の対応の承認 導入先の部署が決定していない',
            exact: true,
        })
        .click();

    await expect(
        page
            .getByRole('table', {
                name: '課題の「プロジェクト」を申請対象にする申請',
            })
            .getByText('WF-000001', { exact: true }),
    ).toBeVisible();
});
