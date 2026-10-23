/**
 * 業務データの入れ物の画面の型(docs の 設計/業務データの入れ物/画面/README.md 1.2。サーバーの App\Queries\Records が返す形)。
 */

/** 項目の型(項目と規則/README.md 1 節の 11 種) */
export type RecordFieldType =
    | 'string'
    | 'integer'
    | 'decimal'
    | 'date'
    | 'datetime'
    | 'boolean'
    | 'choice'
    | 'user'
    | 'department'
    | 'record'
    | 'details';

/** 条件の演算子(項目と規則/規則と業務処理/README.md 8.2) */
export type RecordConditionOperator =
    | 'eq'
    | 'ne'
    | 'lt'
    | 'le'
    | 'ge'
    | 'gt'
    | 'in'
    | 'empty'
    | 'not_empty';

/** 条件の式(8.2)。value は項目の値の JSON の形(in は配列、empty・not_empty は無し) */
export type RecordConditionExpression = {
    field: string;
    op: RecordConditionOperator;
    value?: string | number | boolean | string[];
};

/** 規則の種別(8.1) */
export type RecordRuleKind = 'required_when' | 'compare';

/** 相関チェックの比べ方(8.1) */
export type RecordComparisonOperator = 'lt' | 'le' | 'eq' | 'ne' | 'ge' | 'gt';

export type RecordRuleDefinition =
    | {
          id: number;
          kind: Extract<RecordRuleKind, 'required_when'>;
          target: string;
          condition: { all: RecordConditionExpression[] };
          message: string | null;
      }
    | {
          id: number;
          kind: Extract<RecordRuleKind, 'compare'>;
          target: string;
          operator: RecordComparisonOperator;
          compared: string;
          message: string | null;
      };

export type RecordFieldDefinition = {
    code: string;
    name: string;
    type: RecordFieldType;
    isRequired: boolean;
    isMultiline: boolean;
    isMultiple: boolean; // ユーザー・組織だけ。複数選べる参照(項目と規則/README.md 1 節)
    maxLength: number | null; // 文字列だけ。型の上限を含めた実際の上限(属性が NULL なら型の上限)
    defaultValue: string | number | boolean | null;
    options: { code: string; name: string }[]; // 選択だけ。並びの順
    referencedModule: { code: string; name: string } | null; // 他モジュール参照だけ
    childModule: RecordModuleDefinition | null; // 明細だけ
    isListed: boolean;
    isSearchable: boolean;
    isTitle: boolean;
    grantsRowAccess: boolean; // ユーザー・組織だけ。関係する人の旗(認可とメニュー/README.md 2.4)
};

/** 業務モジュールの見える範囲(全員・関係する人だけ = 認可とメニュー/README.md 2.4) */
export type RowAccessScope = 'all' | 'related';

export type RecordModuleDefinition = {
    code: string;
    name: string;
    description: string | null;
    fields: RecordFieldDefinition[]; // 項目の並び
    rules: RecordRuleDefinition[];
};

/**
 * 参照の値(名称・タイトルが無ければ label は null。画面はユーザー・組織はコード、レコードは #<id> を出す)。
 * affiliation = ユーザーの主所属の組織の名前(複数の参照の欄のチップに出す。組織・他モジュール参照と主所属の無いユーザーは null = 画面/README.md 1.2)
 */
export type RecordReferenceValue = {
    id: number;
    code: string | null;
    label: string | null;
    affiliation: string | null;
};

/** 選択の値(選択肢に無いコードは name が null) */
export type RecordChoiceValue = { code: string; name: string | null };

/** 1 項目の値。未設定は null。小数は文字列、日時は UTC の文字列、選択は { code, name }、配列 = 複数選べる参照(並びの順。空は null) */
export type RecordFieldValue =
    | string
    | number
    | boolean
    | RecordChoiceValue
    | RecordReferenceValue
    | RecordReferenceValue[]
    | null;

