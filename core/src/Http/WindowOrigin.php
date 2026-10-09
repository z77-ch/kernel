<?php

namespace Z77\Core\Http;

/**
 * Where a window request comes from (ADR-047 part 2): `page`, `region:<name>` (a fetch
 * region, e.g. `region:journal-list`) or `window:<id>` (another window). core.js sends it as
 * `_origin` with the GET that opens a window; the controller writes it into the window's
 * form (a hidden `_origin`) and, after the save, answers for THAT origin — one action serves
 * every place it is opened from.
 *
 * Read from the POST first (the form carries it back), then from the GET. Anything that is
 * not one of the three shapes is `page` — the value only ever selects among the controller's
 * own branches and is echoed into commands as a scope, never into SQL or markup unescaped.
 */
final class WindowOrigin
{
    public const PAGE = 'page';

    private const SHAPE = '/^(page|window:\d{1,9}|region:[a-z0-9][a-z0-9-]{0,63})$/';

    /** @param object $request the Request (or a double with getGetParameter / getPostParameters) */
    public static function of(object $request): string
    {
        $posted = method_exists($request, 'getPostParameters') ? ($request->getPostParameters()['_origin'] ?? null) : null;
        $value  = is_string($posted) && $posted !== '' ? $posted : $request->getGetParameter('_origin');

        return is_string($value) && preg_match(self::SHAPE, $value) === 1 ? $value : self::PAGE;
    }
}
