import type { ReactNode } from 'react';
import { DetailItem } from '@/components/DetailItem';
import { RecordHeadingGroups } from '@/components/RecordHeadingGroups';
import { RecordValue } from '@/components/RecordValue';
import { RecordDetailBands } from '@/components/RecordDetailBands';
import { isWideField } from '@/lib/recordForm';
import {
    revisionDetailField,
    revisionDetailRows,
    revisionGroupFields,
    revisionHeadingGroups,
} from '@/lib/recordRevisionView';
import { cn } from '@/lib/utils';
import type { RecordRevisionView } from '@/types';

/**
 * 版の写しで描く詳細タブ。今のレコードの値を持たないので、今の値の詳細タブ(RecordDetailTabs)と別に描く。
 * 描き方の正本 = 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2(写しの描き方)。
 */
export function RecordRevisionFields({
    view,
    notice,
}: {
    view: RecordRevisionView;
    notice?: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-5">
            {notice}
            <RecordHeadingGroups
                groups={revisionHeadingGroups(view)}
                idPrefix="record-revision"
            >
                {(group) => (
                    <dl className="grid grid-cols-1 gap-x-6 gap-y-4 text-sm @md:grid-cols-2 @3xl:grid-cols-3">
                        {revisionGroupFields(view, group.id).map((field) => (
                            <DetailItem
                                key={field.code}
                                name={field.name}
                                className={cn(
                                    isWideField(field) && 'col-span-full',
                                )}
                            >
                                <RecordValue
                                    field={field}
                                    value={view.values[field.code] ?? null}
                                />
                            </DetailItem>
                        ))}
                    </dl>
                )}
            </RecordHeadingGroups>
            <RecordDetailBands
                fields={view.details.map(revisionDetailField)}
                details={revisionDetailRows(view)}
                idPrefix="record-revision"
            />
        </div>
    );
}
