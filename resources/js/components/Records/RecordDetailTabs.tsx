import { router, usePage } from '@inertiajs/react';
import { DetailItem } from '@/components/DetailItem';
import { RecordFieldName } from '@/components/RecordFieldBadges';
import { RecordHeadingGroups } from '@/components/RecordHeadingGroups';
import { RecordRelatedChips } from '@/components/RecordRelatedChips';
import {
    RecordRelatedMenuItems,
    RecordRelatedTab,
} from '@/components/RecordRelatedTab';
import { RecordShowTabs } from '@/components/RecordShowTabs';
import { RecordDetailBands } from '@/components/Records/RecordDetailBands';
import { RecordRowAccessSummary } from '@/components/Records/RecordRowAccessSummary';
import { RecordValue } from '@/components/RecordValue';
import type { useRecordRelated } from '@/hooks/use-record-related';
import { ownValue } from '@/lib/Records/recordAddons';
import { RELATED_TAB, relatedTotal } from '@/lib/recordRelated';
import { isWideField } from '@/lib/recordForm';
import {
    ACCESS_TAB,
    FIELDS_TAB,
    personText,
    selectedTabKey,
    urlWithQuery,
} from '@/lib/recordShow';
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
function FieldsTab({ module, headings, record, notes, related }: TabProps) {
    return (
        <div className="flex flex-col gap-5">
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
                                    note={ownValue(notes, field.code)}
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
 * 選んだタブの中身(詳細 = 項目・明細、参照制限 = 読むだけ、関連レコード = 選んだ入口の表)。
 */
function TabBody({
    selected,
    related,
    ...props
}: TabProps & { selected: string }) {
    switch (selected) {
        case RELATED_TAB:
            return (
                <RecordRelatedTab
                    {...related}
                    onPage={related.page}
                    onNew={related.dialog.openFor}
                />
            );
        case ACCESS_TAB:
            return (
                <RecordRowAccessSummary rowAccess={props.record.rowAccess} />
            );
        default:
            return <FieldsTab {...props} related={related} />;
    }
}

/**
 * 関連レコードのタブ(見える入口が無ければ出さない = 関連レコード.md の 3 節)。件数 = 見える入口の件数の和、メニュー = 入口の切り替えと「新規」。
 */
function relatedTabs(related: RelatedTabState) {
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
              },
          ];
}

/**
 * 左のタブ(詳細 / 参照制限 / 関連レコード = 同 4 節)。選んだタブは URL のクエリ tab に映す(詳細と参照制限は中身が props にあるので
 * URL だけを置き換え、関連レコードは表が無ければ部分の再読み込み = 同 3 節)。
 */
export function RecordDetailTabs({
    module,
    headings,
    record,
    fieldNotes = {},
    related,
}: Omit<TabProps, 'notes'> & { fieldNotes?: Record<string, string> }) {
    const { url } = usePage();
    const tabs = [
        { key: FIELDS_TAB, label: '詳細', count: null },
        { key: ACCESS_TAB, label: '参照制限', count: null },
        ...relatedTabs(related),
    ];
    const selected = selectedTabKey(
        url,
        tabs.map((tab) => tab.key),
    );
    const select = (key: string) =>
        key === RELATED_TAB
            ? related.select(null)
            : router.replace({
                  url: urlWithQuery(url, { tab: key }),
                  preserveState: true,
                  preserveScroll: true,
              });

    return (
        <RecordShowTabs tabs={tabs} selected={selected} onSelect={select}>
            <TabBody
                module={module}
                headings={headings}
                record={record}
                notes={fieldNotes}
                selected={selected}
                related={related}
            />
        </RecordShowTabs>
    );
}
