import { expect, test } from '../support/test';
import { openAsAdministrator } from '../support/records-headings';
import { submitAndExpectUrl } from '../support/submit';
import { PLAN_ADMIN_URL } from '../support/workflow-flow-settings';

// 定義の管理の画面のカード「処理期限」の誤りの入力と全角の数字(docs の 設計/ワークフロー/案件の運用/定義の管理.md の 5 節、
// docs の 設計/画面の意匠/README.md の方針 9 の (g))。zz-workflow-admin(版を足す)の前に流れる(名前の順)。誤りの入力の保存は 422 で
// 終わり、全角の数字の保存は筋の最後に元の期限に戻すので、定義を変えない。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const COUNCIL = `${PLAN_ADMIN_URL}?node=council`;

test('システム管理者: 処理期限に数と読めない文字を入れて保存すると欄の下に誤りが出て、期限なしとして保存されない', async ({
    page,
}) => {
    const deadline = page.getByRole('textbox', {
        name: '処理期限',
        exact: true,
    });
    await openAsAdministrator(page, COUNCIL);
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
    await page.goto(COUNCIL);
    await expect(deadline).toHaveValue('3');
});

test('システム管理者: 処理期限に全角の数字を入れて保存すると、誤りが出ず半角の日数で保存され、元の期限に戻せる', async ({
    page,
}) => {
    const deadline = page.getByRole('textbox', {
        name: '処理期限',
        exact: true,
    });
    const saveWith = async (text: string) => {
        await deadline.fill(text);
        await submitAndExpectUrl(
            page,
            page.getByRole('button', { name: '保存', exact: true }),
            /node=council/,
        );
        await expect(
            page
                .getByText('ステップの設定を保存しました', { exact: true })
                .last(),
        ).toBeVisible();
    };
    await openAsAdministrator(page, COUNCIL);
    await saveWith('５');

    await expect(deadline).not.toHaveAccessibleDescription(/処理期限は/);
    await page.goto(COUNCIL);
    await expect(deadline).toHaveValue('5');
    await saveWith('3');
    await page.goto(COUNCIL);
    await expect(deadline).toHaveValue('3');
});
