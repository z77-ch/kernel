<?php

namespace Z77\Shared\Import;

/**
 * The target data changed between plan time and apply time (IMP-R011) — the
 * decisions were made against a world that moved. The caller recomputes the
 * plan instead of writing.
 */
class ImportStaleException extends \RuntimeException
{
}
