import { expect, test } from '../support/test';
import { openAsAdministrator } from '../support/records-headings';
import {
    ADMIN,
    APPLICANT_LABEL,
    APPLY_URL,
    applyForm,
    attachmentSurface,
    chooseUser,
} from '../support/workflow-apply-attachment';
import {
    PLAN_ADMIN_URL,
    RESTORE_FLOW_ATTACHMENT,
    flowAttachmentSurface,
    saveFlowAttachment,
    useRadio,
} from '../support/workflow-flow-settings';
import { SECOND_QUARTER, openAs } from '../support/workflow-operations';

// 定義の管理の画面の添付ファイルの設定(docs の 設計/ワークフロー/申請の周辺/画面.md の 4 節・9 節の「添付(申請)」「添付(処理)」)。
// zz-workflow-admin(版を足す)の前に流れる(名前の順。この筋は版を足さない)。フローの設定は筋の終わりに戻し、見本の案件は開くだけで処理しない。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

test('システム管理者: タブ「設定」の面「添付ファイル」でキャンセルは今の値に戻り、「使用しない」を保存すると申請の画面に添付の面が出ない', async ({
    page,
    artisan,
}) => {
    try {
        await openAsAdministrator(page, `${PLAN_ADMIN_URL}?tab=settings`);
        await expect(
            page.getByRole('heading', { level: 2, name: '添付ファイル' }),
        ).toBeVisible();
        await page
            .getByRole('button', { name: '添付ファイルの説明', exact: true })
            .focus();
        await expect(page.getByRole('tooltip')).toContainText(
            '申請と処理で、添付ファイルを使用するかどうかを設定します。申請中の申請は申請した時の設定のまま進みます。',
        );
        await expect(useRadio(page, '使用する')).toBeChecked();

        // 変えてキャンセルすると、今の値に戻る
        await useRadio(page, '使用しない').click();
        await expect(useRadio(page, '使用しない')).toBeChecked();
        await flowAttachmentSurface(page)
            .getByRole('button', { name: 'キャンセル', exact: true })
            .click();
        await expect(useRadio(page, '使用する')).toBeChecked();

        await saveFlowAttachment(page, false);
        await chooseUser(page, ADMIN, '申請者');
        await page.goto(APPLY_URL);
        await expect(applyForm(page)).toBeVisible();
        await expect(attachmentSurface(page)).toHaveCount(0);
    } finally {
        artisan(['tinker', '--execute', RESTORE_FLOW_ATTACHMENT]);
    }

    // 戻した設定を画面でも確かめる(申請の画面に添付の面が出る)
    await page.goto(APPLY_URL);
    await expect(attachmentSurface(page)).toBeVisible();
    await chooseUser(page, APPLICANT_LABEL, 'システム管理者');
    await page.goto(`${PLAN_ADMIN_URL}?tab=settings`);
    await expect(useRadio(page, '使用する')).toBeChecked();
});

test('「追加できない」ステップの処理のダイアログには添付の欄が無い(初期データの版 3 の部長の承認待ち)', async ({
    page,
}) => {
    await openAs(page, 'manager', '/workflow/cases');
    await page
        .getByRole('table', { name: '未処理の申請' })
        .getByRole('link', { name: SECOND_QUARTER, exact: true })
        .click();
    await expect(
        page.getByRole('heading', { level: 1, name: SECOND_QUARTER }),
    ).toBeVisible();
    await page
        .getByRole('main')
        .getByRole('button', { name: '承認', exact: true })
        .click();

    const dialog = page.getByRole('dialog', { name: '承認する' });
    await expect(dialog).toBeVisible();
    await expect(
        dialog.getByRole('button', { name: '決定', exact: true }),
    ).toBeVisible();
    await expect(
        dialog.getByRole('group', { name: '添付ファイル', exact: true }),
    ).toHaveCount(0);
});

test('システム管理者: 詳細の見出しの右の「認可設定で見る」は新しいタブでフローの種類の認可設定を開き、元のタブは詳細のまま', async ({
    page,
}) => {
    await openAsAdministrator(page, PLAN_ADMIN_URL);
    const detailUrl = page.url();
    const link = page.getByRole('link', {
        name: '認可設定で見る',
        exact: true,
    });
    await expect(link).toHaveAttribute('target', '_blank');

    // 新しいタブは context の page のイベントで受ける(target=_blank・rel=noopener)
    const opened = page.context().waitForEvent('page');
    await link.click();
    const overview = await opened;
    await overview.waitForLoadState();

    await expect(overview).toHaveURL(/\/authz\/overview\?type=workflow-flow$/);
    await expect(
        overview.getByRole('heading', { level: 1, name: '認可設定' }),
    ).toBeVisible();
    expect(page.url()).toBe(detailUrl);
    await overview.close();
});

test('システム管理者: ステップの設定の最後のカード「添付ファイル」で、部長は「追加できない」を選んでいる(承認のステップは処理期限・確認する人の後のカード 6)', async ({
    page,
}) => {
    await openAsAdministrator(page, `${PLAN_ADMIN_URL}?node=manager`);
    const heading = page.getByRole('heading', {
        level: 3,
        name: '添付ファイル',
        exact: true,
    });

    await expect(heading).toBeVisible();
    await expect(heading).toContainText('6');
    await expect(
        page.getByRole('radio', { name: '追加できる', exact: true }),
    ).not.toBeChecked();
    await expect(
        page.getByRole('radio', { name: '追加できない', exact: true }),
    ).toBeChecked();
    await page
        .getByRole('button', { name: '添付ファイルの説明', exact: true })
        .focus();
    await expect(page.getByRole('tooltip')).toContainText(
        'このステップの処理で、添付ファイルを追加できるかどうかを設定します。',
    );
});
