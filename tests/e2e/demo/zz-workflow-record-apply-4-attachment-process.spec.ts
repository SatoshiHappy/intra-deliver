import { expect, test } from '../support/test';
import {
    textFile,
    expectRecordAttachments,
} from '../support/workflow-apply-attachment';
import {
    addedRows,
    applyCase,
    attachmentField,
    backToApplicant,
    decide,
    dialogFileInput,
    openApproveAsChief,
    shoot,
} from '../support/workflow-process-attachment';
import {
    approveAnswered413,
    expectBlockedBeforeSending,
    expectRowError,
    pickThenTrim,
    removeChosen,
} from '../support/workflow-process-attachment-steps';
import { DENY_UPDATE } from '../support/workflow-record-apply';

// 処理のダイアログの添付の欄: 承認(筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 3 節・9 節の「添付(処理)」)。
// 「追加できない」ステップで欄が無い筋は、ステップのカードの設定の筋(設計/ワークフロー/申請の周辺/画面.md の 4 節)で作る。申請書の業務モジュールの更新の許可を
// 外す zz-workflow-record-apply-3-others の後に流す(名前の順)。付いた添付は申請書のレコードの業務データの詳細で確かめる。
test.use({ viewport: { width: 1280, height: 900 } });
test.describe.configure({ mode: 'serial' });

test('処理担当者が申請書の業務モジュールの更新の許可を持たなくても、処理のダイアログから付けて承認すると付く', async ({
    page,
    artisan,
}) => {
    artisan(['tinker', '--execute', DENY_UPDATE]);
    const name = 'E2E の処理の添付 A';
    const caseUrl = await applyCase(page, name);
    const dialog = await openApproveAsChief(page, caseUrl);

    await pickThenTrim(page, dialog);
    await shoot(page, 'process-dialog-attachment');
    await decide(page, dialog, caseUrl);
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();

    await backToApplicant(page);
    await expectRecordAttachments(page, name, ['first.txt', 'third.txt']);
});

test('送る前の確かめ・413・サーバーの誤りでダイアログと選んだファイルが残り、直して送ると付く', async ({
    page,
}) => {
    const name = 'E2E の処理の添付 B';
    const caseUrl = await applyCase(page, name);
    const dialog = await openApproveAsChief(page, caseUrl);

    await expectBlockedBeforeSending(page, dialog);
    await approveAnswered413(page, dialog);
    await expectRowError(dialog);
    await expect(attachmentField(dialog)).toBeVisible();

    await dialogFileInput(dialog).setInputFiles(textFile('ok.txt'));
    await expect(addedRows(dialog)).toHaveCount(1);
    await decide(page, dialog, caseUrl);
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();

    await backToApplicant(page);
    await expectRecordAttachments(page, name, ['ok.txt']);
});

test('選んだファイルを全部外すと、ファイルなしの承認がそのまま通る', async ({
    page,
}) => {
    const caseUrl = await applyCase(page, 'E2E の処理の添付 C');
    const dialog = await openApproveAsChief(page, caseUrl);

    await dialogFileInput(dialog).setInputFiles(textFile('gone.txt'));
    await removeChosen(dialog, ['gone.txt']);
    await decide(page, dialog, caseUrl);
    await expect(page.getByText('承認しました', { exact: true })).toBeVisible();
});
