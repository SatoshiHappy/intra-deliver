import type { ReactNode } from 'react';
import { DetailItem } from '@/components/DetailItem';
import { RecordFieldName } from '@/components/RecordFieldBadges';
import { RecordHeadingGroups } from '@/components/RecordHeadingGroups';
import { RecordRelatedChips } from '@/components/RecordRelatedChips';
import {
    RecordRelatedMenuItems,
    RecordRelatedTab,
} from '@/components/RecordRelatedTab';
import { RecordDetailBands } from '@/components/RecordDetailBands';
import { RecordRowAccessSummary } from '@/components/RecordRowAccessSummary';
import { RecordTabsFrame } from '@/components/RecordTabsFrame';
import type { RecordFrameTab } from '@/components/RecordTabsFrame';
import { RecordValue } from '@/components/RecordValue';
import type { useRecordRelated } from '@/hooks/use-record-related';
import { RELATED_TAB, relatedTotal } from '@/lib/recordRelated';
import { isWideField } from '@/lib/recordForm';
import { ACCESS_TAB, FIELDS_TAB, personText } from '@/lib/recordShow';
import { EMPTY_VALUE, formatDatetime } from '@/lib/recordValues';
import { cn } from '@/lib/utils';
import type {
    RecordDetail,
    RecordFieldDefinition,
    RecordHeadingGroup,
    RecordModuleDefinition,
    RecordShowDetail,
} from '@/types';

/** 関連レコードのタブ・チップの状態と操作(rowHref = 行の詳細の URL。ページがルートから作る) */
type RelatedTabState = ReturnType<typeof useRecordRelated> & {
    rowHref: (moduleCode: string, recordId: number) => string;
};

/** タブの中身が受けるもの(notes = 後ろのモジュールが足す、項目の名前の横の注) */
type TabProps = {
    module: RecordModuleDefinition;
    headings: RecordHeadingGroup[];
    record: RecordShowDetail;
    notes: Record<string, string>;
    related: RelatedTabState;
    /** 詳細タブの先頭の 1 行(呼び手が渡す。無ければ出さない) */
    notice?: ReactNode;
};

/**
 * 項目の欄 1 つ(名前の下に値)。note = 後ろのモジュールが足す、名前の横の i(Tooltip)の注(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。
 */
function FieldItem({
    field,
    record,
    note,
}: {
    field: RecordFieldDefinition;
    record: RecordDetail;
    note: string | undefined;
}) {
    return (
        <DetailItem
            name={<RecordFieldName field={field} />}
            note={
                note === undefined
                    ? undefined
                    : { name: field.name, text: note }
            }
            className={cn(isWideField(field) && 'col-span-full')}
        >
            <RecordValue
                field={field}
                value={record.values[field.code] ?? null}
            />
        </DetailItem>
    );
}

/**
 * 詳細タブ(中の並び = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 4 節): 関連レコードのチップ、見出しのまとまりごとの
 * 項目の格子(列は左の面の幅の段階 = 帯だけで 3 列、パネルを開くと 2 列 = 同 1 節。複数行は全幅。見出しの行で畳める = 見出し.md の 2 節)、
 * 明細の帯と表、作成・更新の日時と人。notes = 後ろのモジュールが足す、項目の名前の横の注(項目のコード → 文)。
 */
function FieldsTab({
    module,
    headings,
    record,
    notes,
    related,
    notice,
}: TabProps) {
    return (
        <div className="flex flex-col gap-5">
            {notice}
            <RecordRelatedChips
                entries={related.entries}
                onSelect={related.select}
            />
            <RecordHeadingGroups groups={headings} idPrefix="record-show">
                {(group) => (
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm @md:grid-cols-2 @3xl:grid-cols-3">
                        {module.fields
                            .filter((field) =>
                                group.fieldCodes.includes(field.code),
                            )
                            .map((field) => (
                                <FieldItem
                                    key={field.code}
                                    field={field}
                                    record={record}
                                    note={
                                        Object.hasOwn(notes, field.code)
                                            ? notes[field.code]
                                            : undefined
                                    }
                                />
                            ))}
                    </dl>
                )}
            </RecordHeadingGroups>
            <RecordDetailBands
                fields={module.fields.filter(
                    (field) => field.type === 'details',
                )}
                details={record.details}
                idPrefix="record-show"
            />
            <Authors record={record} />
        </div>
    );
}

/** 作成・更新の日時と人 */
function Authors({ record }: { record: RecordDetail }) {
    const authors = [
        ['作成', record.createdAt, record.createdBy],
        ['更新', record.updatedAt, record.updatedBy],
    ] as const;

    return (
        <dl className="grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {authors.map(([label, at, by]) => [
                <dt key={`${label}-t`}>{label}</dt>,
                <dd key={`${label}-d`}>
                    <span className="font-num tabular-nums">
                        {at === null ? EMPTY_VALUE : formatDatetime(at)}
                    </span>{' '}
                    {personText(by)}
                </dd>,
            ])}
        </dl>
    );
}

/**
 * 関連レコードのタブ(見える入口が無ければ出さない = 関連レコード.md の 3 節)。件数 = 見える入口の件数の和、メニュー = 入口の切り替えと「新規」。
 */
function relatedTabs(related: RelatedTabState): RecordFrameTab[] {
    return related.entries.length === 0
        ? []
        : [
              {
                  key: RELATED_TAB,
                  label: '関連レコード',
                  count: relatedTotal(related.entries),
                  menu: (
                      <RecordRelatedMenuItems
                          entries={related.entries}
                          selectedKey={related.selectedKey}
                          onSelect={related.select}
                          onNew={related.dialog.openFor}
                      />
                  ),
                  body: (
                      <RecordRelatedTab
                          {...related}
                          onPage={related.page}
                          onNew={related.dialog.openFor}
                      />
                  ),
              },
          ];
}

/** 呼び手(申請の詳細など)が足す、汎用の受け口 */
type Additions = {
    /** 詳細の後ろに差し込むタブ(中身は呼び手が持つ) */
    extraTab?: { key: string; label: string; body: ReactNode };
    /** 詳細タブの先頭の 1 行 */
    fieldsNotice?: ReactNode;
    /** タブ「参照制限」の項目「参照者」の名前の横の注の文 */
    viewersNote?: string;
};

/**
 * 左のタブ(詳細 / 呼び手のタブ / 参照制限 / 関連レコード = 同 4 節)。枠は {@link RecordTabsFrame}(クエリ tab への映し)。
 */
export function RecordDetailTabs({
    module,
    headings,
    record,
    fieldNotes = {},
    related,
    extraTab,
    fieldsNotice,
    viewersNote,
}: Omit<TabProps, 'notes' | 'notice'> & {
    fieldNotes?: Record<string, string>;
} & Additions) {
    const tabs: RecordFrameTab[] = [
        {
            key: FIELDS_TAB,
            label: '詳細',
            body: (
                <FieldsTab
                    module={module}
                    headings={headings}
                    record={record}
                    notes={fieldNotes}
                    related={related}
                    notice={fieldsNotice}
                />
            ),
        },
        ...(extraTab === undefined ? [] : [extraTab]),
        {
            key: ACCESS_TAB,
            label: '参照制限',
            body: (
                <RecordRowAccessSummary
                    rowAccess={record.rowAccess}
                    viewersNote={viewersNote}
                />
            ),
        },
        ...relatedTabs(related),
    ];

    return (
        <RecordTabsFrame tabs={tabs} onRelated={() => related.select(null)} />
    );
}
