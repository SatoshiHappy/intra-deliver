<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\ApplicantApprovalGuard;
use App\Queries\Workflow\CaseEntryGate;
use App\Queries\Workflow\CaseOperationTable;
use App\Queries\Workflow\EffectiveFlowVersions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * 案件の操作の失敗の文言と例外の 1 か所(docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 6 節の表)。404 は既定の
 * ModelNotFoundException(文言なし)、業務データの入れ物の失敗は入れ物のまま伝わるので、ここには持たない。
 */
final class CaseOperationErrors
{
    /** 申請できる人でない(申請・一時保存の新規と上書き・一時保存からの申請。403)。正は書き込みの入口の判定の文言(画面の 403 と同じ文言) */
    public const NOT_APPLICABLE = CaseEntryGate::NOT_APPLICABLE_MESSAGE;

    /** 改訂番号が違う(422 case) */
    public const STALE_REVISION = '画面を表示した後に申請が変更されました。再表示してください';

    /** 状態 × 役割の表で許されない(422 case)。正は表の文言(処理対象者の候補の口と同じ文言) */
    public const NOT_PERMITTED = CaseOperationTable::NOT_PERMITTED_MESSAGE;

    /** 展開が 0 人・戻す相手が有効でない(422 case。%s = ノード名) */
    public const NO_TARGETS = '%sの処理対象者がいないため、この操作はできません';

    /** 申請者承認防止に当たる(422 case。同 13 節)。正は判定の読み取りの文言(詳細の blockedReason と同じ文言) */
    public const APPLICANT_APPROVAL = ApplicantApprovalGuard::MESSAGE;

    /** 選んだ処理担当者の代理先でない・代理の別やフローが合わない・期間の外(422 principal_user_id。設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const PRINCIPAL = '選択した処理担当者として処理できません。代理の設定を確認してください。';

    /** 選んだ申請者の申請の代理先でない(422 applicant_user_id。同じ 13 節) */
    public const APPLICANT_PRINCIPAL = '選択した申請者として申請できません。代理の設定を確認してください。';

    /** 申請基準日が無い・空白だけ(422 base_date。同 13 節) */
    public const BASE_DATE_REQUIRED = '申請基準日を入力してください';

    /** 申請基準日が日付でない(422 base_date) */
    public const BASE_DATE_INVALID = '申請基準日は日付で入力してください';

    /** 申請基準日が今日の前後 1 年の外(422 base_date) */
    public const BASE_DATE_OUT_OF_RANGE = '申請基準日は今日の前後 1 年以内の日付を入力してください';

    /** 申請基準日に有効な版が無い(422 base_date。%s = 日付)。正は読み取りの文(申請の画面の知らせと同じ文) */
    public const BASE_DATE_NO_VERSION = EffectiveFlowVersions::NO_VERSION_MESSAGE;

    /** 申請基準日の版で申請できる人でない(422 base_date。%s = 日付) */
    public const BASE_DATE_NOT_APPLICABLE = '申請基準日 %s には、このフローを申請できません';

    /** 指定(422 assignments・assignments.<ノードの id>。同 13 節) */
    public const ASSIGNMENTS_MISMATCH = '指定するステップの処理対象者を指定してください';

    /** %1$s = ノードの表示名、%2$d = 最小、%3$d = 最大 */
    public const ASSIGNMENTS_COUNT = '%1$sの処理対象者は %2$d 人から %3$d 人まで指定してください';

    /** %1$s = ノードの表示名、%2$d = 人数(最小 = 最大) */
    public const ASSIGNMENTS_EXACT_COUNT = '%1$sの処理対象者は %2$d 人を指定してください';

    public const ASSIGNMENTS_DUPLICATE = '同じユーザーを重複して指定できません';

    public const ASSIGNMENTS_NOT_EFFECTIVE = '申請基準日に有効でないユーザーは指定できません';

    public const ASSIGNMENTS_APPLICANT = '申請者本人は、このステップの処理対象者に指定できません';

    /** 申請者承認防止のステップの指定に、今日の申請者の代理先(設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const ASSIGNMENTS_APPLICANT_PROXY = '申請者の代理先は、このステップの処理担当者に指定できません';

    /** 先読みで辿り着く分岐開始でどの条件にも該当しない(422 case。%s = 分岐開始の名前。同 13 節) */
    public const NO_BRANCH_ROUTE = '分岐「%s」の条件に該当するルートがないため、申請できません';

    /** 差戻し先が候補に無い(422 send_back_node_id。ルートの深さ/案件の操作/同時実行と検査.md 13 節) */
    public const SEND_BACK_NODE = '差戻し先を、差戻しできるステップから選択してください';

    /** 差戻しできる先が 1 つも無いステップで差戻した(422 send_back_node_id。同 13 節) */
    public const SEND_BACK_NOT_ALLOWED = 'このステップでは差戻しできません';

