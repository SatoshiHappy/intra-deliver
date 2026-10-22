import { router, usePage } from '@inertiajs/react';
import { RecordHeadingGroups } from '@/components/RecordHeadingGroups';
import { RecordRelatedChips } from '@/components/RecordRelatedChips';
import {
    RecordRelatedMenuItems,
    RecordRelatedTab,
} from '@/components/RecordRelatedTab';
import { RecordShowTabs } from '@/components/RecordShowTabs';
import { RecordDetailsTable } from '@/components/Records/RecordDetailsTable';
import { RecordValue } from '@/components/RecordValue';
import type { useRecordRelated } from '@/hooks/use-record-related';
import { ownValue } from '@/lib/Records/recordAddons';
import { RELATED_TAB, relatedTotal } from '@/lib/recordRelated';
import { isWideField } from '@/lib/recordForm';
import {
    detailsTabKey,
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
} from '@/types';

/** 関連レコードのタブ・チップの状態と操作(rowHref = 行の詳細の URL。ページがルートから作る) */
type RelatedTabState = ReturnType<typeof useRecordRelated> & {
    rowHref: (moduleCode: string, recordId: number) => string;
};

/** タブの中身が受けるもの(notes = 後ろのモジュールが足す、項目の欄の上の 1 行) */
type TabProps = {
    module: RecordModuleDefinition;
    headings: RecordHeadingGroup[];
    record: RecordDetail;
    notes: Record<string, string>;
    related: RelatedTabState;
};

/**
 * 項目の欄 1 つ(名前の下に値)。note = 後ろのモジュールが足す、欄の上の 1 行(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5)。
 * 注は欄の上に見せ、dl の並び(dt の後の dd)は崩さない。
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
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1',
                isWideField(field) && 'col-span-full',
            )}
        >
            <dt className="text-xs text-muted-foreground">{field.name}</dt>
            {note !== undefined && (
                <dd className="order-first text-xs text-muted-foreground">
                    {note}
                </dd>
            )}
            <dd className="min-w-0">
                <RecordValue
                    field={field}
                    value={record.values[field.code] ?? null}
                />
            </dd>
        </div>
    );
}

/**
 * 詳細タブ: 見出しのまとまりごとの項目の格子(列は左の面の幅の段階 = 帯だけで 3 列、パネルを開くと 2 列 =
 * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 1 節。複数行は全幅。見出しの行で畳める = 見出し.md の 2 節)と、
 * 作成・更新の日時と人。notes = 後ろのモジュールが足す、項目の欄の上の 1 行(項目のコード → 文)。
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
 * 選んだタブの中身(詳細 = 見出しのまとまりごとの項目の格子、明細の札 = その明細の表、関連レコード = 選んだ入口の表)。
 */
function TabBody({
    selected,
    selectedField,
    related,
    ...props
}: TabProps & {
    selected: string;
    selectedField: RecordFieldDefinition | undefined;
}) {
    if (selected === RELATED_TAB) {
        return (
            <RecordRelatedTab
                {...related}
                onPage={related.page}
                onNew={related.dialog.openFor}
            />
        );
    }

    return selectedField === undefined ? (
        <FieldsTab {...props} related={related} />
    ) : (
        <RecordDetailsTable
            field={selectedField}
            rows={props.record.details[selectedField.code] ?? []}
        />
    );
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
 * 左のタブ(詳細 / 明細の札 / 関連レコード)。選んだタブは URL のクエリ tab に映す(詳細と明細の札は中身が props にあるので URL だけを置き換え、
 * 関連レコードは表が無ければ部分の再読み込み = 詳細の画面/README.md の 3 節)。
 */
export function RecordDetailTabs({
    module,
    headings,
    record,
    fieldNotes = {},
    related,
}: Omit<TabProps, 'notes'> & { fieldNotes?: Record<string, string> }) {
    const { url } = usePage();
    const detailsFields = module.fields.filter(
        (field) => field.type === 'details',
    );
    const tabs = [
        { key: FIELDS_TAB, label: '詳細', count: null },
        ...detailsFields.map((field) => ({
            key: detailsTabKey(field.code),
            label: field.name,
            count: (record.details[field.code] ?? []).length,
        })),
        ...relatedTabs(related),
    ];
    const selected = selectedTabKey(
        url,
        tabs.map((tab) => tab.key),
    );
    const selectedField = detailsFields.find(
        (field) => detailsTabKey(field.code) === selected,
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
                selectedField={selectedField}
                related={related}
            />
        </RecordShowTabs>
    );
}
