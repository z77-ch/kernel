<?php

namespace Z77\Shared\Mail;

use Z77\Core\DI;
use Z77\Shared\Entities\BackendUser;

/**
 * Whose name a mail goes out in (owner decision 2026-10-23) — the ONE place a send action asks.
 * A mail a staff member triggers (today «Alle per E-Mail senden …» of the booking print; later
 * offer, order confirmation, newsletter) is signed with one of HIS sender identities: the
 * identities of the logged-in backend user, the first his default.
 *
 * The send page offers {@see all()} (preselecting {@see DEFAULT_INDEX}), the POST carries the
 * chosen INDEX, and the server asks {@see choose()} again — an index that is not one of THIS
 * user's identities, or a user without any, is refused (Rule 7: the client only points, the
 * server decides). Without an identity there is no sending: the page shows the hint and no
 * button, the POST refuses.
 *
 * The shared partial `partials/senderIdentityField` (namespace `Z77\Shared`) renders the choice
 * from {@see viewModel()}; the outbox stores the chosen identity on the row (module-mailing,
 * `OutboxDraft::withSender()`).
 */
final class SenderIdentities
{
    /** The first identity is the user's default. */
    public const DEFAULT_INDEX = 0;

    /** The form field a send page posts the chosen index in. */
    public const FIELD = 'sender';

    /** The hint for a user without an identity — the page and the refused POST say the same. */
    public const MISSING = 'Erfasse zuerst eine E-Mail an deinem User.';

    /**
     * Where a user maintains his identities: the user administration of module-backend
     * (System › Benutzer, the row's «Bearbeiten»). Offered as a link only when the user can
     * reach it ({@see editUrl()}).
     */
    public const USER_ADMIN_URL = '/backend/system/backend-user/list';

    public function __construct(private ?BackendUser $user)
    {
    }

    /** The identities of the logged-in backend user (none in the CLI or for a member). */
    public static function ofCurrentUser(): self
    {
        return new self(DI::getCurrentUserService()->getBackendUser());
    }

    public function user(): ?BackendUser
    {
        return $this->user;
    }

    /** The user id stored on what was sent («Ausgelöst von»); null without a backend user. */
    public function userId(): ?int
    {
        return $this->user?->getId();
    }

    /** @return list<SenderIdentity> in the user's order, the default first */
    public function all(): array
    {
        return $this->user?->getSenderIdentities() ?? [];
    }

    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    public function default(): ?SenderIdentity
    {
        return $this->all()[self::DEFAULT_INDEX] ?? null;
    }

    /**
     * The identity the POST names — re-checked here: an index of THIS user's list only, and only
     * an identity that is valid now (a stored row edited by hand is not trusted). An absent
     * index means the default (a page that showed the one identity read-only). Null = refuse.
     */
    public function choose(mixed $index): ?SenderIdentity
    {
        if ($index === null || $index === '') {
            $index = self::DEFAULT_INDEX;
        } elseif (is_string($index) && ctype_digit($index)) {
            $index = (int) $index;
        }
        if (!is_int($index)) {
            return null;
        }
        $identity = $this->all()[$index] ?? null;

        return $identity !== null && $identity->problems() === [] ? $identity : null;
    }

    /** The user administration URL when this user may open it, '' otherwise. */
    public function editUrl(): string
    {
        if ($this->user === null) {
            return '';
        }
        try {
            $auth = DI::getAuthService();

            return $auth->canReach($auth->getCurrentUser(), 'backend', 'system', 'backend-user', 'list') ? self::USER_ADMIN_URL : '';
        } catch (\Throwable) {
            return '';   // no auth wiring (CLI, a harness) — no link rather than a broken one
        }
    }

    /**
     * What `partials/senderIdentityField` renders: the labels by index, the preselected index,
     * the missing hint and the edit link.
     *
     * @return array{identities: array<int, string>, selected: int, missing: string, editUrl: string, field: string}
     */
    public function viewModel(): array
    {
        $labels = [];
        foreach ($this->all() as $i => $identity) {
            $labels[$i] = $identity->label();
        }

        return [
            'identities' => $labels,
            'selected'   => self::DEFAULT_INDEX,
            'missing'    => $labels === [] ? self::MISSING : '',
            'editUrl'    => $labels === [] ? $this->editUrl() : '',
            'field'      => self::FIELD,
        ];
    }
}
