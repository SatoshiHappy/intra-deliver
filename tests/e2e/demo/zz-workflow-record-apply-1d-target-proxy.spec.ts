import { expect, test } from '../support/test';
import { fillVersionTwoBaseDate } from '../support/seed-dates';
import {
    basicItem,
    openAs,
    submitApplication,
    switchUser,
} from '../support/workflow-operations';
import { ACCOUNTING } from '../support/workflow-record-apply';
import {
    APPLY_URL,
    RESTORE_PROJECTS,
    RESTRICT_PROJECTS,
    USER_ID,
    applyForm,
    createProject,
    nameField,
    applicationTargetField,
} from '../support/workflow-target-apply';

// 申請対象からの申請の画面(代理)。筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「申請対象から」と、
// 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.1・9.3。山田 恵 → 吉田 達也の申請の代理(全フロー)が
// 初期データにある(設計/ワークフロー/案件の運用/初期データ.md 4.1)。プロジェクトの見える範囲を筋の間だけ「関係する人だけ」にして戻す
// (作成者と責任者だけが見られる)。2 つ目のフローを足す 2-choose より前に置く(流す順は tests/e2e/demo/groups.ts の並び)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

const CONCURRENT = '吉田 達也（総務部）';

test.beforeAll(({ artisan }) => {
    artisan(['tinker', '--execute', RESTRICT_PROJECTS]);
});

test.afterAll(({ artisan }) => {
    artisan(['tinker', '--execute', RESTORE_PROJECTS]);
});

test('申請対象を閲覧できない代理元に申請者を変えると、申請対象が空になり、画面は残る(見出しの 1 行は新規の申請の文)', async ({
    page,
}) => {
    const name = 'E2E の代理元に見えないプロジェクト';
    await openAs(page, 'concurrent', '/');
    const applicationTargetId = await createProject(page, name);
    await page.goto(`${APPLY_URL}?target=${applicationTargetId}`);
    const form = applyForm(page);
    await expect(applicationTargetField(form)).toContainText(name);
    await expect(
        page.getByText(`${name}を申請対象にして申請します。`),
    ).toBeVisible();

    // 山田 恵は作成者でも責任者でもないので、このプロジェクトを閲覧できない(閲覧できないまま申請対象に入れると読み直しが 404 になる)
    await form.getByRole('combobox', { name: '申請者', exact: true }).click();
    await page.getByRole('option', { name: /山田 恵/ }).click();
    await expect(page).toHaveURL(
        (url) =>
            url.searchParams.has('applicant') &&
            !url.searchParams.has('target'),
    );
    await expect(
        form.getByText('山田 恵さんとして申請します', { exact: true }),
    ).toBeVisible();
    await expect(applicationTargetField(form)).toContainText(
        '選択してください',
    );
    await expect(nameField(form)).toHaveValue(name);
    await expect(
        page.getByText('内容を入力して申請します。', { exact: false }),
    ).toBeVisible();
});

test('代理元だけが閲覧できる申請対象を、代理で申請する(入口の URL の applicant で、申請者の既定は代理元)', async ({
    page,
    artisan,
}) => {
    const name = 'E2E の代理元だけに見えるプロジェクト';
    const principalId = artisan([
        'tinker',
        '--execute',
        USER_ID('u0010'),
    ]).trim();
    await openAs(page, 'accounting', '/');
    const applicationTargetId = await createProject(page, name);
    await switchUser(page, ACCOUNTING, 'concurrent');

    // 吉田 達也は作成者でも責任者でもなく、閲覧できない。代理元の山田 恵だけが閲覧できる
    await page.goto(`/records/data/project/${applicationTargetId}`);
    await expect(
        page.getByRole('main').getByRole('link', { name: '申請する' }),
    ).toHaveCount(0);
    await page.goto(
        `${APPLY_URL}?target=${applicationTargetId}&applicant=${principalId}`,
    );
    const form = applyForm(page);
    await expect(
        form.getByRole('combobox', { name: '申請者', exact: true }),
    ).toContainText('山田 恵');
    await expect(applicationTargetField(form)).toContainText(name);
    await expect(nameField(form)).toHaveValue(name);
    await expect(
        page.getByText(`${name}を申請対象にして申請します。`),
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
    await expect(
        page.getByRole('button', { name: `利用者メニュー：${CONCURRENT}` }),
    ).toBeVisible();
});
