<?php

namespace Z77\Shared\Mail;

/**
 * Turns a {@see Message} into the RFC 5322 / MIME wire format (DMS Phase 6 — own build,
 * no dependency). Structure is chosen from the content:
 *
 *   text only, no attachments        → single `text/plain` body
 *   text + html, no attachments      → `multipart/alternative`
 *   any body + attachments           → `multipart/mixed` wrapping the body part
 *
 * Bodies and attachments are base64-encoded (robust for UTF-8 + binary), lines wrapped
 * at 76 chars, CRLF throughout. Non-ASCII subjects / display names are RFC 2047 B-encoded.
 * The transport ({@see SmtpTransport}) is responsible for SMTP dot-stuffing of the
 * resulting data blob; this builder only produces headers + body.
 */
final class MimeMessage
{
    private const CRLF = "\r\n";

    /**
     * @return array{sender: string, recipients: list<string>, data: string}
     */
    public static function build(Message $message): array
    {
        if ($message->getFromAddress() === '') {
            throw new \RuntimeException('Message has no From address.');
        }
        if ($message->getRecipients() === []) {
            throw new \RuntimeException('Message has no recipients.');
        }

        $self = new self();
        return [
            'sender'     => $message->getFromAddress(),
            'recipients' => $message->getRecipients(),
            'data'       => $self->render($message),
        ];
    }

    private function render(Message $m): string
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($m->getFromAddress(), $m->getFromName()),
            'To: ' . $this->addressList($m->getTo()),
        ];
        if ($m->getCc() !== []) {
            $headers[] = 'Cc: ' . $this->addressList($m->getCc());
        }
        if ($m->getReplyTo() !== '') {
            $headers[] = 'Reply-To: ' . $m->getReplyTo();
        }
        $headers[] = 'Subject: ' . $this->encodeWord($m->getSubject());
        $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->senderDomain($m) . '>';
        $headers[] = 'MIME-Version: 1.0';

        $content = $this->contentPart($m);

        if ($m->getAttachments() !== []) {
            $boundary = $this->boundary('mixed');
            $parts    = [$content];
            foreach ($m->getAttachments() as $attachment) {
                $parts[] = $this->attachmentPart($attachment);
            }
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
            $body = $this->multipartBody($boundary, $parts);
        } else {
            foreach ($content['headers'] as $h) {
                $headers[] = $h;
            }
            $body = $content['body'];
        }

        return implode(self::CRLF, $headers) . self::CRLF . self::CRLF . $body;
    }

    /**
     * The body part (without attachments): a text/plain leaf, or a multipart/alternative
     * of text + html when an HTML body is present.
     *
     * @return array{headers: list<string>, body: string}
     */
    private function contentPart(Message $m): array
    {
        $text = $this->leaf('text/plain; charset=UTF-8', $m->getTextBody());

        if ($m->getHtmlBody() === null) {
            return $text;
        }

        $html     = $this->leaf('text/html; charset=UTF-8', $m->getHtmlBody());
        $boundary = $this->boundary('alt');
        return [
            'headers' => ['Content-Type: multipart/alternative; boundary="' . $boundary . '"'],
            'body'    => $this->multipartBody($boundary, [$text, $html]),
        ];
    }

    /**
     * @return array{headers: list<string>, body: string}
     */
    private function leaf(string $contentType, string $body): array
    {
        return [
            'headers' => ['Content-Type: ' . $contentType, 'Content-Transfer-Encoding: base64'],
            'body'    => $this->base64($body),
        ];
    }

    /**
     * @return array{headers: list<string>, body: string}
     */
    private function attachmentPart(Attachment $a): array
    {
        return [
            'headers' => [
                'Content-Type: ' . $a->mimeType . '; name="' . $a->filename . '"',
                'Content-Transfer-Encoding: base64',
                'Content-Disposition: attachment; filename="' . $a->filename . '"',
            ],
            'body' => $this->base64($a->bytes),
        ];
    }

    /**
     * @param list<array{headers: list<string>, body: string}> $parts
     */
    private function multipartBody(string $boundary, array $parts): string
    {
        $out = '';
        foreach ($parts as $part) {
            $out .= '--' . $boundary . self::CRLF
                 . implode(self::CRLF, $part['headers']) . self::CRLF . self::CRLF
                 . $part['body'] . self::CRLF;
        }
        $out .= '--' . $boundary . '--' . self::CRLF;
        return $out;
    }

    private function base64(string $bytes): string
    {
        return rtrim(chunk_split(base64_encode($bytes), 76, self::CRLF), self::CRLF);
    }

    private function boundary(string $tag): string
    {
        return '=_z77_' . $tag . '_' . bin2hex(random_bytes(10));
    }

    /**
     * @param list<string> $addresses
     */
    private function addressList(array $addresses): string
    {
        return implode(', ', $addresses);
    }

    private function formatAddress(string $address, string $name): string
    {
        if ($name === '') {
            return $address;
        }
        if ($this->isAscii($name)) {
            return '"' . str_replace('"', '', $name) . '" <' . $address . '>';
        }
        return $this->encodeWord($name) . ' <' . $address . '>';
    }

    /** RFC 2047 B-encoding for non-ASCII header text; pass-through for plain ASCII. */
    private function encodeWord(string $value): string
    {
        if ($this->isAscii($value)) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function isAscii(string $value): bool
    {
        return preg_match('/[^\x20-\x7E]/', $value) !== 1;
    }

    private function senderDomain(Message $m): string
    {
        $at = strrpos($m->getFromAddress(), '@');
        return $at !== false ? substr($m->getFromAddress(), $at + 1) : 'localhost';
    }
}
