<?php

namespace Z77\Shared\Validators;

use Z77\Core\Config\AuthRole;
use Z77\Persistence\Validation\EntityValidator;
use Z77\Shared\Auth\PasswordPolicy;
use Z77\Shared\Auth\PasswordTier;
use Z77\Shared\Entities\LoginUser;
use Z77\Shared\Repositories\LoginUserRepository;

/**
 * Validates a {@see LoginUser} for the backend user management.
 *
 * Username: required, ASCII alphanumeric (+ _ -), min 3, unique across users.
 * Roles: at least one, each a known {@see AuthRole} key.
 * Password: plaintext is transient (not an entity field), so it is checked via
 * an overridden {@see executeValidation()} — required on add, optional on edit
 * (blank = keep current). A length floor (8) always applies; under the
 * `veryStrong` {@see PasswordTier} the full policy is enforced as a HARD BLOCK
 * (all other tiers only nag via the `passwordWeak` flag — see security.md).
 *
 * `excludeId` lets the live single-field check exclude the edited user from the
 * uniqueness scan (the transient check-entity carries no id).
 */
class LoginUserValidator extends EntityValidator
{
    /** Absolute length floor enforced for every tier (a sanity minimum). */
    private const ABSOLUTE_MIN_LENGTH = 8;

    public function __construct(
        LoginUser $user,
        private ?LoginUserRepository $repo = null,
        private string $password = '',
        private bool $isNew = false,
        private ?int $excludeId = null,
        private PasswordTier $tier = PasswordTier::Strong,
    ) {
        parent::__construct($user);
    }

    protected function executeValidation(?array $only = null): void
    {
        parent::executeValidation($only);

        // `password` is not an entity field — run its check explicitly.
        if ($only === null || in_array('password', $only, true)) {
            $this->validatePasswordValue();
        }
    }

    public function validateUsername(string $username): void
    {
        $this->validate('username', 'Benutzername', $username)
            ->notEmpty()
            ->minLength(3)
            ->isAlphaAsciiNum();

        if ($this->hasFieldError('username') || $this->repo === null) {
            return;
        }

        $ownId = $this->excludeId ?? $this->entity->getId();
        foreach ($this->repo->findAll() as $other) {
            if ($other->getId() !== $ownId && strcasecmp($other->getUsername(), $username) === 0) {
                $this->addFieldError('username', 'Benutzername «' . $username . '» ist bereits vergeben.');
                return;
            }
        }
    }

    public function validateRoles(array $roles): void
    {
        if ($roles === []) {
            $this->addFieldError('roles', 'Mindestens eine Rolle wählen.');
            return;
        }

        $valid = array_keys(AuthRole::getRoleHierarchy());
        foreach ($roles as $role) {
            if (!in_array($role, $valid, true)) {
                $this->addFieldError('roles', 'Unbekannte Rolle: ' . (string) $role);
                return;
            }
        }
    }

    public function validateInitials(string $initials): void
    {
        $initials = trim($initials);
        if ($initials === '') {
            return; // optional — empty falls back to the auto-derived initials
        }
        $length = mb_strlen($initials);
        if ($length < 2 || $length > 3) {
            $this->addFieldError('initials', 'Initialen müssen 2–3 Zeichen lang sein.');
        }
    }

    private function validatePasswordValue(): void
    {
        // Edit: a blank password keeps the current one — nothing to validate.
        if (!$this->isNew && $this->password === '') {
            return;
        }

        $check = $this->validate('password', 'Passwort', $this->password)->minLength(self::ABSOLUTE_MIN_LENGTH);
        if ($this->isNew) {
            $check->notEmpty();
        }
        if ($this->hasFieldError('password')) {
            return;
        }

        // veryStrong is the only tier that hard-blocks; all others just nag (the
        // passwordWeak flag is set by the controller). Length + blocklist only —
        // never character-class composition.
        if ($this->tier->blocksWeak()) {
            $eval = PasswordPolicy::evaluate($this->password, [$this->entity->getUsername()], $this->tier);
            if ($eval['weak']) {
                $this->addFieldError('password', 'Passwort genügt den Anforderungen nicht: ' . implode(', ', $eval['reasons']) . '.');
            }
        }
    }
}
