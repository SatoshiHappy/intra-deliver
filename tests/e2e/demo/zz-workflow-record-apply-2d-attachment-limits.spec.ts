import { expect, test } from '../support/test';
import { submitApplication } from '../support/workflow-operations';
import {
    expectRecordAttachments,
    fileInput,
    startApplication,
    textFile,
} from '../support/workflow-apply-attachment';
import { expectApplied } from '../support/workflow-apply-attachment-steps';
import {
    applyAnswered413,
    chooseOversized,
    expectBlockedBeforeSending,
    removeChosen,
} from '../support/workflow-apply-attachment-sizes';

// 申請の画面の添付の面: 要求の大きさ(筋の正本 = docs の 設計/ワークフロー/申請の周辺/画面.md の 9 節の「要求の大きさ」)。
// 既定の上限(95MB)では 3MB のファイルは止まらないので、画面を開いた時の props の上限を読み、1 ファイルの上限より 1 バイト大きい
// 中身の無いファイルを選ぶ(送らない)。流す順は名前の順で、zz-workflow-record-apply-3-others より前。
test.use({ viewport: { width: 1280, height: 720 } });

test('送る前の確かめ(4 つ・1 ファイルの上限超)は送らずトースト、413 の応答もトーストで入力と選んだファイルが残り、送り直せる', async ({
    page,
}, testInfo) => {
    const name = 'E2E の添付の計画 E';
    const form = await startApplication(page, name);
    const posts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST') {
            posts.push(request.url());
        }
    });

    const four = ['a.txt', 'b.txt', 'c.txt', 'd.txt'];
    await fileInput(page).setInputFiles(four.map(textFile));
    await expectBlockedBeforeSending(page, form);
    await removeChosen(page, four);

    await chooseOversized(page, testInfo.outputPath('big.txt'));
    await expectBlockedBeforeSending(page, form);
    expect(posts).toEqual([]);
    await removeChosen(page, ['big.txt']);

    await fileInput(page).setInputFiles(textFile('ok.txt'));
    await applyAnswered413(page, form, name);

    await submitApplication(page, form);
    await expectApplied(page);
    await expectRecordAttachments(page, name, ['ok.txt']);
});
