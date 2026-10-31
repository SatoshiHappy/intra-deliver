import { describe, expect, it } from 'vite-plus/test';
import {
    preventsLine,
    requiredCountText,
    targetHeading,
    targetKindChoices,
    targetLine,
} from './stepCardTexts';

describe('targetHeading', () => {
    it('申請は「誰が申請できるか」、ほかは「誰が承認するか」', () => {
        expect(targetHeading('apply')).toBe('誰が申請できるか');
        expect(targetHeading('approve')).toBe('誰が承認するか');
        expect(targetHeading('serial')).toBe('誰が承認するか');
        expect(targetHeading('parallel')).toBe('誰が承認するか');
    });
});

describe('targetLine(見出しの下の 1 行の 5 行)', () => {
    it('申請・対象者で指定', () => {
        expect(targetLine('apply', 'fixed')).toBe(
            '申請できる人を、ユーザー、組織、役職、ロールで指定します。',
        );
    });

    it('承認・横配置・対象者で指定', () => {
        expect(targetLine('approve', 'fixed')).toBe(
            '承認者を、ユーザー、組織、役職、ロールで指定します。',
        );
        expect(targetLine('serial', 'fixed')).toBe(
            '承認者を、ユーザー、組織、役職、ロールで指定します。',
        );
    });

    it('承認・基準にする人で指定', () => {
        expect(targetLine('approve', 'relative')).toBe(
            '承認者を、基準にする人、組織、役職で指定します。',
        );
    });

    it('承認・横配置・処理の時に指定', () => {
        expect(targetLine('approve', 'designated')).toBe(
            '承認者を、誰がいつ選択するかを設定します。',
        );
        expect(targetLine('serial', 'designated')).toBe(
            '承認者を、誰がいつ選択するかを設定します。',
        );
    });

    it('縦配置・処理の時に指定', () => {
        expect(targetLine('parallel', 'designated')).toBe(
            '合議に参加する人を、誰がいつ選択するかを設定します。',
        );
    });

    it('指定の種類が無ければ出さない', () => {
        expect(targetLine('approve', '')).toBeNull();
    });
});

describe('preventsLine', () => {
    it('縦配置だけ合議の処理対象者の文', () => {
        expect(preventsLine('approve')).toBe(
            'オンにすると、申請者本人はこのステップで自分の申請を承認できません。',
        );
        expect(preventsLine('serial')).toBe(preventsLine('approve'));
        expect(preventsLine('parallel')).toBe(
            'オンにすると、申請者本人を合議の処理対象者に指定できません。',
        );
    });
});

describe('targetKindChoices', () => {
    it('申請は対象者だけ、承認は 3 種類、配置は処理の時だけ、分岐・同期は無い', () => {
        expect(targetKindChoices('apply')).toEqual(['fixed']);
        expect(targetKindChoices('approve')).toEqual([
            'fixed',
            'relative',
            'designated',
        ]);
        expect(targetKindChoices('serial')).toEqual(['designated']);
        expect(targetKindChoices('parallel')).toEqual(['designated']);
        expect(targetKindChoices('branch_start')).toEqual([]);
        expect(targetKindChoices('sync_end')).toEqual([]);
    });
});

describe('requiredCountText', () => {
    it('可決の条件が人数で必要な人数が数なら文を出す', () => {
        expect(requiredCountText('count', '2')).toBe(
            '2 人が承認したら次へ進みます。',
        );
        expect(requiredCountText('count', ' 03 ')).toBe(
            '3 人が承認したら次へ進みます。',
        );
    });

    it('人数でない条件・数でない入力・空は出さない', () => {
        expect(requiredCountText('all', '2')).toBeNull();
        expect(requiredCountText('ratio', '50')).toBeNull();
        expect(requiredCountText('', '2')).toBeNull();
        expect(requiredCountText('count', '')).toBeNull();
        expect(requiredCountText('count', 'abc')).toBeNull();
        expect(requiredCountText('count', '-1')).toBeNull();
    });
});
