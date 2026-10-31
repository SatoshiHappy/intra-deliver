import { expect, test } from './support/test';

// 業務の一覧の見出しの横の業務モジュールの説明(docs の 設計/画面の意匠/README.md 方針 9(d)、
// 設計/業務データの入れ物/画面/業務の画面.md の 2.1)。初期データ(RecordsSeeder)のプロジェクトの説明を読むだけ。
test.use({ viewport: { width: 1280, height: 720 } });

test('業務の一覧の見出しの横の「?」に業務モジュールの説明が出る', async ({
    page,
}) => {
    await page.goto('/records/data/project');

    await expect(
        page.getByRole('heading', { level: 1, name: 'プロジェクト' }),
    ).toBeVisible();
    await page
        .getByRole('button', { name: 'プロジェクトの説明', exact: true })
        .hover();
    await expect(page.getByRole('tooltip')).toHaveText(
        '社内のプロジェクトの担当・期間・状態',
    );
});
