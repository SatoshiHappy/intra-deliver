import { expect, test } from '../support/test';
import { submitApplication } from '../support/workflow-operations';
import {
    expectRecordAttachments,
    fileInput,
    saveDraft,
    startApplication,
    textFile,
} from '../support/workflow-apply-attachment';
import {
    disableModuleAttachment,
    expectApplied,
    expectDisabledScreens,
    expectDrafts,
    pickThenTrim,
    reviseDraft,
} from '../support/workflow-apply-attachment-steps';

// 申請の画面の添付の面: 一時保存の添付(筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「添付(申請)」)。
// 申請書の業務モジュール「プロジェクト計画の申請」とフロー project_plan を使い、申請書のレコードを足すだけで、ほかの spec の件数を変えない。
// 付いた添付は申請書のレコードの業務データの詳細で確かめる(拡張点: 申請の詳細のサイドパネルで確かめるときは、
// support/workflow-apply-attachment.ts の expectRecordAttachments をその確かめに替える)。流す順は名前の順で、
// tests/e2e/demo/groups.ts の並びと同じ。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

/** 筋の終わりに添付の許可を戻す文(業務モジュールの設定の画面で外した分) */
const RESTORE_ATTACHMENT = [
    'DB::table("record_modules")->where("code", "project_plan_application")',
    '->update(["is_attachment_enabled" => true]);',
].join('');

test('一時保存で 2 つ付け、開き直して 1 つ外し 1 つ足して上書きし、申請すると付いた添付が 2 つ', async ({
    page,
}) => {
    const name = 'E2E の添付の計画 A';
    const form = await startApplication(page, name);

    // 面は「コメント」の後・「連絡」の前
    const headings = await form
        .getByRole('heading', { level: 2 })
        .allTextContents();
    expect(headings.slice(-3)).toEqual(['コメント', '添付ファイル', '連絡']);

    await pickThenTrim(page);
    const draftUrl = await saveDraft(page, form);
    await expectDrafts(page, ['first.txt', 'second.txt']);
    await reviseDraft(page, form, draftUrl);
    await expectDrafts(page, ['second.txt', 'fourth.txt']);

    await submitApplication(page, form);
    await expectApplied(page);
    await expectRecordAttachments(page, name, ['fourth.txt', 'second.txt']);
});

test('業務モジュールが添付を許可しないと面が出ず、一時保存の添付が残っていれば行と 1 行だけが出て、外すと申請できる', async ({
    page,
    artisan,
}) => {
    const form = await startApplication(page, 'E2E の添付の計画 B');
    await fileInput(page).setInputFiles(textFile('keep.txt'));
    const draftUrl = await saveDraft(page, form);

    try {
        await disableModuleAttachment(page);
        await expectDisabledScreens(page, draftUrl);
    } finally {
        artisan(['tinker', '--execute', RESTORE_ATTACHMENT]);
    }
});
