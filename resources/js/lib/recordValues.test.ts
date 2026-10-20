import { describe, expect, it } from 'vite-plus/test';
import { recordField } from './recordField.testing';
import {
    formatRecordValue,
    missingChoiceLabel,
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
