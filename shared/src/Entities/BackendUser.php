<?php
namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Counter;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Mail\SenderIdentity;
use Z77\Shared\Traits\ArrayMappable;

#[Entity('file', 'framework/auth/backendUsers.json')]
class BackendUser
{
    use ArrayMappable;

    public function __construct(array $data = [])
    {
        if ($data) {
            $this->mapFromArray($data);
        }
    }

    private ?int $id = null;

    #[Clean('text')]
    private string $username = '';

    /**
     * Optional avatar initials (2–3 chars), user-entered display data — NOT
     * security-relevant, so it maps straight from the edit form. Empty → the UI
     * derives initials from the username (first two letters, uppercased).
     */
    #[Clean('text')]
    private string $initials = '';

    /**
     * The person's full name («Max Muster»), display data like the initials — shown where the
     * backend says who is signed in (the dashboard, owner 2026-10-22). Optional: a file written
     * before the field has no key and reads ''.
     */
    #[Clean('text')]
    private string $name = '';

    public const NAME_LENGTH = 80;

    /**
     * Recovery address for «Passwort vergessen» (login.md LOGIN-RESET-001): optional, unique
     * across users (case-insensitive, BackendUserValidator). Separate from the sender identities
     * below, which stay display data. A file written before the field has no key and reads ''
     * (no address = the forgot form sends nothing).
     */
    #[Clean('email')]
    private string $email = '';

    public const EMAIL_LENGTH = 254;

    /**
     * The names this person sends mail in (owner decision 2026-10-23): 0..n rows
     * `{name, email}` (+ `phone` when set, owner 2026-11-13), the FIRST is the default — {@see SenderIdentity}, resolved for a send
     * action by {@see \Z77\Shared\Mail\SenderIdentities}. JSON key `sender_identities`; a
     * file written before the field has no key and reads [] (no identity — no sending until
     * one is entered). Without #[Clean]: the controller rebuilds the list from the form rows
     * (`SenderIdentity::listFromForm()`), the body's own key is never trusted.
     *
     * @var list<array{name: string, email: string, phone?: string}>
     */
    private array $senderIdentities = [];

    /** At most this many identities per user — a short choice on the send page. */
    public const SENDER_IDENTITIES_MAX = 10;

    /** bcrypt hash — never mapped from the edit form; set via the controller from the plaintext `password` field. */
    private string $passwordHash = '';

    /** @var string[] AuthRole keys — controller-validated, never trusted from the body. */
    private array $roles = [];

    private array $preferences = [];

    /**
     * Order in the user list; lower comes first. Server-controlled (set on add
     * via nextSortKey, on reorder via moveAction) — deliberately without #[Clean]
     * and re-set server-side after mapFromArray, never trusted from the form.
     */
    private int $sortKey = 0;

    /**
     * Whether the current password failed {@see PasswordPolicy} when it was last
     * set. Server-controlled (computed when the password is set, never from the
     * form). Drives the every-login nag — the policy allows weak passwords but
     * keeps reminding. Cannot be recomputed from the bcrypt hash, so it is stored.
     */
    private bool $passwordWeak = false;

    /**
     * Session stamp: copied into the session at login and compared on every request
     * (AuthService::getCurrentUser), so raising it ends the user's other sessions — there is no
     * server-side session list. A file written before the field reads 0, like a session without
     * the stamp, so a deploy throws nobody out.
     *
     * ⚠️ Deliberately WITHOUT a setter: hydration writes it by reflection and mapFromArray() cannot
     * reach it, so a form body carrying `auth_epoch` has no effect. The only way up is
     * {@see bumpAuthEpoch()}. A #[Counter]: a raise holds against a parallel save and a parallel
     * raise, the store adds it to what is on disk (persistence-file.md DATA-MERGE-001).
     */
    #[Counter]
    private int $authEpoch = 0;

