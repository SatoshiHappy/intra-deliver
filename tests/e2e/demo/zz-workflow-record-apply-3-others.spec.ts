import { expect, test } from '../support/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import {
    basicItem,
    openAs,
    submitApplication,
    switchUser,
} from '../support/workflow-operations';
import {
    ACCOUNTING,
    APPLY_FORM,
    DENY_UPDATE,
    FLOW_CODE,
    FLOW_NAME,
    createRecord,
    expectHeld,
    recordIdOf,
} from '../support/workflow-record-apply';

// 既存のデータからの申請の画面(代理と権限)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節。
// 2-choose が足した 2 つ目のフローを使う。権限の筋は更新の許可を外すので最後に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

test('代理: 代理元が作成者のレコードを、代理先が詳細の「申請する」で申請する(入口の URL に applicant・申請者の既定は代理元)', async ({
    page,
}) => {
    const name = 'E2E の既存の計画 D';
    // 山田 恵(経理部)が作ったレコード。山田 恵 → 吉田 達也の申請の代理(全フロー)が初期データにある(設計/ワークフロー/案件の運用/初期データ.md 4.1)
    await openAs(page, 'accounting', '/');
    const recordUrl = await createRecord(page, name);
    await switchUser(page, ACCOUNTING, 'concurrent');
    await page.goto(recordUrl);

    // 吉田 達也は作成者でないが、代理元の山田 恵が作成者なので入口が出る(入口が 2 つなのでダイアログ)
    await page
        .getByRole('main')
        .getByRole('button', { name: '申請する', exact: true })
        .click();
    const dialog = page.getByRole('dialog', { name: '申請するフローを選択' });
    await dialog
        .getByRole('radio', { name: new RegExp(`^${FLOW_NAME}`) })
        .click();
    await dialog.getByRole('button', { name: '選択', exact: true }).click();
    await expect(page).toHaveURL(
        (url) =>
            url.pathname === `/workflow/flows/${FLOW_CODE}/apply` &&
            url.searchParams.get('record') === recordIdOf(recordUrl) &&
            url.searchParams.has('applicant'),
    );

    // 申請者の既定は代理元(選べる申請者は作成者の 1 人だけなので欄は出ず、注意の囲みだけが出る)
    const form = page.getByRole('form', { name: APPLY_FORM });
    await expect(
        form.getByRole('combobox', { name: '申請者', exact: true }),
    ).toHaveCount(0);
    await expect(
        form.getByText('山田 恵さんとして申請します', { exact: true }),
    ).toBeVisible();
    await fillVersionTwoBaseDate(form);
    await submitApplication(page, form);
    await expect(page.getByText('申請しました', { exact: true })).toBeVisible();
    await expect(basicItem(page, '申請者')).toHaveText('山田 恵');
    await expect(
        page
            .getByRole('region', { name: '履歴' })
            .getByText('吉田 達也(山田 恵さんの代理)', { exact: true })
            .first(),
    ).toBeVisible();

    // 申請したレコードは、入口が残っても押せない形
    await page.goto(recordUrl);
    await expectHeld(page);
});

test('権限(経路ごと): 申請書のレコードの update を許可しない(read と create だけ)人の詳細には「編集」も「申請する」も出ない', async ({
    page,
    artisan,
}) => {
    const name = 'E2E の既存の計画 E';
    await openAs(page, 'applicant', '/');
    const recordUrl = await createRecord(page, name);
    const main = page.getByRole('main');
    await expect(
        main.getByRole('button', { name: '申請する', exact: true }),
    ).toBeVisible();

    artisan(['tinker', '--execute', DENY_UPDATE]);
    await page.goto(recordUrl);
    await expect(
        page.getByRole('heading', { level: 1, name, exact: true }),
    ).toBeVisible();
    await expect(main.getByText('申請する', { exact: true })).toHaveCount(0);
    await expect(main.getByText('編集', { exact: true })).toHaveCount(0);
});
