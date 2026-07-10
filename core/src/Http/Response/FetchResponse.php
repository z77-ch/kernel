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
