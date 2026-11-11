import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import type { ColumnGroup } from '@/lib/Authz/gridColumns';
import type { AuthzGridSetting, AuthzGridSubject } from '@/types';
import { Legend } from './Legend';
import { SettingCell } from './SettingCell';
import { SettingsHead } from './SettingsHead';

function subject(
    id: number,
    name: string,
    category: AuthzGridSubject['category'],
): AuthzGridSubject {
    return {
        id,
        name,
        category,
        matches: false,
        builtin: true,
        policyCount: null,
        fingerprint: null,
    };
}

const LOGIN = { code: 'im_authz_meta_subject', name: 'ログインの状態' };
const ROLE = { code: 'b_m_role', name: 'ロール' };

function head(actions: string[], groups: ColumnGroup[] = defaultGroups()) {
    return renderToStaticMarkup(
        createElement(SettingsHead, {
            groups,
            actions,
            canDeleteColumn: false,
        }),
    );
}

function defaultGroups() {
    return [
        {
            category: LOGIN,
            subjects: [
                subject(1, 'ゲストユーザー', LOGIN),
                subject(2, 'ログイン済みユーザー', LOGIN),
            ],
        },
        {
            category: ROLE,
            subjects: [subject(3, 'システム管理者', ROLE)],
        },
    ];
}

function cell(setting: AuthzGridSetting | undefined, editing: boolean) {
    return renderToStaticMarkup(
        createElement(
            'table',
            null,
            createElement(
                'tbody',
                null,
                createElement(
                    'tr',
                    null,
                    createElement(SettingCell, {
                        setting,
                        band: false,
                        press: editing
                            ? { onPress: () => undefined, saving: false }
                            : null,
                    }),
                ),
            ),
        ),
    );
}

describe('SettingsHead(「設定」タブの見出し)', () => {
    it('各列の対象者の名前の下に種類を小さく出し、カテゴリの段は置かない', () => {
        const markup = head(['execute']);

        expect(markup).toContain('ゲストユーザー');
        expect(markup).toContain(
            '<span class="block text-xs font-normal text-muted-foreground">ログインの状態</span>',
        );
        expect(markup).toContain('ロール</span>');
        expect(markup).not.toContain('colgroup');
        // 行は対象者の段だけ(アクションが 1 つのときはアクションの段も無い)
        expect(markup.match(/<tr/g)).toHaveLength(1);
    });

    it('アクションが 2 つ以上のときだけアクションの段を足し、見出しは 2 段まで', () => {
        const markup = head(['read', 'create']);

        expect(markup.match(/<tr/g)).toHaveLength(2);
        expect(markup).toContain('rowSpan="2"');
        expect(markup).not.toContain('colgroup');
    });

    it('種類の名前が無いカテゴリはコードを出す', () => {
        const unnamed = { code: 'a:b', name: null };

        expect(
            head(
                ['execute'],
                [{ category: unnamed, subjects: [subject(9, '列', unnamed)] }],
            ),
        ).toContain('>a:b</span>');
    });
});

describe('SettingCell(設定なしは空欄)', () => {
    it.each([
        ['読み取り', false],
        ['編集中', true],
    ])(
        '%s: 設定なしのセルは印が無く、読み上げの「設定なし」だけがある',
        (_label, editing) => {
            const markup = cell(undefined, editing);

            expect(markup).toContain('<span class="sr-only">設定なし</span>');
            expect(markup).not.toContain('<svg');
            expect(markup).not.toContain('lucide');
        },
    );

    it('編集中は設定なしのセルも押せるボタンのまま', () => {
        expect(cell(undefined, true)).toContain('<button');
        expect(cell(undefined, false)).not.toContain('<button');
    });
});

describe('Legend(凡例)', () => {
    it('「設定」タブに「設定なし」の行が無く、ほかの行は残る', () => {
        const markup = renderToStaticMarkup(
            createElement(Legend, { tab: 'settings' }),
        );

        expect(markup).not.toContain('設定なし');
        expect(markup).toContain('許可(直接)');
        expect(markup).toContain('判定する人に該当する対象者');
    });
});
