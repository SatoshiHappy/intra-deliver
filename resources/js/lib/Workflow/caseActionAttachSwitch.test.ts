import { describe, expect, it } from 'vite-plus/test';
import { attachmentErrorsOf, filesToSendOf } from './applyAttachment';
import { sendShapeOf } from './attachmentSend';
import { attachableOf, showAttachOf } from './caseActionAttach';
import {
    node,
    nodeAction,
    proxy,
    self,
    show,
    target,
} from './caseActionAttach.testing';
import { initialFormOf, payloadOf } from './caseActionForm';

/** 処理者を 自分(真)→ 代理元(偽)→ 自分(真)と替えたときの、見せる・送るファイルと誤りの置き場 */
const steps: [
    string,
    string,
    boolean,
    number,
    Record<number, string>,
    string[],
][] = [
    ['自分(真)', '9', true, 2, { 1: '行の誤り' }, ['欄の誤り']],
    ['代理元(偽)', '3', false, 0, {}, ['欄の誤り']],
    ['自分に戻す(真)', '9', true, 2, { 1: '行の誤り' }, ['欄の誤り']],
];

describe('可否が偽になったとき・真に戻ったとき(表。規則の正本 = 設計/ワークフロー/申請の周辺/画面.md の 3 節)', () => {
    const chosen = [new File(['a'], 'a.pdf'), new File(['b'], 'b.pdf')];
    const errors = {
        'attachments.1': '行の誤り',
        attachments: '欄の誤り',
    };
    const attach = showAttachOf(show);

    it.each(steps)(
        '%s: 選んだファイルは持ったまま、出す・送るのは真のときだけ',
        (_name, selected, attachable, sent, rows, surface) => {
            expect(attachableOf(attach, target(), node(), selected)).toBe(
                attachable,
            );
            expect(filesToSendOf({ enabled: attachable }, chosen)).toHaveLength(
                sent,
            );
            expect(attachmentErrorsOf(errors, sent, attachable)).toEqual({
                rows,
                surface,
            });
        },
    );

    it('操作を替えても同じ(承認では真の自分が、保留では偽)', () => {
        const hold = target({ operation: 'hold' });

        expect(attachableOf(attach, target(), node(), '9')).toBe(true);
        expect(attachableOf(attach, hold, node(), '9')).toBe(false);
    });

    it('送信の誤りの戻りで props が変わると、同じ入口の可否が偽になり、戻ると真に戻る', () => {
        const before = showAttachOf(show);
        const stopped = showAttachOf({
            ...show,
            nodeActions: [
                nodeAction(11, {
                    approve: [{ ...self, canAttach: false }, proxy],
                }),
            ],
            attachmentLimits: null,
        });

        expect(attachableOf(before, target(), node(), '9')).toBe(true);
        expect(attachableOf(stopped, target(), node(), '9')).toBe(false);
        expect(attachableOf(before, target(), node(), '9')).toBe(true);
    });
});

describe('送る形(ファイルあり・なし)', () => {
    const t = target();
    const data = {
        ...initialFormOf(t),
        comment: '確認しました',
    };

    it('ファイルが無ければ入力のまま、あれば封筒の JSON とファイル', () => {
        const payload = payloadOf(data, t, []);
        const files = [new File(['a'], 'a.pdf')];

        expect(sendShapeOf(payload, [])).toBe(payload);
        expect(sendShapeOf(payload, files)).toEqual({
            envelope: JSON.stringify(payload),
            attachments: files,
        });
    });
});
