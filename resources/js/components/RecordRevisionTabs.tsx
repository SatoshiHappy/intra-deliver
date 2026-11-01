import type { ReactNode } from 'react';
import { RecordRevisionFields } from '@/components/RecordRevisionFields';
import { RecordRowAccessSummary } from '@/components/RecordRowAccessSummary';
import { RecordTabsFrame } from '@/components/RecordTabsFrame';
import type { RecordFrameTab } from '@/components/RecordTabsFrame';
import { ACCESS_TAB, FIELDS_TAB } from '@/lib/recordShow';
import type { RecordRevisionView } from '@/types';

type Tab = { key: string; label: string; body: ReactNode };

/**
 * 版の写しで描く左のタブ(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 2 節・4.2): 詳細 / 呼び手のタブ(extraTab) /
 * 参照制限(写しに参照制限の記録が無ければ出さない) / 最後のタブ(lastTab。件数は持たず、中身は呼び手が渡す)。
 * 枠と URL への映しは {@link RecordTabsFrame}、レコードの今の値で描く形は RecordDetailTabs。
 */
export function RecordRevisionTabs({
    view,
    fieldsNotice,
    extraTab,
    viewersNote,
    lastTab,
}: {
    view: RecordRevisionView;
    /** 詳細タブの先頭の 1 行 */
    fieldsNotice?: ReactNode;
    extraTab?: Tab;
    /** タブ「参照制限」の項目「参照者」の名前の横の注の文 */
    viewersNote?: string;
    lastTab?: Tab;
}) {
    const tabs: RecordFrameTab[] = [
        {
            key: FIELDS_TAB,
            label: '詳細',
            body: <RecordRevisionFields view={view} notice={fieldsNotice} />,
        },
        ...(extraTab === undefined ? [] : [extraTab]),
        ...(view.rowAccess === null
            ? []
            : [
                  {
                      key: ACCESS_TAB,
                      label: '参照制限',
                      body: (
                          <RecordRowAccessSummary
                              rowAccess={view.rowAccess}
                              viewersNote={viewersNote}
                          />
                      ),
                  },
              ]),
        ...(lastTab === undefined ? [] : [lastTab]),
    ];

    return <RecordTabsFrame tabs={tabs} />;
}
