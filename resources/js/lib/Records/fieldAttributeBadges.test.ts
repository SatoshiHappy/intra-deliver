import { describe, expect, it } from 'vite-plus/test';
import { recordField } from '@/lib/recordField.testing';
import type { RecordAdminFieldDefinition, RecordFieldType } from '@/types';
import { fieldAttributeBadges } from './fieldAttributeBadges';

/**
 * 管理の画面の項目(相対の既定値は既定で無し)。
 */
function adminField(
    code: string,
    type: RecordFieldType,
    overrides: Partial<RecordAdminFieldDefinition> = {},
): RecordAdminFieldDefinition {
    const { relativeDefault = null, ...definition } = overrides;

    return { ...recordField(code, type, definition), relativeDefault };
}

describe('fieldAttributeBadges', () => {
    it('関係する人の旗は「表示の項目」の後・既定値の前に札「関係する人」', () => {
        expect(
            fieldAttributeBadges(
                adminField('owner', 'user', {
                    isRequired: true,
                    grantsRowAccess: true,
                }),
            ),
        ).toEqual(['必須', '一覧', '検索', '関係する人']);
        expect(
            fieldAttributeBadges(
                adminField('title', 'string', {
                    isTitle: true,
                    defaultValue: '未定',
                }),
            ),
        ).toEqual(['一覧', '検索', '表示の項目', '既定値: 未定']);
    });

    it('複数行の既定値の札は、記法を除いた平文の先頭(1 行の既定値は今のまま)', () => {
        const badges = (isMultiline: boolean) =>
            fieldAttributeBadges(
                adminField('note', 'string', {
                    isMultiline,
                    defaultValue: '**確認**\n- [参照](https://example.com)',
                }),
            ).at(-1);

        expect(badges(true)).toBe('既定値: 確認 参照');
        expect(badges(false)).toBe(
            '既定値: **確認**\n- [参照](https://example.com)',
        );
    });

    it('旗が無ければ札「関係する人」を出さない', () => {
        expect(
            fieldAttributeBadges(adminField('owner', 'department')),
        ).not.toContain('関係する人');
    });
});

describe('fieldAttributeBadges(相対の既定値)', () => {
    it('相対の既定値は「登録の時から <数> <単位>後」', () => {
        const badge = (duration: string) =>
            fieldAttributeBadges(
                adminField('held_on', 'date', { relativeDefault: duration }),
            ).at(-1);

        expect(badge('P6M')).toBe('既定値: 登録の時から 6 か月後');
        expect(badge('P0D')).toBe('既定値: 登録の時から 0 日後');
        expect(badge('P1Y')).toBe('既定値: 登録の時から 1 年後');
    });
});