export type RecordDetailRow = {
    id: number;
    values: Record<string, RecordFieldValue>;
};

/** 一覧の検索条件(範囲の型は from・to) */
export type RecordFilterValue =
    | string
    | { from: string | null; to: string | null };

/** 一覧のページ Records/Index の records(画面/業務の画面.md 2.1) */
export type RecordList = {
    data: {
        id: number;
        values: Record<string, RecordFieldValue>; // 一覧に出す項目だけ
        updatedAt: string | null;
    }[];
    currentPage: number;
    lastPage: number;
    total: number;
};

/** 一覧のページの query(読み取りが解釈した検索条件と並び) */
export type RecordListQuery = {
    filters: Record<string, RecordFilterValue>;
    sort: string;
};

/** 詳細のページ Records/Show の record(画面/業務の画面.md 2.3) */
export type RecordDetail = {
    id: number;
    values: Record<string, RecordFieldValue>;
    details: Record<string, RecordDetailRow[]>;
    createdAt: string | null;
    updatedAt: string | null;
    createdBy: RecordReferenceValue | null; // null = ゲスト
    updatedBy: RecordReferenceValue | null;
};

/** 複製の登録の画面の初期値(Records/Create の copySource。明細の行は id を持たない = レコードの共通機能/複製.md の 1 節) */
export type RecordCopySource = {
    id: number;
    values: Record<string, RecordFieldValue>;
    details: Record<string, { values: Record<string, RecordFieldValue> }[]>;
};

/** レコードの持ち主(詳細・編集のページの hold。理由だけを出し、案件の番号や名前は出さない = 画面/業務の画面.md 2.2・2.3) */
export type RecordHold = { reason: string };

/**
 * 参照の選択欄で選んだ相手(id と表示名 = 画面/README.md 4 節)。affiliation = ユーザーの所属(複数の参照の欄のチップに出す)。
 * 出どころは、候補から選んだ相手なら候補の主所属の名前、今の値の相手なら RecordReferenceValue の affiliation(画面/業務の画面.md 2.2)。
 */
export type PickedReference = {
    id: number;
    label: string;
    affiliation?: string | null;
};

/** 編集のページ Records/Edit の record(画面/業務の画面.md 2.2) */
export type RecordEditable = {
    id: number;
    values: Record<string, RecordFieldValue>;
    details: Record<string, RecordDetailRow[]>;
    version: string; // 開いたときの版(更新で送り返す)
};

/** 他モジュール参照の候補の口 records.data.candidates の応答(画面/README.md 4 節) */
export type RecordCandidates = {
    records: { id: number; label: string | null }[];
};

/** 業務モジュールのコードと名前(管理の画面の参照先の候補・親) */
export type RecordModuleSummary = { code: string; name: string };

/** 業務モジュールの作成と編集のアイコンの選択肢(サーバーの列挙 MenuIcon::recordModuleChoices() の値と表示名。画面/管理の画面.md 3.2・3.5) */
export type RecordModuleIconChoice = { value: string; label: string };

/** 管理の業務モジュールの編集の業務モジュール(画面/管理の画面.md 3.5)。明細のモジュール(isDetails)は icon・isVisible・categoryIds が null */
export type RecordAdminModuleForm = {
    code: string;
    name: string;
    description: string | null;
    icon: string | null;
    /** サイドバーに出ているか(明細のモジュールは null = 認可とメニュー/メニューと初期データ.md 5.1) */
    isVisible: boolean | null;
    isDetails: boolean;
    /** 所属するモジュール分類の ID(明細のモジュールは null、非表示は空 = モジュール分類/画面と検査.md 4.2) */
    categoryIds: string[] | null;
    /** 添付ファイルの追加・削除を許可するか(明細のモジュールは null = レコードの共通機能/業務モジュールの管理.md 2 節) */
    isAttachmentEnabled: boolean | null;
    /** 見える範囲(明細のモジュールは null = レコードの共通機能/業務モジュールの管理.md 2 節) */
    rowAccessScope: RowAccessScope | null;
};

