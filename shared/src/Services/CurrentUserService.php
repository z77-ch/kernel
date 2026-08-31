<?php
namespace Z77\Shared\Services;

use Z77\Shared\Entities\BackendUser,
    Z77\Shared\ValueObjects\UserPreferences,
    Z77\Persistence\Resolver\UnifiedEntityManager
;

/**
 * Per-request provider for the authenticated user entity and preferences.
 *
 * Caches the BackendUser for the lifetime of the request so that multiple
 * consumers (controllers, layout hooks) share a single DB read.
 * Call savePreferences() to persist changes — the cache is invalidated
 * automatically so subsequent getBackendUser() / getPreferences() calls
 * return the updated state.
 */
class CurrentUserService
{
    private ?BackendUser $backendUser = null;
    private bool $loaded = false;

    public function __construct(
        private AuthService $authService,
        private UnifiedEntityManager $uem
    ) {}

    public function getBackendUser(): ?BackendUser
    {
        if (!$this->loaded) {
            // Backend realm only: a member-realm id lives in a different id
            // space (MemberAccount) and must never be looked up here.
            if (!$this->authService->getCurrentUser()->isBackendRealm()) {
                $this->loaded = true;

                return null;
            }
            $this->loaded = true;
            $id = $this->authService->getCurrentUser()->getId();
            if ($id > 0) {
                $this->backendUser = $this->uem->getRepository(BackendUser::class)->find($id);
            }
        }
        return $this->backendUser;
    }

    public function getPreferences(): UserPreferences
    {
        return new UserPreferences($this->getBackendUser()?->getPreferences() ?? []);
    }

    public function savePreferences(UserPreferences $prefs): void
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return;
        }

        $backendUser->setPreferences($prefs->toArray());
        $this->uem->persist($backendUser);
        $this->uem->flush();

        $this->backendUser = null;
        $this->loaded    = false;
    }
}
