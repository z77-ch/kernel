<?php

namespace Z77\Core\Http\Response;

/**
 * The ONE reading of an entity tag: how it goes onto the wire, and whether a
 * client's `If-None-Match` names it.
 *
 * Before this class the same question was answered in four places, each
 * differently incomplete: PageCachePolicy handled a comma list, `*` and the
 * weak prefix but only for numeric tags; ApiResponder handled the weak prefix
 * and quoting but neither a list nor `*`; FileResponse compared the raw
 * header against one quoted string; and the axo3 widget stripped quotes and
 * nothing else. A client that sends what RFC 7232 allows therefore got a 304
 * from one door and 400 KB from the next.
 *
 * A tag is OPAQUE. It may be a time (the page cache uses the entry's mtime,
 * and `Last-Modified` rides along for it) or a content hash — this class
 * never interprets it, it only compares. {@see isTime()} is the one place
 * that says which of the two a caller is holding, so a hash can never be
 * rendered as a date.
 *
 * Only strong tags are emitted. We never mark a tag weak, so we never have to
 * decide what a weak comparison would mean for our resources; an incoming
 * `W/` prefix is accepted because clients echo tags in either form.
 */
final class Etag
{
    /** The header value for a tag — always quoted, always strong. */
    public static function header(int|string $tag): string
    {
        return '"' . $tag . '"';
    }

    /**
     * Does the client's `If-None-Match` name this tag?
     *
     * RFC 7232 §3.2: the header is either `*` (any existing representation)
     * or a comma-separated list of tags, each optionally weak-prefixed and
     * always quoted. Comparison is on the quoted string, so `"7"` and `"007"`
     * are DIFFERENT tags even where both were built from the number seven —
     * a tag is opaque, not a number.
     */
    public static function matches(?string $ifNoneMatch, int|string $tag): bool
    {
        if ($ifNoneMatch === null) {
            return false;
        }

        $raw = trim($ifNoneMatch);
        if ($raw === '') {
            return false;
        }
        if ($raw === '*') {
            return true;
        }

        $tag = (string) $tag;
        foreach (explode(',', $raw) as $part) {
            $sent = trim($part);
            if (str_starts_with($sent, 'W/')) {
                $sent = substr($sent, 2);
            }
            if (trim($sent, '"') === $tag) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this tag a point in time (and may a `Last-Modified` be derived from
     * it), or an opaque hash?
     *
     * The distinction is the TYPE, deliberately: a caller that holds a time
     * passes an int, a caller that holds a hash passes a string. Guessing
     * from the value would make `"1788604483"` — a perfectly valid hash — into
     * a date.
     */
    public static function isTime(int|string $tag): bool
    {
        return is_int($tag);
    }
}
