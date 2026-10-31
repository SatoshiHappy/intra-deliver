import { describe, expect, it } from 'vite-plus/test';
import { assignNode, moveNode, reexpandNode } from './caseOperation.testing';
import {
    BACK_BLOCKED_HELP,
    DELETE_CONFIRM_TEXT,
    FORWARD_BLOCKED_HELP,
    MOVE_DIRECTIONS,
    MOVE_TYPE_HELP,
    OPERATION_LABELS,
    OPERATION_SURFACE_NOTE,
    OPERATION_TITLES,
    assignHelpOf,
    assignOptionOf,
    isAssignRequired,
    blockedDirectionHelpOf,
    moveCalloutOf,
    operationSubtitleOf,
    reexpandCalloutOf,
    reexpandHelpOf,
    retargetCalloutOf,
    retargetHelpOf,
} from './caseOperationTexts';

describe('面と確かめの文', () => {
    it('ボタンの語・題・面の 1 行は設計の文のとおり', () => {
        expect(OPERATION_LABELS).toEqual({
            retarget: '処理担当者の変更',
            reexpand: '再展開',
            move: 'ステップの移動',
            assign: '指定の変更',
            release: '保留解除',
            destroy: '申請の削除',
        });
        expect(OPERATION_TITLES).toEqual({
            retarget: '処理担当者を変更する',
            reexpand: '再展開する',
            move: 'ステップを移動する',
            assign: '指定を変更する',
        });
        expect(OPERATION_SURFACE_NOTE).toBe(
            '申請操作の権限がある人だけに表示されます。',
        );
    });

    it('削除の確かめの文は申請書も一緒に消えることを示す', () => {
        expect(DELETE_CONFIRM_TEXT).toBe(
            'この申請を削除しますか。申請書(申請内容・添付ファイル・コメント・更新履歴)も一緒に削除されます。この操作は取り消せません。',
        );
    });

    it('2 行目は、処理担当者の変更と再展開が「{申請名}　{ステップ名}」(全角の空白)、ほかは申請名だけ', () => {
        expect(operationSubtitleOf('retarget', '新製品', '部長')).toBe(
            '新製品　部長',
        );
        expect(operationSubtitleOf('reexpand', '新製品', '部長')).toBe(
            '新製品　部長',
        );
        expect(operationSubtitleOf('move', '新製品', '部長')).toBe('新製品');
        expect(operationSubtitleOf('assign', '新製品', '部長')).toBe('新製品');
    });
});

describe('処理担当者の変更の文', () => {
    it('「?」は 1 人以上、展開の行は 1 人', () => {
        expect(retargetHelpOf(false)).toBe(
            '1 人以上を指定します。自分自身を除く、申請基準日に有効な利用者を指定できます。',
        );
        expect(retargetHelpOf(true)).toBe(
            '1 人を指定します。自分自身を除く、申請基準日に有効な利用者を指定できます。',
        );
    });

    it('注意の囲みの見出しは保留中かで替わり、文は同じ', () => {
        const text =
            '変更後の処理担当者の未処理に表示され、通知が届きます。差戻しなどで再び到達すると、フロー定義の指定で展開し直します。';

        expect(retargetCalloutOf(true)).toEqual({
            title: '保留は解除されます',
            text,
        });
        expect(retargetCalloutOf(false)).toEqual({
            title: '処理担当者が替わります',
            text,
        });
    });
});

describe('再展開の文', () => {
    it('注意の囲みは保留中のときだけ見出しを持つ', () => {
        const text = '展開した結果が 0 人の場合は、再展開できません。';

        expect(reexpandCalloutOf(true)).toEqual({
            title: '保留は解除されます',
            text,
        });
        expect(reexpandCalloutOf(false)).toEqual({ title: null, text });
    });

    it('「?」は要約があるときだけ(要約が無ければ undefined =「?」を出さない)', () => {
        expect(reexpandHelpOf(reexpandNode({ summary: null }))).toBeUndefined();
        expect(reexpandHelpOf(undefined)).toBeUndefined();
        expect(
            reexpandHelpOf(reexpandNode({ summary: '申請者の上位組織の部長' })),
        ).toBe('申請者の上位組織の部長を、申請基準日で展開した結果です。');
    });
});

describe('ステップの移動の文', () => {
    it('移動の種類は戻る・進む・終了の順で、「?」の文は設計のとおり', () => {
        expect(MOVE_DIRECTIONS).toEqual([
            { value: 'back', label: '戻る' },
            { value: 'forward', label: '進む' },
            { value: 'finish', label: '終了' },
        ]);
        expect(MOVE_TYPE_HELP).toBe(
            '戻るは、差戻しと同じ結果になります。進むは、現在のステップを承認したときと同じように進みます。終了は、承認終了と同じように申請を完了します。',
        );
        expect(FORWARD_BLOCKED_HELP).toBe(
            '次のステップの指定が必要なため、このステップは進められません。',
        );
    });

    it('押せない戻る・進むだけに「?」の文が付く(終了・ステップが未選択は無し)', () => {
        const blocked = moveNode({ backNodes: [], canForward: false });
        const open = moveNode({
            backNodes: [{ nodeId: 1, name: '申請' }],
            canForward: true,
        });

        expect(blockedDirectionHelpOf('back', blocked)).toBe(BACK_BLOCKED_HELP);
        expect(blockedDirectionHelpOf('forward', blocked)).toBe(
            FORWARD_BLOCKED_HELP,
        );
        expect(blockedDirectionHelpOf('finish', blocked)).toBeNull();
        expect(blockedDirectionHelpOf('back', open)).toBeNull();
        expect(blockedDirectionHelpOf('forward', open)).toBeNull();
        expect(blockedDirectionHelpOf('back', undefined)).toBeNull();
        expect(BACK_BLOCKED_HELP).toBe(
            '戻せるステップがないため、このステップは戻せません。',
        );
    });

    it('注意の囲みは移動の種類ごとの見出しと文(switch の 3 つ)', () => {
        expect(moveCalloutOf('back')).toEqual({
            title: '戻り先以降のステップは、未到達に戻ります',
            text: '戻り先の処理担当者の未処理に表示され、通知が届きます。',
        });
        expect(moveCalloutOf('forward')).toEqual({
            title: '現在のステップを処理済みにして、次へ進みます',
            text: '次のステップの処理担当者の未処理に表示され、通知が届きます。',
        });
        expect(moveCalloutOf('finish')).toEqual({
            title: '申請を完了します',
            text: '承認終了と同じように申請を完了します。この操作は取り消せません。',
        });
    });
});

describe('指定の変更の文', () => {
    it('選択肢の文は「{種類の語}「{名前}」　まだ到達していません」', () => {
        expect(assignOptionOf(assignNode())).toBe(
            '並列承認「関係部署の合議」　まだ到達していません',
        );
    });

    it('人数の「?」は最小と最大を入れる', () => {
        expect(assignHelpOf(assignNode({ min: 2, max: 5 }))).toBe(
            '到達していない動的承認や配置のステップの指定を書き直します。2 人から 5 人まで指定します。最小が 0 人のステップは、0 人にすると削除されます。',
        );
        expect(assignHelpOf(undefined)).toBeUndefined();
    });

    it('札「必須」は最小が 1 人以上のステップだけ(最小 0 人は 0 人にすると削除される)', () => {
        expect(isAssignRequired(assignNode({ min: 1, max: 3 }))).toBe(true);
        expect(isAssignRequired(assignNode({ min: 0, max: 3 }))).toBe(false);
        expect(isAssignRequired(undefined)).toBe(false);
    });
});
