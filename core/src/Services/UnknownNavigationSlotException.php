<?php

namespace Z77\Core\Services;

/**
 * Thrown by {@see NavigationService::getBySlot()} when a template requests a
 * navigation slot that is not registered in any view-area module config
 * (ModuleManager `navSlots`, ADR-022). Fail-fast: a slot typo or a removed slot
 * surfaces at the render site instead of silently yielding an empty navigation.
 */
class UnknownNavigationSlotException extends \RuntimeException
{
    public function __construct(string $slot)
    {
        parent::__construct("Unknown navigation slot '{$slot}' — not registered in any view-area module config (navSlots, ADR-022).");
    }
}
