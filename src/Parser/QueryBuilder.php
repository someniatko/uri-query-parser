<?php

/**
 * League.Uri (http://uri.thephpleague.com).
 *
 * @author  Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @license https://github.com/thephpleague/uri-query-parser/blob/master/LICENSE (MIT License)
 * @version 1.0.0
 * @link    https://uri.thephpleague.com/query-parser
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace League\Uri\Parser;

use League\Uri\Exception\InvalidQueryPair;
use League\Uri\Exception\UnknownEncoding;
use function array_keys;
use function html_entity_decode;
use function implode;
use function is_bool;
use function is_numeric;
use function is_scalar;
use function is_string;
use function preg_match;
use function preg_quote;
use function preg_replace_callback;
use function rawurldecode;
use function rawurlencode;
use function sprintf;
use function str_replace;
use const PHP_QUERY_RFC1738;
use const PHP_QUERY_RFC3986;

/**
 * A class to build a URI query string from a collection of key/value pairs.
 *
 * @package  League\Uri
 * @author   Ignace Nyamagana Butera <nyamsprod@gmail.com>
 * @since    1.0.0
 * @see      https://tools.ietf.org/html/rfc3986#section-3.4
 * @internal Use the function League\Uri\query_build instead
 */
final class QueryBuilder
{
    private const ENCODING_LIST = [
        PHP_QUERY_RFC1738 => [
            'suffixKey' => '*',
            'suffixValue' => '*=&',
        ],
        PHP_QUERY_RFC3986 => [
            'suffixKey' => "!$'()*+,;:@?/%",
            'suffixValue' => "!$'()*+,;=:@?/&%",
        ],
    ];

    private const REGEXP_UNRESERVED_CHAR = '/[^A-Za-z0-9_\-\.~]/';

    /**
     * @var string
     */
    private static $regexpKey;

    /**
     * @var string
     */
    private static $regexpValue;

    /**
     * Build a query string from an associative array.
     *
     * The method expects the return value from Query::parse to build
     * a valid query string. This method differs from PHP http_build_query as
     * it does not modify parameters keys.
     *
     * @throws UnknownEncoding  If the encoding type is invalid
     * @throws InvalidQueryPair If a pair is invalid
     */
    public static function build(iterable $pairs, string $separator = '&', int $enc_type = PHP_QUERY_RFC3986): ?string
    {
        if (null === (self::ENCODING_LIST[$enc_type] ?? null)) {
            throw new UnknownEncoding(sprintf('Unknown Encoding: %s', $enc_type));
        }

        self::$regexpValue = '/(%[A-Fa-f0-9]{2})|[^A-Za-z0-9_\-\.~'.preg_quote(
            str_replace(
                html_entity_decode($separator, ENT_HTML5, 'UTF-8'),
                '',
                self::ENCODING_LIST[$enc_type]['suffixValue']
            ),
            '/'
        ).']+/ux';

        self::$regexpKey = '/(%[A-Fa-f0-9]{2})|[^A-Za-z0-9_\-\.~'.preg_quote(
            str_replace(
                html_entity_decode($separator, ENT_HTML5, 'UTF-8'),
                '',
                self::ENCODING_LIST[$enc_type]['suffixKey']
            ),
            '/'
        ).']+/ux';

        $res = [];
        foreach ($pairs as $pair) {
            $res[] = self::buildPair($pair);
        }

        if ([] === $res) {
            return null;
        }

        $query = implode($separator, $res);
        if (PHP_QUERY_RFC1738 === $enc_type) {
            return str_replace(['+', '%20'], ['%2B', '+'], $query);
        }

        return $query;
    }

    /**
     * Build a RFC3986 query key/value pair association.
     *
     * @throws InvalidQueryPair If the pair is invalid
     */
    private static function buildPair(array $pair): string
    {
        if ([0, 1] !== array_keys($pair)) {
            throw new InvalidQueryPair('A pair must be a sequential array starting at `0` and containing two elements.');
        }

        [$name, $value] = $pair;
        if (!is_scalar($name)) {
            throw new InvalidQueryPair(sprintf('A pair key must be a scalar value `%s` given.', gettype($name)));
        }

        if (is_bool($name)) {
            $name = (int) $name;
        }

        if (is_string($name) && (bool) preg_match(self::$regexpKey, $name)) {
            $name = preg_replace_callback(self::$regexpKey, [QueryBuilder::class, 'encodeMatches'], $name);
        }

        if (is_string($value)) {
            if (! (bool) preg_match(self::$regexpValue, $value)) {
                return $name.'='.$value;
            }

            return $name.'='.preg_replace_callback(self::$regexpValue, [QueryBuilder::class, 'encodeMatches'], $value);
        }

        if (is_numeric($value)) {
            return $name.'='.$value;
        }

        if (is_bool($value)) {
            return $name.'='.(int) $value;
        }

        if (null === $value) {
            return (string) $name;
        }

        throw new InvalidQueryPair(sprintf('A pair value must be a scalar value or the null value, `%s` given.', gettype($value)));
    }

    /**
     * Encodes matched sequences.
     */
    private static function encodeMatches(array $matches): string
    {
        if ((bool) preg_match(self::REGEXP_UNRESERVED_CHAR, rawurldecode($matches[0]))) {
            return rawurlencode($matches[0]);
        }

        return $matches[0];
    }
}
