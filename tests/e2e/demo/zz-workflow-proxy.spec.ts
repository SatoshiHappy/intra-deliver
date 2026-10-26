import { expect, test } from '../support/test';
import {
    basicItem,
    fillPlanApplication,
    openAs,
    submitApplication,
    switchUser,
} from '../support/workflow-operations';

// 案件の運用の画面(docs の 設計/ワークフロー/案件の運用/画面/README.md の 9 節)の筋のうち、申請の画面の申請の代理と連絡。
// 初期データの見本(設計/ワークフロー/案件の運用/初期データ.md 4.1 の代理)で案件を作るので、名前の順で zz-workflow-operations.spec.ts・
// zz-workflow-processing.spec.ts の後に流す(同じ組)。作る案件は筋の中で完結し、前の spec の見本の案件と件数を変えない。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const PLAN_APPLY_URL = '/workflow/flows/project_plan/apply';
const PLAN_FORM = 'プロジェクト計画の承認の入力';

test('申請の代理: u0009 が「申請者」で u0010 を選ぶと注意の囲みが出て、申請すると申請者が u0010、処理実行者が代理と出る', async ({
    page,
}) => {
    const projectName = 'E2E の代理申請';
    // u0010 → u0009 の申請の代理(全フロー。初期データ 4.1 の 2 行目)があるので、申請者の選択肢は 自分(吉田 達也)と 山田 恵
    await openAs(page, 'concurrent', PLAN_APPLY_URL);
    const form = page.getByRole('form', { name: PLAN_FORM });
    const applicant = form.getByRole('combobox', {
        name: '申請者',
        exact: true,
    });
    await expect(applicant).toContainText('吉田 達也');
    await expect(form.getByText(/さんとして申請します$/)).toHaveCount(0);

    await applicant.click();
    await page.getByRole('option', { name: /山田 恵/ }).click();
    // 申請者に依る props の読み直しが済んだ印は URL のクエリ applicant(応答で props と一緒に替わる)
    await expect(page).toHaveURL((url) => url.searchParams.has('applicant'));
    await expect(
        form.getByText('山田 恵さんとして申請します', { exact: true }),
    ).toBeVisible();
    await expect(form).toContainText(
        '申請者と担当組織は山田 恵さんになります。履歴の処理実行者には、吉田 達也(山田 恵さんの代理)と記録されます。',
    );
    await expect(form.locator('dl').first()).toContainText('経理部');

    await fillPlanApplication(page, form, projectName);
    await submitApplication(page, form);

    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(basicItem(page, '申請者')).toHaveText('山田 恵');
    await expect(
        page
            .getByRole('region', { name: '履歴' })
            .getByText('吉田 達也(山田 恵さんの代理)', { exact: true })
            .first(),
    ).toBeVisible();
});

test('連絡: u0001 が閲覧できない u0003 を連絡する人に選んで申請すると、u0003 の通知に移り先の無い連絡の行が出る', async ({
    page,
}) => {
    // 連絡の受け手は部長 u0003: 計画の承認の最初のステップは課長 u0002 で、u0003 は申請者でも参照者(経理部)でも管理者でもなく、未処理にも当たらないので閲覧できない
    const projectName = 'E2E の連絡申請';
    const message = '内容を確認してください。';
    await openAs(page, 'applicant', PLAN_APPLY_URL);
    const form = page.getByRole('form', { name: PLAN_FORM });
    await fillPlanApplication(page, form, projectName);

    const contact = form.getByRole('group', {
        name: '連絡する人',
        exact: true,
    });
    await contact
        .getByRole('button', { name: 'ユーザーを検索して追加', exact: true })
        .click();
    await page.getByPlaceholder('名前・コードで検索').fill('高橋');
    await page.getByRole('option', { name: /高橋 誠/ }).click();
    await expect(
        contact.getByRole('list', { name: '選んだ連絡する人' }),
    ).toContainText('高橋 誠');
    await form
        .getByRole('textbox', { name: 'ひと言', exact: true })
        .fill(message);
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();

    await switchUser(page, '佐々木 陽太（東京営業課）', 'manager');
    await page.goto('/notices');
    const row = page
        .getByRole('table', { name: '通知' })
        .getByRole('row')
        .filter({ hasText: message });
    await expect(row).toContainText('連絡');
    await expect(row).toContainText(`プロジェクト計画の承認 ${projectName}`);
    await expect(row).toContainText('この申請は閲覧できません。');
    await expect(row.getByRole('link')).toHaveCount(0);
});
