import { test } from '../support/test';
import {
    ADMIN,
    APPLICANT_LABEL,
    chooseUser,
    fileInput,
    saveDraft,
    startApplication,
    textFile,
} from '../support/workflow-apply-attachment';
import { expectDisabledScreens } from '../support/workflow-apply-attachment-steps';
import {
    RESTORE_FLOW_ATTACHMENT,
    saveFlowAttachment,
} from '../support/workflow-flow-settings';

// 申請の画面の添付の面: フローを「使用しない」にした後の一時保存(筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の
// 「添付(申請)」の後半)。フローの設定は管理者の画面で替え、筋の終わりに戻す。申請書のレコードを足すだけで、ほかの spec の件数を変えない。
// 2d の後・3 の前に流れる(名前の順。申請書の業務モジュールの更新の許可を外す 3 より前)。
test.use({ viewport: { width: 1280, height: 720 } });
test.describe.configure({ mode: 'serial' });

test('一時保存で添付を付けた後にフローを「使用しない」にすると、面に一時保存の添付の行と 1 行だけが出て、外すと申請できる', async ({
    page,
    artisan,
}) => {
    const form = await startApplication(page, 'E2E の添付の計画 F');
    await fileInput(page).setInputFiles(textFile('keep.txt'));
    const draftUrl = await saveDraft(page, form);

    try {
        await chooseUser(page, APPLICANT_LABEL, 'システム管理者');
        await saveFlowAttachment(page, false);
        await chooseUser(page, ADMIN, '申請者');
        await expectDisabledScreens(page, draftUrl);
    } finally {
        artisan(['tinker', '--execute', RESTORE_FLOW_ATTACHMENT]);
    }
});
