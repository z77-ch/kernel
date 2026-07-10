<?php

namespace Z77\Core\Http\Response;

/**
 * VoidResponse
 *
 * No output, no headers. Execution ends cleanly after dispatch.
 * Use for background job triggers or fire-and-forget actions where
 * the browser expects no visible response.
 *
 * Usage in action:
 *   return $this->void();
 */
class VoidResponse implements ResponseInterface
{
    public function send(): void
    {
        // Intentionally empty — no output to client.
    }
}