/**
 * 見出しのまとまり(詳細とフォーム。レコードの共通機能/詳細の画面/見出し.md の 1・2 節。サーバーの RecordHeadingGroups の @phpstan-type HeadingGroup)。
 * 頭のまとまり(最初の見出しより前の項目)は id・name とも null。fieldCodes = そのまとまりの項目のコード(明細の項目を除く。並びの順)。
 */
export type RecordHeadingGroup = {
    id: number | null;
    name: string | null;
    fieldCodes: string[];
};

/** 管理の項目の表の見出し(業務モジュールの管理.md の 6 節。fieldCount = 明細の項目を数えない) */
export type RecordAdminHeading = {
    id: number;
    name: string;
    fieldCount: number;
};

/** 管理の項目の表の 1 行 = 見出しと項目の 1 本の並びの要素(並び替えの items と同じ形) */
export type RecordAdminItem =
    | { kind: 'heading'; id: number }
    | { kind: 'field'; code: string };

/** モジュール分類の ID と名前(業務モジュールの編集の props categories。並びの順 = 画面/管理の画面.md 3.5) */
export type ModuleCategoryChoice = { id: string; name: string };

/** モジュール分類の画面の分類の行(並びの順。modules は分類の中の並び = モジュール分類/画面と検査.md 4.4) */
export type ModuleCategoryRow = {
    id: string;
    name: string;
    modules: RecordModuleSummary[];
};

/** モジュール分類の画面の「分類なし」の行(明細でない業務モジュール。表示中を「業務」の直下の並びで、続けて非表示 = 4.4) */
export type UncategorizedModule = {
    code: string;
    name: string;
    isVisible: boolean;
};

/** 管理の業務モジュールの一覧の行(画面/管理の画面.md 3.1) */
export type RecordAdminModuleRow = {
    code: string;
    name: string;
    parentCode: string | null;
    /** サイドバーに出ているか(明細のモジュールは null) */
    isVisible: boolean | null;
};

/**
 * 管理の画面の項目(画面/管理の画面.md 3.3・3.4)。relativeDefault = 日付・日時の相対の既定値の長さ(ISO 8601 の形。
 * 無ければ null)。相対の既定値の項目は defaultValue が null(長さは値にせずこの欄で受ける)
 */
export type RecordAdminFieldDefinition = RecordFieldDefinition & {
    relativeDefault: string | null;
};

/** 管理の業務モジュールの定義(画面/管理の画面.md 3.3。明細のモジュールなら親を持つ) */
export type RecordAdminModule = Omit<RecordModuleDefinition, 'fields'> & {
    fields: RecordAdminFieldDefinition[]; // 項目の並び
    parent: RecordModuleSummary | null;
    /** サイドバーに出ているか(明細のモジュールは null) */
    isVisible: boolean | null;
    /** 見える範囲(明細のモジュールは null) */
    rowAccessScope: RowAccessScope | null;
};

/** 管理の項目の編集の項目(画面/管理の画面.md 3.4)。maxLengthAttribute = 保存された最大の文字数の属性(null = 型の上限)。並び順は項目の表で変えるので持たない */
export type RecordAdminField = RecordAdminFieldDefinition & {
    maxLengthAttribute: number | null;
};

/** 定義のコード・名前・説明の最大の文字数(管理の画面の props。画面/管理の画面.md 3 節の冒頭。名前の上限は規則の文言・選択肢の名称・モジュール分類の名前にも使う) */
export type DefinitionTextMaxLengths = {
    code: number;
    name: number;
    description: number;
};

/** 文字列の項目の型の上限(最大の文字数の属性が空のときの上限)と、複数行に指定できる上限(画面/管理の画面.md 3.4) */
export type StringMaxLengths = {
    singleLine: number;
    multiline: number;
    multilineMax: number;
};
