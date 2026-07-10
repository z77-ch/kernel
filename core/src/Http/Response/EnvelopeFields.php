<?php

namespace Z77\Core\Http\Response;

/**
 * Shared envelope channels for responses that carry client-side feedback.
 *
 * Three channels:
 *   flashes  — short-lived top-of-viewport messages
 *   messages — persistent bottom-left messages
 *   commands — client-side actions (load-script, close-modal, ...)
 *
 * Used by FetchResponse (serialized as JSON body) and HtmlResponse (serialized
 * as embedded <script type="application/json" data-z77-envelope> block).
 * Client side, both forms are dispatched by the same envelope handler in core.js.
 */
trait EnvelopeFields
{
    /** @var array<array{type:string,text:string}> */
    private array $flashes = [];

    /** @var array<array{type:string,text:string}> */
    private array $messages = [];

    /** @var array<array{action:string,...}> */
    private array $commands = [];

    public function addFlash(string $type, string $text): self
    {
        $this->flashes[] = ['type' => $type, 'text' => $text];
        return $this;
    }

    public function addMessage(string $type, string $text): self
    {
        $this->messages[] = ['type' => $type, 'text' => $text];
        return $this;
    }

    /**
     * @param array<array{type:string,text:string}> $flashes
     */
    public function setFlashes(array $flashes): self
    {
        $this->flashes = $flashes;
        return $this;
    }

    /**
     * @param array<array{type:string,text:string}> $messages
     */
    public function setMessages(array $messages): self
    {
        $this->messages = $messages;
        return $this;
    }

    public function addCommand(string $action, array $params = []): self
    {
        $this->commands[] = array_merge(['action' => $action], $params);
        return $this;
    }

    /**
     * Returns only non-empty envelope channels, ready to be merged into a
     * JSON body or serialized into an embedded HTML script tag.
     */
    protected function buildEnvelopeFields(): array
    {
        $env = [];
        if (!empty($this->flashes))  $env['flashes']  = $this->flashes;
        if (!empty($this->messages)) $env['messages'] = $this->messages;
        if (!empty($this->commands)) $env['commands'] = $this->commands;
        return $env;
    }

    protected function hasEnvelopeFields(): bool
    {
        return !empty($this->flashes) || !empty($this->messages) || !empty($this->commands);
    }
}
