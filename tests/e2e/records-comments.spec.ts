import { expect, test } from './support/test';

// レコードのコメントのゲストの形(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 7 節、モック 2g): 入力の欄の代わりに 1 行、
// 一覧は見られ、「削除」は出ない。ゲストだけで成り立つ(宛先を選んで投稿・削除・通知は利用者を切り替える側の別の spec)。
test.use({ viewport: { width: 1280, height: 720 } });

test('ゲストはコメントの一覧を見られ、入力の欄の代わりに 1 行が出て、削除は出ない', async ({
    page,
}) => {
    await page.goto('/records/data/project');
    await page
        .getByRole('region', { name: 'プロジェクトの一覧' })
        .getByRole('link', { name: '社内ポータル刷新', exact: true })
        .click();
    await expect(page).toHaveURL(/\/records\/data\/project\/\d+$/);
    await page
        .getByRole('navigation', { name: 'サイドパネル', exact: true })
        .getByRole('button', { name: /^コメント/ })
        .click();
    await expect(page).toHaveURL(/\?panel=comments$/);

    const pane = page.getByRole('region', { name: 'コメント', exact: true });
    await expect(
        pane.getByText('コメントを追加するには、ユーザーを選択してください。', {
            exact: true,
        }),
    ).toBeVisible();
    await expect(
        pane.getByRole('form', { name: 'コメントの入力' }),
    ).toHaveCount(0);
    const list = pane.getByRole('list', { name: 'コメントの一覧' });
    await expect(list.getByRole('listitem').first()).toBeVisible();
    await expect(list.getByRole('button', { name: '削除' })).toHaveCount(0);
});
