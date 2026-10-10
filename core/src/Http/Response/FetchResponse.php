<?php

namespace Z77\Core\Http\Response;

class FetchResponse implements ResponseInterface
{
    use EnvelopeFields;

    private string  $status   = 'success';
    private array   $fields   = [];
    private ?array  $redirect = null;
    private array   $data     = [];
    private string  $html     = '';

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function setField(string $name, bool $valid, string $message = ''): self
    {
        $this->fields[$name] = ['valid' => $valid, 'message' => $message];
        return $this;
    }

    public function setRedirect(string $url, int $delay = 0): self
    {
        $this->redirect = ['url' => $url, 'delay' => $delay];
        return $this;
    }

    public function setData(array $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function setHtml(string $html): self
    {
        $this->html = $html;
        return $this;
    }

    /*
     * ── The row vocabulary (ADR-047 addendum 2026-10-10) ──────────────────────────────
     *
     * A one-row save answers with what changed, not with `reload`. A list row carries the
     * entity identity the windows already use — `data-entity="<entity>:<id>"` — and a list
     * that receives new rows carries `data-entity-list="<entity>"`. The three helpers below
     * turn «replace the row of invoice 42» into one line; they emit the existing commands
     * (`replace-html`, `remove-element`, `insert-html`), nothing new in core.js.
     *
     * `$origin` (optional) scopes the target to the page part the request came from
     * ('page' | 'region:<name>' | 'window:<id>'), as every command does.
     */

    /** The DOM address of one entity row: `[data-entity="<entity>:<id>"]`. */
    public static function rowTarget(string $entity, int|string $id): string
    {
        return '[data-entity="' . $entity . ':' . $id . '"]';
    }

    /** The DOM address of the list that receives new rows of an entity: `[data-entity-list="<entity>"]`. */
    public static function listTarget(string $entity): string
    {
        return '[data-entity-list="' . $entity . '"]';
    }

    /** Replace the rendered row of an entity (`replace-html` on its `data-entity` element). */
    public function replaceRow(string $entity, int|string $id, string $html, string $origin = ''): self
    {
        return $this->addCommand('replace-html', self::scoped(['target' => self::rowTarget($entity, $id), 'html' => $html], $origin));
    }

    /** Remove the row of an entity (`remove-element`). */
    public function removeRow(string $entity, int|string $id, string $origin = ''): self
    {
        return $this->addCommand('remove-element', self::scoped(['target' => self::rowTarget($entity, $id)], $origin));
    }

    /**
     * Insert a new row into the entity's list (`insert-html` on its `data-entity-list` element).
     * $position: 'append' (default) | 'prepend' | 'before' | 'after' — the last two address a
     * sibling row, so hand its target in through $target.
     */
    public function insertRow(string $entity, string $html, string $position = 'append', string $origin = '', ?string $target = null): self
    {
        return $this->addCommand('insert-html', self::scoped([
            'target'   => $target ?? self::listTarget($entity),
            'html'     => $html,
            'position' => $position,
        ], $origin));
    }

    /** @param array<string,mixed> $params */
    private static function scoped(array $params, string $origin): array
    {
        if ($origin !== '') {
            $params['origin'] = $origin;
        }
        return $params;
    }

    public function send(): void
    {
        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->build(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function build(): array
    {
        $envelope = [
            'status'   => $this->status,
            'flashes'  => $this->flashes,
            'messages' => $this->messages,
            'fields'   => $this->fields,
            'data'     => $this->data,
        ];

        if ($this->redirect !== null) {
            $envelope['redirect'] = $this->redirect;
        }
        if ($this->html !== '') {
            $envelope['html'] = $this->html;
        }
        if (!empty($this->commands)) {
            $envelope['commands'] = $this->commands;
        }

        return $envelope;
    }
}
