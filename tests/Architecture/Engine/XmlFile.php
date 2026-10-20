<?php

namespace Tests\Architecture\Engine;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * XML ファイルを読む(汎用)。読めない・壊れているときは例外(黙って空にしない)。
 */
final class XmlFile
{
    /**
     * @throws InvalidArgumentException ファイルが無いとき
     * @throws UnexpectedValueException 読めない・XML として壊れているとき
     */
    public static function xpath(string $path): DOMXPath
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("XML ファイルが無い: {$path}");
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new UnexpectedValueException("XML ファイルを読めない: {$path}");
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($content, LIBXML_NONET);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $errors !== []) {
            $messages = implode(' / ', array_map(fn ($error): string => trim($error->message), $errors));

            throw new UnexpectedValueException("XML として読めない: {$path}({$messages})");
        }

        return new DOMXPath($document);
    }

    /**
     * XPath に一致した属性・要素の値(文字列)の一覧を文書順で返す。
     *
     * @return list<string>
     *
     * @throws UnexpectedValueException XPath を評価できないとき
     */
    public static function values(DOMXPath $xpath, string $expression): array
    {
        $nodes = $xpath->query($expression);

        if ($nodes === false) {
            throw new UnexpectedValueException("XPath を評価できない: {$expression}");
        }

        $values = [];

        foreach ($nodes as $node) {
            $values[] = trim($node->nodeValue ?? '');
        }

        return $values;
    }
}
