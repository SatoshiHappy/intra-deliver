/**
 * レコードの詳細のサイドパネルと can の型(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/README.md の 6.1 節。
 * サーバーの App\Queries\Records\Api\RecordShowPanelQuery と入口が返す形)。業務ツールの節の中身は
 * 節の口(詳細の画面/節の口.md の 1 節)の @phpstan-type を同じ名前で写す。records.ts が大きさの上限に近いので分けた。
 * 後ろのモジュールが一覧と詳細に足すものの型も、ここに置く(末尾)。
 */
import type {
    RecordFieldType,
    RecordFieldValue,
    RecordReferenceValue,
} from './records';
import type { StatusTone } from './status';

/** レコードの詳細の can(権限の判定だけ。持ち主の有無は hold) */
export type RecordDetailCan = {
    update: boolean;
    delete: boolean;
    copy: boolean; // 業務モジュールの create(複製)
    attach: boolean; // 業務モジュールの update かつ添付ファイルの追加・削除を許可する
    apply: boolean; // 申請の入口(applyEntries)がある
};

/** サイドパネルの欄(帯の 1 項目)。並び = 添付ファイル → コメント → 更新履歴 → 業務ツールの節 */
export type RecordPanelItem = {
    key: string; // 'attachments' | 'comments' | 'revisions' | 節の code(クエリ panel の値)
    name: string; // 正式の名前
    shortName: string; // 帯の名前(4 字まで)
    count: number | null; // null = 件数を出さない
    kind: 'attachments' | 'comments' | 'revisions' | 'section';
};

/** 業務ツールの節(帯の 1 欄)の名札と件数(中身は開いた時に panelContent で返す) */
type RecordShowSection = {
    code: string;
    heading: string;
    shortHeading: string;
    count: number | null;
};

export type RecordShowSectionLink = { label: string; url: string };

export type RecordShowTableSection = {
    kind: 'table';
    count: number | null;
    columns: {
        code: string;
        label: string;
        type: 'title' | 'text' | 'date' | 'datetime' | 'status';
    }[];
    rows: {
        code: string;
        url: string | null;
        cells: { text: string; tone: StatusTone | null }[];
    }[];
    emptyText: string;
    more: RecordShowSectionLink | null;
};

export type RecordShowListSection = {
    kind: 'list';
    count: number | null;
    items: {
        code: string;
        title: string;
        url: string | null;
        note: string | null;
        at: string | null;
    }[];
    emptyText: string;
    more: RecordShowSectionLink | null;
};

export type RecordShowLinesSection = {
    kind: 'lines';
    count: number | null;
    note: string | null;
    items: { time: string | null; label: string | null; text: string }[];
    emptyText: string;
};

/** 節の失敗(「表示できませんでした」・件数なし) */
export type RecordShowFailedSection = { kind: 'failed' };

/** 添付ファイルの欄の 1 行(新しい順)。開けない行(期限で実体を消した)は URL が null */
export type RecordAttachmentRow = {
    id: number;
    uploadId: number;
    name: string;
    size: number; // バイト
    mime: string;
    previewable: boolean;
    attachedBy: RecordReferenceValue | null; // null = ゲスト
    attachedAt: string | null;
    purgeDueOn: string | null; // 期限で実体を消した時の期限の日(YYYY-MM-DD)
    downloadUrl: string | null;
    previewUrl: string | null;
};

/**
 * コメントの欄の 1 行(新しい順)。削除の印の行は本文・書いた人・宛先を持たず、削除した人と日時を持つ(deleted)。
 * mine = 自分の書いたコメント(削除できる)。ほかの人の分を消せるかは欄の canDeleteOthers。
 */
export type RecordCommentRow = {
    id: number;
    author: RecordReferenceValue | null;
    body: string | null;
    createdAt: string | null;
    mentions: RecordReferenceValue[];
    deleted: { by: RecordReferenceValue | null; at: string | null } | null;
    mine: boolean;
};

/** コメントの宛先の候補 1 人(宛先の候補の口の JSON の 1 要素) */
export type RecordMentionCandidate = {
    id: number;
    name: string;
    department: string | null;
};

/** 更新履歴の変化の値を描く項目の定義(後の版の定義の写し。値の部品 RecordValue に渡す分) */
export type RecordRevisionField = {
    code: string;
    name: string;
    type: RecordFieldType;
    isMultiline: boolean;
    referencedModule: { code: string } | null;
};

