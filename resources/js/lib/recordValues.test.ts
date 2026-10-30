import { describe, expect, it } from 'vite-plus/test';
import { recordField } from './recordField.testing';
import {
    formatRecordValue,
    missingChoiceLabel,
    referenceLabel,
    shownReferences,
    toTokyoLocal,
} from './recordValues';

const choiceField = recordField('status', 'choice', {
    name: '状態',
    options: [
        { code: 'open', name: '受付' },
        { code: 'done', name: '完了' },
    ],
});

describe('toTokyoLocal', () => {
    it('UTC の 15:00 は Asia/Tokyo の翌日 0:00 になる', () => {
        expect(toTokyoLocal('2026-10-10T15:00:00Z')).toBe('2026-10-11T00:00');
    });

    it('時差つきの文字列を Asia/Tokyo の datetime-local の形にする', () => {
        expect(toTokyoLocal('2026-10-11T09:30:00+09:00')).toBe(
            '2026-10-11T09:30',
        );
    });

    it('読めない文字列は null', () => {
        expect(toTokyoLocal('not a date')).toBeNull();
    });
});

describe('missingChoiceLabel', () => {
    it('空の値と選択肢にある値は null', () => {
        expect(missingChoiceLabel(choiceField, '')).toBeNull();
        expect(missingChoiceLabel(choiceField, 'done')).toBeNull();
    });

    it('選択肢に無い値は、値に「(選択肢にありません)」を付けて出す', () => {
        expect(missingChoiceLabel(choiceField, 'closed')).toBe(
            'closed(選択肢にありません)',
        );
    });
});

const members = recordField('members', 'user', { isMultiple: true });

const sections = recordField('sections', 'department', { isMultiple: true });

/** 参照の値(コード = c<id>) */
function target(id: number, label: string | null) {
    return { id, code: `c${id}`, label, affiliation: null };
}

/** 表示名の並びの参照の値(id は 1 から) */
function targets(...labels: string[]) {
    return labels.map((label, index) => target(index + 1, label));
}

describe('formatRecordValue(複数選べる参照)', () => {
    const three = targets('高橋 誠', '中村 美咲', '山田 恵');

    it('2 人までは表のセルでも「、」でつなぐ', () => {
        expect(
            formatRecordValue(members, targets('高橋 誠', '中村 美咲'), 'cell'),
        ).toBe('高橋 誠、中村 美咲');
    });

    it('表のセルで 3 人以上なら、先頭の 2 人と「ほか <N> 名」', () => {
        expect(formatRecordValue(members, three, 'cell')).toBe(
            '高橋 誠、中村 美咲 ほか 1 名',
        );
    });

    it('組織は「ほか <N> 件」', () => {
        expect(
            formatRecordValue(
                sections,
                targets('総務部', '経理部', '人事部', '開発部'),
                'cell',
            ),
        ).toBe('総務部、経理部 ほか 2 件');
    });

    it('詳細と差分は全員を「、」でつなぐ(既定は詳細。表示名が無ければコード)', () => {
        const all = '高橋 誠、中村 美咲、山田 恵';

        expect(formatRecordValue(members, three)).toBe(all);
        expect(formatRecordValue(members, three, 'detail')).toBe(all);
        expect(formatRecordValue(members, three, 'diff')).toBe(all);
        expect(formatRecordValue(members, [target(7, null)])).toBe('c7');
    });

    it('空の配列と null は未設定の表示', () => {
        expect(formatRecordValue(members, [], 'cell')).toBe('—');
        expect(formatRecordValue(members, null, 'cell')).toBe('—');
    });
});

const parties = recordField('parties', 'user_or_department', {
    isMultiple: true,
});

const host = recordField('host', 'user_or_department');

/** 型「ユーザーと組織」の相手(コード = c<id>) */
function party(type: 'user' | 'department', id: number, label: string | null) {
    return { type, ...target(id, label) };
}

