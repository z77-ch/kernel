<?php

namespace Z77\Core\Services;

use Z77\Core\Session\SessionManager;

/**
 * Central buffer for user-facing feedback. Two channels, two delivery modes.
 *
 * Channels:
 *   flash    — short-lived (top of viewport, success/info auto-dismiss after 5s, error stays)
 *   message  — persistent (bottom-left, stays until closed by user, append-friendly)
 *
 * Delivery modes:
 *   in-place (push*ForEnvelope)        — sent in the current FetchResponse envelope
 *   after redirect (push*AfterRedirect) — stored in session, consumed by the next page render
 *
 * Status values for both channels: 'success' | 'info' | 'error'.
 * Status is server-controlled; lifetime/dismiss behaviour is decided client-side.
 */
class MessageService
{
    private const SESSION_KEY_FLASHES  = '_flash';
    private const SESSION_KEY_MESSAGES = '_message';

    /** @var array<array{type:string,text:string}> */
    private array $flashesBuffer = [];

    /** @var array<array{type:string,text:string}> */
    private array $messagesBuffer = [];

    public function __construct(private SessionManager $sessionManager) {}

    public function pushFlash(string $type, string $text): void
    {
        $this->flashesBuffer[] = ['type' => $type, 'text' => $text];
    }

    public function pushMessage(string $type, string $text): void
    {
        $this->messagesBuffer[] = ['type' => $type, 'text' => $text];
    }

    public function pushFlashAfterRedirect(string $type, string $text): void
    {
        $this->appendToSession(self::SESSION_KEY_FLASHES, $type, $text);
    }

    public function pushMessageAfterRedirect(string $type, string $text): void
    {
        $this->appendToSession(self::SESSION_KEY_MESSAGES, $type, $text);
    }

    /**
     * Returns and clears the in-place flash buffer for envelope inclusion.
     * @return array<array{type:string,text:string}>
     */
    public function consumeFlashesForEnvelope(): array
    {
        $out = $this->flashesBuffer;
        $this->flashesBuffer = [];
        return $out;
    }

    /**
     * Returns and clears the in-place message buffer for envelope inclusion.
     * @return array<array{type:string,text:string}>
     */
    public function consumeMessagesForEnvelope(): array
    {
        $out = $this->messagesBuffer;
        $this->messagesBuffer = [];
        return $out;
    }

    /**
     * Returns and clears the session-flash buffer for the next page render.
     * Single-consumer guarantee: a manual reload must not re-show messages.
     * @return array<array{type:string,text:string}>
     */
    public function consumeFlashesForPage(): array
    {
        return $this->consumeSession(self::SESSION_KEY_FLASHES);
    }

    /**
     * Returns and clears the session-message buffer for the next page render.
     * @return array<array{type:string,text:string}>
     */
    public function consumeMessagesForPage(): array
    {
        return $this->consumeSession(self::SESSION_KEY_MESSAGES);
    }

    private function appendToSession(string $key, string $type, string $text): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $list   = $this->sessionManager->get($key, []);
        $list[] = ['type' => $type, 'text' => $text];
        $this->sessionManager->set($key, $list);
    }

    /**
     * @return array<array{type:string,text:string}>
     */
    private function consumeSession(string $key): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return [];
        }
        $list = $this->sessionManager->get($key, []);
        $this->sessionManager->remove($key);
        return $list;
    }
}
