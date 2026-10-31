import { describe, expect, it } from 'vite-plus/test';
import {
    UNPICKED_TITLE,
    councilBoxTitle,
    frameHeaderTexts,
} from './diagramFrames';
import { node } from './diagramLayout.testing';
import { stepBoxToneOf } from './diagramMarks';

// 案件の図の箱と枠の見出しの見た目(docs の 設計/ワークフロー/ルートの深さ/画面/図と意匠とE2E.md 8 節)。
describe('stepBoxToneOf(申請・承認と展開の行の箱の地)', () => {
    it('処理済みの箱は処理済みの札の地、承認待ちの箱は承認待ちの札の地(太い縁)', () => {
        expect(stepBoxToneOf({ state: 'done', label: '処理済み' })).toBe(
            'settled',
        );
        expect(stepBoxToneOf({ state: 'current', label: '承認待ち' })).toBe(
            'pending',
        );
    });

    it('保留中・差戻し・到達していない・省略と、状態の無い定義の図は card の地', () => {
        for (const state of [
            'hold',
            'returned',
            'upcoming',
            'skipped',
        ] as const) {
            expect(stepBoxToneOf({ state, label: state })).toBe('plain');
        }

        expect(stepBoxToneOf(undefined)).toBe('plain');
    });
});

describe('frameHeaderTexts(横配置・縦配置の枠の見出し)', () => {
    it('左は種類と名前、右は短い文だけで、状態の札(承認待ちの語)を含まない', () => {
        const council = node(5, 'parallel', {
            name: '関係部署の合議',
            subtitle: '承認者は課長が選択',
            stepState: { state: 'current', label: '承認待ち' },
        });

        expect(frameHeaderTexts(council, '並列承認')).toEqual({
            title: '並列承認「関係部署の合議」',
            subtitle: '承認者は課長が選択',
        });
    });

    it('短い文が無い枠は右を出さない', () => {
        expect(frameHeaderTexts(node(4, 'serial'), '順次承認')).toEqual({
            title: '順次承認「ノード4」',
            subtitle: null,
        });
    });
});

describe('councilBoxTitle(並列承認の枠の中の箱の題)', () => {
    const person = (name: string, isMe: boolean) => ({
        id: 1,
        code: 'u0001',
        name,
        mainDepartmentId: null,
        isMe,
    });

    it('人の名前を題にし、自分には「(自分)」を添え、番号の題は出さない', () => {
        const child = node(7, 'approve', {
            name: '関係部署の合議 承認 1',
            people: [person('吉田 達也', true)],
        });

        expect(councilBoxTitle(child)).toBe('吉田 達也(自分)');
        expect(
            councilBoxTitle({ ...child, people: [person('山田 恵', false)] }),
        ).toBe('山田 恵');
    });

    it('人がまだ選ばれていない箱は「未選択」', () => {
        expect(councilBoxTitle(node(8, 'approve'))).toBe(UNPICKED_TITLE);
        expect(UNPICKED_TITLE).toBe('未選択');
    });
});