describe('formatRecordValue(型「ユーザーと組織」)', () => {
    it('表のセルで 3 つ以上なら、人だけでも「ほか <N> 件」', () => {
        const people = [
            party('user', 1, '高橋 誠'),
            party('user', 2, '中村 美咲'),
            party('user', 3, '山田 恵'),
        ];

        expect(formatRecordValue(parties, people, 'cell')).toBe(
            '高橋 誠、中村 美咲 ほか 1 件',
        );
    });

    it('人と組織が混ざっても、並びの順に先頭の 2 つと「ほか <N> 件」', () => {
        const mixed = [
            party('department', 3, '開発部'),
            party('user', 3, '高橋 誠'),
            party('department', 4, '総務部'),
            party('user', 5, '中村 美咲'),
        ];

        expect(formatRecordValue(parties, mixed, 'cell')).toBe(
            '開発部、高橋 誠 ほか 2 件',
        );
        expect(formatRecordValue(parties, mixed)).toBe(
            '開発部、高橋 誠、総務部、中村 美咲',
        );
    });

    it('名前の無い相手は、コードに種類を添える(1 つの値も同じ)', () => {
        const nameless = [party('user', 3, null), party('department', 3, null)];

        expect(formatRecordValue(parties, nameless)).toBe(
            'c3(ユーザー)、c3(組織)',
        );
        expect(formatRecordValue(host, party('department', 7, null))).toBe(
            'c7(組織)',
        );
        expect(formatRecordValue(host, party('user', 7, '高橋 誠'))).toBe(
            '高橋 誠',
        );
    });
});

describe('shownReferences', () => {
    it('表のセルの数の境界: 2 つまでは全部、3 つから先頭の 2 つと残りの数', () => {
        const cell = (count: number) =>
            shownReferences(
                parties,
                Array.from({ length: count }, (_, index) =>
                    party('user', index + 1, `u${index + 1}`),
                ),
                'cell',
            );

        expect(cell(1)).toMatchObject({ rest: null });
        expect(cell(2).shown).toHaveLength(2);
        expect(cell(2).rest).toBeNull();
        expect(cell(3).shown.map((value) => value.id)).toEqual([1, 2]);
        expect(cell(3).rest).toBe('ほか 1 件');
        expect(cell(101).rest).toBe('ほか 99 件');
    });

    it('詳細と差分は添え書きを付けない', () => {
        const three = [1, 2, 3].map((id) => party('user', id, `u${id}`));

        expect(shownReferences(parties, three, 'detail')).toEqual({
            shown: three,
            rest: null,
        });
        expect(shownReferences(members, three, 'diff').rest).toBeNull();
    });
});

describe('formatRecordValue(複数行の文字列)', () => {
    const note = recordField('note', 'string', { isMultiline: true });
    const markdown = '# 見出し\n\n[公式](https://example.com) と **太字**';

    it('表のセルは、記法を除いた平文の先頭', () => {
        expect(formatRecordValue(note, markdown, 'cell')).toBe(
            '見出し 公式 と 太字',
        );
    });

    it('詳細と差分は、記法のままの文字列(描くのは RecordValue)', () => {
        expect(formatRecordValue(note, markdown)).toBe(markdown);
        expect(formatRecordValue(note, markdown, 'diff')).toBe(markdown);
    });

    it('未設定は、どの場所でも「—」', () => {
        expect(formatRecordValue(note, null, 'cell')).toBe('—');
        expect(formatRecordValue(note, '', 'cell')).toBe('—');
    });

    it('1 行の文字列は、表のセルでも記法を除かない', () => {
        const line = recordField('title', 'string');

        expect(formatRecordValue(line, '**太字**', 'cell')).toBe('**太字**');
    });
});

describe('種類の判定は項目の型で決める(表示)', () => {
    const owner = recordField('owner', 'user');
    const untyped = target(3, '高橋 誠');
    const nameless = target(3, null);

    it('型「ユーザーと組織」の項目で種類の無い値は、名前の有無によらず投げる(1 つ・複数)', () => {
        expect(() => formatRecordValue(host, untyped)).toThrow();
        expect(() => formatRecordValue(host, nameless)).toThrow();
        expect(() => formatRecordValue(parties, [untyped])).toThrow();
        expect(() => formatRecordValue(parties, [nameless])).toThrow();
        expect(() => referenceLabel(host, untyped)).toThrow();
        expect(() => referenceLabel(host, nameless)).toThrow();
    });

    it('ほかの参照の型の項目は、種類を持つ値でも種類の名前を付けない', () => {
        expect(referenceLabel(owner, party('user', 3, null))).toBe('c3');
        expect(formatRecordValue(members, [party('user', 3, null)])).toBe('c3');
    });
});
