import { describe, expect, it } from 'vite-plus/test';
import { FIELD_TYPE_CHOICES, fieldTypeLabel } from './recordDefinitions';

describe('FIELD_TYPE_CHOICES', () => {
    it('「ユーザー」「組織」「ユーザーと組織」のそれぞれ直後に複数の選択肢を置く', () => {
        expect(FIELD_TYPE_CHOICES.map(fieldTypeLabel)).toEqual([
            '文字列(1 行)',
            '文字列(複数行)',
            '整数',
            '小数',
            '日付',
            '日時',
            '真偽',
            '選択',
            'ユーザー',
            'ユーザー(複数)',
            '組織',
            '組織(複数)',
            'ユーザーと組織',
            'ユーザーと組織(複数)',
            '他モジュール参照',
            '明細',
        ]);
    });

    it('複数の選択肢は型 user・department・user_or_department と isMultiple で送る', () => {
        const multiple = FIELD_TYPE_CHOICES.filter(
            (choice) => choice.isMultiple,
        );

        expect(
            multiple.map(({ type, isMultiline, isMultiple }) => ({
                type,
                isMultiline,
                isMultiple,
            })),
        ).toEqual([
            { type: 'user', isMultiline: false, isMultiple: true },
            { type: 'department', isMultiline: false, isMultiple: true },
            {
                type: 'user_or_department',
                isMultiline: false,
                isMultiple: true,
            },
        ]);
        expect(new Set(FIELD_TYPE_CHOICES.map((c) => c.value)).size).toBe(
            FIELD_TYPE_CHOICES.length,
        );
    });
});

describe('fieldTypeLabel', () => {
    it('作った項目の型の名前は is_multiple で分ける', () => {
        const label = (
            type: 'user' | 'department' | 'user_or_department',
            isMultiple: boolean,
        ) => fieldTypeLabel({ type, isMultiline: false, isMultiple });

        expect(label('user', false)).toBe('ユーザー');
        expect(label('user', true)).toBe('ユーザー(複数)');
        expect(label('department', false)).toBe('組織');
        expect(label('department', true)).toBe('組織(複数)');
        expect(label('user_or_department', false)).toBe('ユーザーと組織');
        expect(label('user_or_department', true)).toBe('ユーザーと組織(複数)');
    });
});
