<?php
namespace Z77\Shared\Services;

use Z77\Shared\Entities\LoginUser,
    Z77\Shared\ValueObjects\UserPreferences,
    Z77\Persistence\Resolver\UnifiedEntityManager
;

/**
 * Per-request provider for the authenticated user entity and preferences.
 *
 * Caches the LoginUser for the lifetime of the request so that multiple
 * consumers (controllers, layout hooks) share a single DB read.
 * Call savePreferences() to persist changes — the cache is invalidated
 * automatically so subsequent getLoginUser() / getPreferences() calls
 * return the updated state.
 */
class CurrentUserService
{
    private ?LoginUser $loginUser = null;
    private bool $loaded = false;

    public function __construct(
        private AuthService $authService,
        private UnifiedEntityManager $uem
    ) {}

    public function getLoginUser(): ?LoginUser
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $id = $this->authService->getCurrentUser()->getId();
            if ($id > 0) {
                $this->loginUser = $this->uem->getRepository(LoginUser::class)->find($id);
            }
        }
        return $this->loginUser;
    }

    public function getPreferences(): UserPreferences
    {
        return new UserPreferences($this->getLoginUser()?->getPreferences() ?? []);
    }

    public function savePreferences(UserPreferences $prefs): void
    {
        $loginUser = $this->getLoginUser();
        if ($loginUser === null) {
            return;
        }

        $loginUser->setPreferences($prefs->toArray());
        $this->uem->persist($loginUser);
        $this->uem->flush();

        $this->loginUser = null;
        $this->loaded    = false;
    }
}
