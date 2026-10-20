import { describe, expect, it } from 'vite-plus/test';
import type { RecordFieldType } from '@/types';
import { attributeFlags } from './recordFieldFlags';

const labelsOf = (type: RecordFieldType, isMultiline = false) =>
    attributeFlags({ type, isMultiline }).map((flag) => flag.label);

describe('attributeFlags', () => {
    it('ユーザー・組織は「関係する人」を最後に出し、説明を付ける', () => {
        expect(labelsOf('user')).toEqual([
            '必須',
            '一覧に表示',
            '検索条件にする',
            '関係する人',
        ]);
        expect(labelsOf('department')).toContain('関係する人');

        const flag = attributeFlags({ type: 'user', isMultiline: false }).at(
            -1,
        );
        expect(flag).toMatchObject({
            key: 'grantsRowAccess',
            inputKey: 'grants_row_access',
        });
        expect(flag?.hint).toContain('見える範囲が「関係する人だけ」');
    });

    it('ほかの型は「関係する人」を出さない', () => {
        const others: RecordFieldType[] = [
            'string',
            'integer',
            'decimal',
            'date',
            'datetime',
            'boolean',
            'choice',
            'record',
            'details',
        ];

        for (const type of others) {
            expect(labelsOf(type)).not.toContain('関係する人');
        }
    });

    it('今までの出し分け(真偽は必須なし・明細は必須だけ・表示の項目は 1 行の文字列だけ)', () => {
        expect(labelsOf('boolean')).toEqual(['一覧に表示', '検索条件にする']);
        expect(labelsOf('details')).toEqual(['必須(1 行以上)']);
        expect(labelsOf('string')).toContain('表示の項目');
        expect(labelsOf('string', true)).not.toContain('表示の項目');
    });
});
