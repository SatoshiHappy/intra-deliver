<?php

namespace Tests\Architecture\Engine\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * 正規表現のパターンが、修飾子 `D`・`m` の無い `$` を使うことを止める(汎用。判定は {@see RegexEndAnchor})。
 *
 * 見るのはパターンの引数だけ: `preg_*` の第 1 引数(`preg_quote` などパターンを受けない関数を除く)・`preg_replace_callback_array` の
 * 配列のキー・`assertMatchesRegularExpression`・`assertDoesNotMatchRegularExpression` の第 1 引数。型が定数の文字列
 * (定数の配列の値を含む)に決まるものだけを見て、決まらないもの(`preg_quote` とつないだ文字列など)は止まらない。
 * 抑制の注釈では消せない(nonIgnorable)。
 *
 * @implements Rule<CallLike>
 */
final class RegexEndAnchorRule implements Rule
{
    private const IDENTIFIER = 'regexEndAnchor';

    private const PATTERN_FUNCTIONS = [
        'preg_match', 'preg_match_all', 'preg_replace', 'preg_replace_callback',
        'preg_filter', 'preg_split', 'preg_grep',
    ];

    private const KEYED_PATTERN_FUNCTION = 'preg_replace_callback_array';

    private const PATTERN_ASSERTIONS = ['assertmatchesregularexpression', 'assertdoesnotmatchregularexpression'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $argument = self::firstArgument($node);

        if ($argument === null) {
            return [];
        }

        $type = $scope->getType($argument->value);
        $patterns = self::isKeyedPatternCall($node) ? self::constantKeys($type) : self::constantStrings($type);
        $errors = [];

        foreach (array_unique($patterns) as $pattern) {
            if (RegexEndAnchor::acceptsTrailingNewline($pattern)) {
                $errors[] = self::error($pattern, $argument);
            }
        }

        return $errors;
    }

    /**
     * パターンを受ける呼び出しなら第 1 引数を返す(名前の決まらない呼び出し・引数の展開・名前つきの引数は見ない)。
     */
    private static function firstArgument(CallLike $node): ?Arg
    {
        if ($node->isFirstClassCallable() || ! self::takesPattern($node)) {
            return null;
        }

        $argument = $node->getArgs()[0] ?? null;

        return $argument === null || $argument->unpack || $argument->name !== null ? null : $argument;
    }

    private static function takesPattern(CallLike $node): bool
    {
        if ($node instanceof FuncCall) {
            $name = $node->name instanceof Name ? strtolower($node->name->toString()) : null;

            return in_array($name, [...self::PATTERN_FUNCTIONS, self::KEYED_PATTERN_FUNCTION], true);
        }

        if ($node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall) {
            return $node->name instanceof Identifier
                && in_array($node->name->toLowerString(), self::PATTERN_ASSERTIONS, true);
        }

        return false;
    }

    private static function isKeyedPatternCall(CallLike $node): bool
    {
        return $node instanceof FuncCall && $node->name instanceof Name
            && strtolower($node->name->toString()) === self::KEYED_PATTERN_FUNCTION;
    }

    /**
     * 型が決める定数の文字列(定数の配列なら値の定数の文字列)。
     *
     * @return list<string>
     */
    private static function constantStrings(Type $type): array
    {
        $strings = array_map(fn ($string): string => $string->getValue(), $type->getConstantStrings());

        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getValueTypes() as $valueType) {
                $strings = [...$strings, ...self::constantStrings($valueType)];
            }
        }

        return $strings;
    }

    /**
     * 定数の配列のキーの定数の文字列。
     *
     * @return list<string>
     */
    private static function constantKeys(Type $type): array
    {
        $keys = [];

        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getKeyTypes() as $keyType) {
                $keys = [...$keys, ...self::constantStrings($keyType)];
            }
        }

        return $keys;
    }

    private static function error(string $pattern, Arg $argument): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            '正規表現 %s の $ は末尾の改行の前にも一致する。修飾子 D を付けるか、$ の代わりに \z を使う。',
            $pattern,
        ))
            ->identifier(self::IDENTIFIER)
            ->line($argument->getStartLine())
            ->nonIgnorable()
            ->build();
    }
}
