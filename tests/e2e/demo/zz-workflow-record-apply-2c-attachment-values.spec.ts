import { test } from '../support/test';
import { openAs, submitApplication } from '../support/workflow-operations';
import { createRecord } from '../support/workflow-record-apply';
import {
    addMilestone,
    expectRecordAttachments,
    fileInput,
    openPlanRecord,
    saveDraft,
    startApplication,
    textFile,
} from '../support/workflow-apply-attachment';
import {
    attachToRecord,
    clearDetailsAndApply,
    expectApplied,
    expectAttachedRows,
    expectNoDetails,
    expectTypedValues,
    openApplyFromRecord,
} from '../support/workflow-apply-attachment-steps';

// 申請の画面の添付の面: 値の型・明細・付いている添付(筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「添付(申請)」)。
// 付いている添付の筋は、フローを選ぶダイアログと、申請書のレコードへの添付の追加(update の許可)を使うので、2 つ目のフローを足す
// zz-workflow-record-apply-2-choose より後・update の許可を外す zz-workflow-record-apply-3-others より前に流れる名前にする。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

test('参照・真偽・明細を持つ申請書に添付つきで申請すると、値の型が保たれて通る', async ({
    page,
}) => {
    const name = 'E2E の添付の計画 C';
    const form = await startApplication(page, name);
    await addMilestone(form, 1, 'E2E の節目 1', true);
    await addMilestone(form, 2, 'E2E の節目 2', false);
    await fileInput(page).setInputFiles(textFile('typed.txt'));
    await submitApplication(page, form);
    await expectApplied(page);

    await openPlanRecord(page, name);
    await expectTypedValues(page, name);
    await expectRecordAttachments(page, name, ['typed.txt']);
});

test('一時保存の明細を全部消して添付つきで申請すると、明細の行が消える', async ({
    page,
}) => {
    const name = 'E2E の添付の計画 D';
    const form = await startApplication(page, name);
    await addMilestone(form, 1, 'E2E の節目 3', false);
    await addMilestone(form, 2, 'E2E の節目 4', true);
    const draftUrl = await saveDraft(page, form);

    await clearDetailsAndApply(page, form, draftUrl);
    await expectNoDetails(page, name, 'empty-details.txt');
});

test('既存のデータからの申請の画面は、付いている添付を名前と大きさだけで表示する(削除を出さない)', async ({
    page,
}) => {
    await openAs(page, 'applicant', '/');
    const recordUrl = await createRecord(page, 'E2E の添付の計画 F');
    await attachToRecord(page, recordUrl);
    await openApplyFromRecord(page, recordUrl);
    await expectAttachedRows(page);
});
