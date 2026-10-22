import { describe, expect, it } from 'vite-plus/test';
import {
    actionLabel,
    blockedLabel,
    judgementTitle,
    resourceTypeLabel,
} from './labels';

describe('actionLabel', () => {
    it('表にあるアクションは画面の語、無ければ ID のまま', () => {
        expect(actionLabel('read')).toBe('参照');
        expect(actionLabel('approve')).toBe('approve');
    });

    it('業務モジュールの全件の参照は「全件の参照」', () => {
        expect(actionLabel('read-all')).toBe('全件の参照');
    });

    it('ワークフローのフローの操作は「操作」', () => {
        expect(actionLabel('operate')).toBe('操作');
    });
});

describe('resourceTypeLabel', () => {
    it('ワークフローのフローは「ワークフローのフロー」、表に無ければ ID のまま', () => {
        expect(resourceTypeLabel('workflow-flow')).toBe('ワークフローのフロー');
        expect(resourceTypeLabel('no-such-type')).toBe('no-such-type');
    });
});

describe('blockedLabel', () => {
    it('全部のアクションが閉塞なら「閉塞中」だけ', () => {
        expect(blockedLabel(['read', 'admin'], ['read', 'admin'])).toBe(
            '閉塞中',
        );
    });

    it('一部なら閉塞のアクションの語を括弧に並べる', () => {
        expect(
            blockedLabel(['read', 'update'], ['read', 'update', 'admin']),
        ).toBe('閉塞中(参照、更新)');
    });
});

describe('judgementTitle', () => {
    it('効いた対象者の無い禁止は既定の禁止と出す', () => {
        expect(judgementTitle('deny', [])).toBe('該当する設定なし(既定の禁止)');
    });

    it('対象者のある禁止と、ほかの結果は出さない', () => {
        expect(judgementTitle('deny', ['営業部'])).toBeUndefined();
        expect(judgementTitle('permit', [])).toBeUndefined();
        expect(judgementTitle('block', [])).toBeUndefined();
    });
});