/** 明細の行の変化(行は id で突き合わせる。値 = 項目のコード → 値) */
type RecordRevisionRowChange = {
    change: 'added' | 'removed' | 'changed';
    rowId: number;
    before: Record<string, RecordFieldValue> | null;
    after: Record<string, RecordFieldValue> | null;
    changedCodes: string[];
};

/** 添付の変化。削除の行だけ外した時点のファイルを開ける(開けなければ URL が null。期限で消したら purgeDueOn) */
export type RecordRevisionAttachmentChange = {
    change: 'added' | 'removed';
    id: number;
    name: string;
    uploadId: number | null;
    mime: string | null;
    previewable: boolean;
    purgeDueOn: string | null;
    downloadUrl: string | null;
    previewUrl: string | null;
};

/** 版の変化(項目 → 明細 → 添付の順) */
export type RecordRevisionChanges = {
    fields: {
        code: string;
        name: string;
        field: RecordRevisionField;
        before: RecordFieldValue;
        after: RecordFieldValue;
    }[];
    details: {
        code: string;
        name: string;
        fields: RecordRevisionField[];
        rows: RecordRevisionRowChange[];
    }[];
    attachments: RecordRevisionAttachmentChange[];
};

/**
 * 更新履歴の欄の 1 行(新しい版から)。頭 = 日時・操作した人(actor。null = ゲスト。principal があれば代理の形)・操作
 * (origin があれば契機の札とステップ名、無ければ action)。changes = null は頭だけ(登録・差分の無い版)。
 */
export type RecordRevisionRow = {
    id: number;
    revision: number;
    at: string | null;
    action: 'create' | 'update';
    actor: RecordReferenceValue | null;
    principal: RecordReferenceValue | null;
    origin: { operation: string; nodeName: string | null } | null;
    noPreviousRecord: boolean; // 前の版が無い update の版(「この版より前の記録はありません。」)
    changes: RecordRevisionChanges | null;
};

/**
 * 開いた欄の中身(欄の種類ごと)。section の content = null は節が出す内容を返さなかった(「表示する内容がありません」)。
 */
export type RecordPanelContent =
    | {
          kind: 'attachments';
          rows: RecordAttachmentRow[];
          hasMore: boolean;
          notice: string | null; // 使用しない業務モジュール・申請済みの 1 行
          guide: string; // 「ファイルを追加」の案内の 1 行(上限と形式)
      }
    | {
          kind: 'comments';
          rows: RecordCommentRow[];
          hasMore: boolean;
          canPost: boolean; // ユーザーがいる(ゲストは書けない)
          canDeleteOthers: boolean; // コメントの管理の権限(ほかの人の分も消せる)
      }
    | { kind: 'revisions'; rows: RecordRevisionRow[]; hasMore: boolean }
    | {
          kind: 'section';
          code: string;
          content:
              | RecordShowTableSection
              | RecordShowListSection
              | RecordShowLinesSection
              | RecordShowFailedSection
              | null;
      };

/** Records/Show のサイドパネルの props */
export type RecordShowPanelProps = {
    panel: { items: RecordPanelItem[] } | null; // 帯の項目だけ(開いた欄はクエリ panel が正本)
    panelContent: RecordPanelContent | null;
    sections: RecordShowSection[];
};

/**
 * 後ろのモジュールが一覧と詳細に足すもの(docs の 設計/業務データの入れ物/画面/業務の画面.md の 2.5。サーバーの
 * App\Queries\Records\Api\RecordScreenAddons の @phpstan-type を同じ名前で写す)。
 * 操作 = ダイアログを開くだけ(dialog = 画面の部品の名前、props = 部品に渡す値、changesValues = 値を変える操作か)。
 */
export type RecordAddonAction = {
    code: string;
    label: string;
    dialog: string;
    props: Record<string, string | number | boolean | null>;
    disabledReason: string | null; // 押せない理由(Tooltip に出す)
    changesValues: boolean;
};

export type RecordAddonBadge = { label: string; tone: StatusTone | null };

/** 一覧の見出しの操作 */
export type RecordIndexAddons = { actions: RecordAddonAction[] };

/** 詳細の操作・見出しの右の札・項目の欄の上の注(項目のコード → 1 行) */
export type RecordShowAddons = {
    actions: RecordAddonAction[];
    badges: RecordAddonBadge[];
    fieldNotes: Record<string, string>;
};
