<?php

namespace Z77\Shared\Attributes;

/**
 * Opens ONE action as a cross-origin POST door: a form on a foreign site posts to it without a
 * session and without a CSRF header (security.md «cross-origin POST door», CROSS-ORIGIN-POST-001).
 *
 * The attribute alone opens nothing. The module config must name the same action under
 * `crossOriginPosts` (`'group/controller/action' => <CrossOriginPolicyInterface FQCN>`); either
 * key without the other is a config error and throws (Bootstrap). The door runs on the stateless
 * branch — no session, no cookie, no page cache — behind {@see \Z77\Core\Services\CrossOriginPostGuard},
 * and the action must answer with `$this->json()`.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CrossOriginPost
{
}
