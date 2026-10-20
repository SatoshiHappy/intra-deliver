import { plainTextExcerpt } from '@/lib/richText';
import type {
    RecordChoiceValue,
    RecordFieldDefinition,
    RecordFieldValue,
    RecordModuleDefinition,
    RecordReferenceValue,
} from '@/types';

/**
 * 業務データの値の表示の組み立て(docs の 設計/業務データの入れ物/画面/README.md 1.3・1.4)。一覧・詳細・明細・差分で同じ関数を使い、
 * 場所ごとに組み立てを分けない(場所の違いは引数 place だけで受ける)。
 */

/** 値を出す場所(呼ぶ所が渡す。場所ごとの見え方 = 画面/README.md 1.4 の表) */
export type RecordValuePlace = 'detail' | 'cell' | 'diff';

/** 表のセルで、複数選べる参照の先頭から出す相手の数(残りは数で添える = 1.3) */
const CELL_SHOWN_TARGETS = 2;

/** 表示のタイムゾーン(利用者のブラウザのタイムゾーンに依らない) */
const TIMEZONE = 'Asia/Tokyo';

/** 未設定の表示 */
export const EMPTY_VALUE = '—';

const tokyoParts = new Intl.DateTimeFormat('en-CA', {
    timeZone: TIMEZONE,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hourCycle: 'h23',
});

/**
 * 時差つきの日時の文字列を、Asia/Tokyo の YYYY-MM-DDTHH:MM(datetime-local の値 = 入力の形)にする。読めなければ null。
 */
export function toTokyoLocal(value: string): string | null {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    const parts = Object.fromEntries(
        tokyoParts.formatToParts(date).map((part) => [part.type, part.value]),
    );

    return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}

/**
 * 日時の文字列を Asia/Tokyo の YYYY/MM/DD HH:MM で出す(入力の形 toTokyoLocal から導く。読めなければそのまま)。作成・更新の日時にも使う。
 */
export function formatDatetime(value: string): string {
    return toTokyoLocal(value)?.replace('T', ' ').replaceAll('-', '/') ?? value;
}

/**
 * 日付の文字列(YYYY-MM-DD)を YYYY/MM/DD で出す(その形でなければそのまま。暦の日付なのでタイムゾーンで変えない)。
 */
export function formatDate(value: string): string {
    return value.replace(/^(\d{4})-(\d{2})-(\d{2})$/, '$1/$2/$3');
}

/**
 * 整数部に 3 桁ごとの区切りを付ける(小数部はそのまま)。
 */
function groupDigits(value: string): string {
    const [integer, fraction] = value.split('.');
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return fraction === undefined ? grouped : `${grouped}.${fraction}`;
}

function isChoice(value: RecordFieldValue): value is RecordChoiceValue {
    return typeof value === 'object' && value !== null && 'name' in value;
}

function isReference(value: RecordFieldValue): value is RecordReferenceValue {
    return typeof value === 'object' && value !== null && 'label' in value;
}

/**
 * 参照の値の表示(label、無ければユーザー・組織はコード、レコードは #<id>)。
 */
export function referenceLabel(
    type: RecordFieldDefinition['type'],
    value: RecordReferenceValue,
): string {
    return (
        value.label ??
        (type === 'record' ? `#${value.id}` : (value.code ?? `#${value.id}`))
    );
}

/**
 * 複数選べる参照の値の表示(表のセルで 3 つ以上なら、先頭の 2 つの後に空白をはさんで「ほか <N> 名」。組織は「ほか <N> 件」)。
 */
function referencesText(
    field: RecordFieldDefinition,
    values: RecordReferenceValue[],
    place: RecordValuePlace,
): string {
    const labels = values.map((value) => referenceLabel(field.type, value));

    if (place !== 'cell' || labels.length <= CELL_SHOWN_TARGETS) {
        return labels.join('、');
    }

    const rest = labels.length - CELL_SHOWN_TARGETS;
    const unit = field.type === 'user' ? '名' : '件';

    return `${labels.slice(0, CELL_SHOWN_TARGETS).join('、')} ほか ${rest} ${unit}`;
}

/**
 * 選択肢に無い今の値の表示(無ければ null)。業務処理を通さずに書いた値を空に見せない(画面/README.md 1.2)。
 */
export function missingChoiceLabel(
    field: RecordFieldDefinition,
    value: string,
): string | null {
    return value === '' || field.options.some((option) => option.code === value)
        ? null
        : `${value}(選択肢にありません)`;
}

/**
 * 値を表示の文字列にする(place = 出す場所。既定は詳細)。
 */
export function formatRecordValue(
    field: RecordFieldDefinition,
    value: RecordFieldValue,
    place: RecordValuePlace = 'detail',
): string {
    if (value === null || value === '') {
        return EMPTY_VALUE;
    }

    if (Array.isArray(value)) {
        return value.length === 0
            ? EMPTY_VALUE
            : referencesText(field, value, place);
    }

    if (isReference(value)) {
        return referenceLabel(field.type, value);
    }

    if (isChoice(value)) {
        return value.name ?? value.code;
    }

    switch (field.type) {
        case 'boolean':
            return value === true ? 'はい' : 'いいえ';
        case 'integer':
        case 'decimal':
            return groupDigits(String(value));
        case 'date':
            return formatDate(String(value));
        case 'datetime':
            return formatDatetime(String(value));
        case 'string':
            return field.isMultiline && place === 'cell'
                ? plainTextExcerpt(String(value))
                : String(value);
        default:
            return String(value);
    }
}

/**
 * 数と日時の字(font-num tabular-nums)で出す値か(整数・小数・日付・日時と、#<id> で出す参照)。
 */
export function isNumericValue(
    field: RecordFieldDefinition,
    value: RecordFieldValue,
): boolean {
    if (['integer', 'decimal', 'date', 'datetime'].includes(field.type)) {
        return true;
    }

    return (
        field.type === 'record' && isReference(value) && value.label === null
    );
}

/**
 * レコードの見出し = 表示の項目の値(無ければ #<id>。詳細・編集の画面とパンくず = 画面/業務の画面.md 2.2・2.3)。
 */
export function recordHeading(
    module: RecordModuleDefinition,
    record: { id: number; values: Record<string, RecordFieldValue> },
): string {
    const title = module.fields.find((field) => field.isTitle);
    const value =
        title === undefined ? null : (record.values[title.code] ?? null);

    return title === undefined || value === null
        ? `#${record.id}`
        : formatRecordValue(title, value);
}