    /**
     * The second factor (login.md BACKEND-2FA-001): the vault ciphertext of the TOTP secret, when
     * it was switched on (ISO 8601), and the last time step accepted, so a code works once
     * (RFC 6238 §5.2). A file written before the fields reads '' / '' / 0: 2FA off. A running
     * enrolment is not here, it waits in the session of the user setting it up.
     *
     * ⚠️ Deliberately WITHOUT setters, like {@see $authEpoch}: a form body carrying these keys has
     * no effect. Templates and answers must use {@see hasTotp()} and nothing else; the ciphertext
     * is for BackendTwoFactor.
     */
    private string $totpSecret = '';
    private string $totpActivatedAt = '';
    private int $totpLastStep = 0;

    public function getId(): ?int               { return $this->id; }
    public function getUsername(): string       { return $this->username; }
    public function getInitials(): string       { return $this->initials; }
    public function getName(): string           { return $this->name; }
    public function getEmail(): string          { return $this->email; }
    public function getAuthEpoch(): int         { return $this->authEpoch; }
    public function getPasswordHash(): string   { return $this->passwordHash; }
    public function getRoles(): array           { return $this->roles; }
    public function getPreferences(): array     { return $this->preferences; }
    public function getSortKey(): int           { return $this->sortKey; }
    public function isPasswordWeak(): bool      { return $this->passwordWeak; }
    public function hasTotp(): bool             { return $this->totpSecret !== '' && $this->totpActivatedAt !== ''; }
    public function getTotpSecret(): string     { return $this->totpSecret; }
    public function getTotpActivatedAt(): string { return $this->totpActivatedAt; }
    public function getTotpLastStep(): int      { return $this->totpLastStep; }

    /** @return list<SenderIdentity> the default first */
    public function getSenderIdentities(): array
    {
        return array_map(static fn(array $row) => SenderIdentity::fromArray($row), $this->senderIdentities);
    }

    public function setUsername(string $username): void       { $this->username = $username; }
    public function setInitials(string $initials): void       { $this->initials = $initials; }
    public function setName(string $name): void               { $this->name = trim($name); }
    public function setEmail(string $email): void             { $this->email = trim($email); }
    public function setPasswordHash(string $hash): void       { $this->passwordHash = $hash; }
    public function setRoles(array $roles): void              { $this->roles = $roles; }
    public function setPreferences(array $preferences): void  { $this->preferences = $preferences; }
    public function setSortKey(int $sortKey): void            { $this->sortKey = $sortKey; }
    public function setPasswordWeak(bool $weak): void         { $this->passwordWeak = $weak; }

    /** The password changed: every session carrying the previous stamp is stale from now on. */
    public function bumpAuthEpoch(): void                     { $this->authEpoch++; }

    /**
     * A new account's stamp. Ids are max+1, so a new user can get the id of a deleted one: with a
     * stamp nobody has, the deleted user's sessions do not fit him (login.md, id reuse).
     */
    public function seedAuthEpoch(int $now): void             { $this->authEpoch = $now; }

    /** The enrolment was confirmed: 2FA is on, and the confirming code is already spent. */
    public function enableTotp(string $ciphertext, string $activatedAt, int $acceptedStep): void
    {
        $this->totpSecret      = $ciphertext;
        $this->totpActivatedAt = $activatedAt;
        $this->totpLastStep    = $acceptedStep;
    }

    /** A code was accepted: its time step cannot be used again. */
    public function recordTotpStep(int $step): void           { $this->totpLastStep = $step; }

    /** 2FA is off: switched off with a code, or reset by an administrator. */
    public function clearTotp(): void
    {
        $this->totpSecret      = '';
        $this->totpActivatedAt = '';
        $this->totpLastStep    = 0;
    }

    /**
     * Rows `{name, email}` (the stored form) or {@see SenderIdentity} objects, in order; anything
     * else is dropped. Stored as given — the validator judges ({@see SenderIdentity::problems()}).
     *
     * @param list<SenderIdentity|array<string, mixed>>|mixed $identities
     */
    public function setSenderIdentities(mixed $identities): void
    {
        $rows = [];
        foreach (is_array($identities) ? $identities : [] as $identity) {
            if (is_array($identity)) {
                $identity = SenderIdentity::fromArray($identity);
            }
            if ($identity instanceof SenderIdentity) {
                $rows[] = $identity->toArray();
            }
        }
        $this->senderIdentities = $rows;
    }
}
