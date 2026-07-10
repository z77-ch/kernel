<?php
namespace Z77\Shared\Entities;

use Z77\Shared\Attributes\Clean;
use Z77\Shared\Attributes\Entity;
use Z77\Shared\Traits\ArrayMappable;

#[Entity('file', 'framework/auth/loginUsers.json')]
class LoginUser
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

    public function getId(): ?int               { return $this->id; }
    public function getUsername(): string       { return $this->username; }
    public function getInitials(): string       { return $this->initials; }
    public function getPasswordHash(): string   { return $this->passwordHash; }
    public function getRoles(): array           { return $this->roles; }
    public function getPreferences(): array     { return $this->preferences; }
    public function getSortKey(): int           { return $this->sortKey; }
    public function isPasswordWeak(): bool      { return $this->passwordWeak; }

    public function setUsername(string $username): void       { $this->username = $username; }
    public function setInitials(string $initials): void       { $this->initials = $initials; }
    public function setPasswordHash(string $hash): void       { $this->passwordHash = $hash; }
    public function setRoles(array $roles): void              { $this->roles = $roles; }
    public function setPreferences(array $preferences): void  { $this->preferences = $preferences; }
    public function setSortKey(int $sortKey): void            { $this->sortKey = $sortKey; }
    public function setPasswordWeak(bool $weak): void         { $this->passwordWeak = $weak; }
}