    /** ステップの移動の種類が無い・外の値(422 direction。設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const MOVE_DIRECTION = '移動の種類を選択してください';

    /** ステップの移動の戻り先が候補に無い・候補が空(422 send_back_node_id。設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const MOVE_BACK_NODE = '戻り先を、戻せるステップから選択してください';

    /** 指定するステップを進める(422 case。設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const MOVE_DESIGNATOR = '次のステップの指定が必要なため、このステップは進められません。承認の画面から処理してください。';

    /** 案件名(422 name) */
    public const NAME_TOO_LONG = '申請名は '.WorkflowCase::NAME_MAX.' 文字以内で入力してください';

    public const NAME_NEWLINE = '申請名に改行を入力できません';

    public const NAME_INVALID_CHARACTER = '申請名に使用できない文字が含まれています';

    /** コメント(422 comment) */
    public const COMMENT_TOO_LONG = 'コメントは '.WorkflowCaseHistory::COMMENT_MAX.' 文字以内で入力してください';

    public const COMMENT_INVALID_CHARACTER = 'コメントに使用できない文字が含まれています';

    /** 一時保存の入力(422 draft) */
    public const DRAFT_TOO_LARGE = '一時保存の内容が大きすぎます';

    public const DRAFT_INVALID_CHARACTER = '一時保存の内容に使用できない文字が含まれています';

    /** 確認できる確認が無い(422 case。設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節) */
    public const NO_CONFIRMATION = 'この申請には、確認できるステップがありません。';

    /** 連絡(422 contact.user_ids・contact.message。同じ 13 節) */
    public const CONTACT_REQUIRED = '連絡する人を選択してください';

    public const CONTACT_TOO_MANY = '連絡する人は '.ContactValidator::MAX_USERS.' 人まで選択できます';

    public const CONTACT_NOT_EFFECTIVE = '有効でないユーザーには連絡できません';

    public const CONTACT_SELF = '自分自身には連絡できません';

    public const CONTACT_DUPLICATE = '同じユーザーを重複して選択できません';

    public const CONTACT_MESSAGE_TOO_LONG = 'ひと言は '.ContactValidator::MESSAGE_MAX.' 文字以内で入力してください';

    public const CONTACT_MESSAGE_INVALID_CHARACTER = 'ひと言に使用できない文字が含まれています';

    /** 一括の選択(422 cases・case_ids。同じ 13 節) */
    public const BULK_REQUIRED = '申請を 1 件以上選択してください';

    public const BULK_TOO_MANY = '一度に選択できる申請は '.BulkInputValidator::MAX_CASES.' 件までです';

    /** 案件そのものの誤りのキー(改訂番号・表・展開) */
    public const CASE_KEY = 'case';

    /** 処理担当者(代理元)の入力のキー */
    public const PRINCIPAL_KEY = 'principal_user_id';

    /** 申請者(申請の代理の代理元)の入力のキー */
    public const APPLICANT_KEY = 'applicant_user_id';

    /**
     * 申請できる人でないときの 403。
     */
    public static function notApplicable(): AuthorizationException
    {
        return new AuthorizationException(self::NOT_APPLICABLE);
    }

    /**
     * 案件そのものの 422(キー case)。
     */
    public static function ofCase(string $message): ValidationException
    {
        return ValidationException::withMessages([self::CASE_KEY => [$message]]);
    }

    /**
     * 展開・戻す相手が 0 人の 422(キー case)。
     */
    public static function noTargets(string $nodeName): ValidationException
    {
        return self::ofCase(sprintf(self::NO_TARGETS, $nodeName));
    }

    /**
     * 処理担当者として処理できない 422(キー principal_user_id)。
     */
    public static function principal(): ValidationException
    {
        return ValidationException::withMessages([self::PRINCIPAL_KEY => [self::PRINCIPAL]]);
    }

    /**
     * 申請者として申請できない 422(キー applicant_user_id)。
     */
    public static function applicantPrincipal(): ValidationException
    {
        return ValidationException::withMessages([self::APPLICANT_KEY => [self::APPLICANT_PRINCIPAL]]);
    }

    /**
     * 申請者承認防止のノードで展開の結果が申請者として扱う人だけの 422(キー case。代理先を含めば代理先を含む文)。
     *
     * @param  list<int>  $targetUserIds  展開の結果
     */
    public static function applicantOnlyTargets(
        string $nodeName,
        array $targetUserIds,
        int $applicantUserId,
    ): ValidationException {
        $message = ApplicantApprovalGuard::onlyApplicantTargetsMessage($nodeName, $targetUserIds, $applicantUserId);

        return self::ofCase($message);
    }

    /**
     * 先読みで辿り着く分岐開始で真の辺が無い 422(キー case)。
     */
    public static function noBranchRoute(string $branchName): ValidationException
    {
        return self::ofCase(sprintf(self::NO_BRANCH_ROUTE, $branchName));
    }
}
