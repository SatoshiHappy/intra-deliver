import { describe, expect, it } from 'vite-plus/test';
import { fieldErrorKeys, stepFieldsOf } from './stepFields';

// docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md の 3.3 節のデシジョン表の行ごと(行の番号は表の番号)。
// 起きない行(申請・配置で指定の方法が違う、縦配置でない種類に可決の条件、縦配置に可決の条件が無い)は DB の検査制約が止める
// (tests/Feature/Models/Workflow/WorkflowNodeSettingsTest.php)。
describe('stepFieldsOf: カード 4 の無い種類', () => {
    it('1: 申請・対象者で指定 = 対象者だけ(指定の方法は選べるのが 1 つなので出さない)', () => {
        expect(stepFieldsOf('apply', 'fixed', '')).toEqual({
            target: ['subjects'],
            decision: [],
        });
    });

    it('2: 承認・対象者で指定 = 指定の方法・対象者', () => {
        expect(stepFieldsOf('approve', 'fixed', '')).toEqual({
            target: ['targetKind', 'subjects'],
            decision: [],
        });
    });

    it('3: 承認・基準にする人で指定 = 指定の方法・基準にする人・組織・役職', () => {
        expect(stepFieldsOf('approve', 'relative', '')).toEqual({
            target: ['targetKind', 'relative'],
            decision: [],
        });
    });

    it('4: 承認・処理の時に指定 = 指定の方法・選択する人・人数の最小と最大(カード 4 が無いのでカード 1)', () => {
        expect(stepFieldsOf('approve', 'designated', '')).toEqual({
            target: ['targetKind', 'designator', 'range'],
            decision: [],
        });
    });

    it('5: 横配置・処理の時に指定 = 選択する人・人数の最小と最大', () => {
        expect(stepFieldsOf('serial', 'designated', '')).toEqual({
            target: ['designator', 'range'],
            decision: [],
        });
    });
});

describe('stepFieldsOf: 縦配置と、設定を持たない種類', () => {
    it('6: 縦配置・処理の時に指定・全員 = カード 1 は選択する人、カード 4 は人数と可決の条件だけ', () => {
        expect(stepFieldsOf('parallel', 'designated', 'all')).toEqual({
            target: ['designator'],
            decision: ['range', 'decisionRule'],
        });
    });

    it('7: 縦配置・処理の時に指定・人数 = カード 4 に必要な人数', () => {
        expect(stepFieldsOf('parallel', 'designated', 'count')).toEqual({
            target: ['designator'],
            decision: ['range', 'decisionRule', 'count'],
        });
    });

    it('8: 縦配置・処理の時に指定・割合 = カード 4 に必要な割合', () => {
        expect(stepFieldsOf('parallel', 'designated', 'ratio')).toEqual({
            target: ['designator'],
            decision: ['range', 'decisionRule', 'ratio'],
        });
    });

    it('9: 分岐・同期 = 欄なし(設定を持たないのでカードも出さない)', () => {
        expect(stepFieldsOf('branch_start', '', '')).toEqual({
            target: [],
            decision: [],
        });
        expect(stepFieldsOf('sync_end', '', '')).toEqual({
            target: [],
            decision: [],
        });
    });
});

describe('fieldErrorKeys', () => {
    it('出している欄の誤りのキーだけ', () => {
        expect(
            fieldErrorKeys(stepFieldsOf('parallel', 'designated', 'count')),
        ).toEqual([
            'designator_node_code',
            'min_assignees',
            'max_assignees',
            'decision_rule',
            'decision_value',
        ]);
        expect(fieldErrorKeys(stepFieldsOf('apply', 'fixed', ''))).toEqual([
            'targets',
        ]);
    });
});
