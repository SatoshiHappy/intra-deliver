import { describe, expect, it } from 'vite-plus/test';
import {
    candidateNotice,
    canDeleteComment,
    commentErrors,
    NO_COMMENT_ERRORS,
} from './recordComments';

describe('commentErrors', () => {
    it('body と mentions と mentions.<添字> だけを拾い、ほかのキーは無視する', () => {
        expect(
            commentErrors({
                body: 'コメントを入力してください',
                'mentions.1': '宛先に指定できないユーザーが含まれています',
                mentions: '宛先は 10 人までです',
                other: '無視',
            }),
        ).toEqual({
            body: 'コメントを入力してください',
            mentions: [
                '宛先に指定できないユーザーが含まれています',
                '宛先は 10 人までです',
            ],
        });
        expect(commentErrors({})).toEqual(NO_COMMENT_ERRORS);
    });

    it('同じ文言の宛先の誤り(mentions.0 と mentions.1)は 1 件にまとめる', () => {
        expect(
            commentErrors({
                'mentions.0': '宛先に指定できないユーザーが含まれています',
                'mentions.1': '宛先に指定できないユーザーが含まれています',
            }).mentions,
        ).toEqual(['宛先に指定できないユーザーが含まれています']);
    });
});

describe('canDeleteComment', () => {
    const live = { deleted: null, mine: false };
    const mark = { deleted: { by: null, at: null }, mine: false };

    it('自分の分か管理の権限があれば出し、削除の印の行には出さない', () => {
        expect(canDeleteComment({ ...live, mine: true }, false)).toBe(true);
        expect(canDeleteComment(live, true)).toBe(true);
        expect(canDeleteComment(live, false)).toBe(false);
        expect(canDeleteComment({ ...mark, mine: true }, true)).toBe(false);
    });
});

describe('candidateNotice', () => {
    const idle = { waiting: false, failed: false, found: 2 };

    it('語が空なら入力を促し、待ち・失敗・該当なし・候補ありの順に知らせる', () => {
        expect(candidateNotice(' ', idle)).toBe('検索する語を入力してください');
        expect(candidateNotice('中', { ...idle, failed: true })).toBe(
            '候補を取得できませんでした',
        );
        expect(candidateNotice('中', { ...idle, found: null })).toBe('検索中…');
        expect(candidateNotice('中', { ...idle, waiting: true })).toBe(
            '検索中…',
        );
        expect(candidateNotice('中', { ...idle, found: 0 })).toBe(
            '該当する候補はありません',
        );
        expect(candidateNotice('中', idle)).toBeNull();
    });
});
