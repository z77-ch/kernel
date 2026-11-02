<?php

namespace Z77\Shared\Attributes;

/**
 * An int property that only ever moves by a difference: on a save the file store adds this
 * request's change to what is on disk NOW, under the file's lock, and puts the result back into
 * the entity. Two parallel raises raise twice; a raise computed from a stale read still raises
 * (persistence-file.md DATA-MERGE-001).
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Counter
{
}
