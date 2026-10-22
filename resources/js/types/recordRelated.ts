/**
 * 関連レコードの型(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 7 節、詳細の画面/README.md の 6.1 節、
 * 業務モジュールの管理.md の 6 節。サーバーの App\Queries\Records\Api\RecordRelatedQuery と入口が返す形)。
 * records.ts・recordShow.ts が大きさの上限に近いので分けた。
 */
import type {
    RecordFieldDefinition,
    RecordFieldValue,
    RecordHeadingGroup,
    RecordModuleDefinition,
    RecordModuleSummary,
    RecordReferenceValue,
} from './records';

/** 入口 1 つ(参照元の業務モジュール × 参照の項目。並び = 入口の並び) */
export type RecordRelatedEntry = {
    key: string; // <業務モジュール>.<項目>(明細の項目は <業務モジュール>.<明細の項目>.<項目>。クエリ related の値)
    name: string;
    count: number; // 見える参照元の件数
    canCreate: boolean; // 「新規」を出せるか(関連レコード.md の 4.1 節)
};

/** Records/Show の relatedRecords(見える入口が無ければ null) */
export type RecordRelatedRecords = {
    entries: RecordRelatedEntry[]; // 選んだ入口は URL のクエリ related から決める
};

/** 入口の表の 1 ページ(列は 5 つまで。更新日の列は画面が updatedAt から足す) */
export type RecordRelatedRows = {
    entry: string;
    module: RecordModuleSummary; // 参照元の業務モジュール(行の詳細へのリンクの先)
    columns: RecordFieldDefinition[];
    rows: {
        id: number;
        values: Record<string, RecordFieldValue>;
        updatedAt: string | null;
    }[];
    currentPage: number;
    lastPage: number;
    total: number;
};

/** 「新規」のダイアログのフォーム(参照元の業務モジュールの登録のフォームと、入口の項目の固定の値) */
export type RecordRelatedForm = {
    entry: string;
    field: string; // 入口の項目のコード
    value: RecordReferenceValue; // このレコード(表示名つき)
    module: RecordModuleDefinition;
    headings: RecordHeadingGroup[];
};

/** Records/Show の関連レコードの props */
export type RecordRelatedProps = {
    relatedRecords: RecordRelatedRecords | null;
    relatedRecordRows: RecordRelatedRows | null; // クエリ tab が related のときだけ
    relatedRecordForm?: RecordRelatedForm | null; // 「新規」のダイアログを開いた時の部分の再読み込みでだけ
};

/** 項目のコードと名前 */
type RecordFieldSummary = { code: string; name: string };

/** 管理の業務モジュールの定義の「関連レコードの列」の行(業務モジュールの管理.md の 6 節) */
export type RecordRelatedColumnSetting = {
    entry: string; // 入口の項目のコード(明細の項目は <明細の項目>.<項目>)
    label: string;
    referencedModule: RecordModuleSummary;
    columns: RecordFieldSummary[]; // 選んだ列(空 = 未選択)
    candidates: RecordFieldSummary[]; // 選べる項目(行の業務モジュールの明細以外の項目)
};
