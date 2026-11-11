<?php

namespace Z77\Shared\Content;

/**
 * The asset store behind `kind: document` block fields, as the content EDITOR sees it
 * (media-picker bauplan P2b). `shared` knows the SHAPE of a document id
 * ({@see BlockSchemaValidator::documentId()}) and nothing of the store; the module that owns
 * the store (module-dms) declares ONE implementation in its config:
 *
 *   'contentDocuments' => \Z77\Module\Dms\Content\ContentDocuments::class,
 *
 * folded in by {@see ContentExtensions::assemble()} — a second declaration is a configuration
 * error. Without one, a `document` field is a plain id input and only its shape is checked.
 *
 * Built with no constructor argument (like a block renderer); it reaches its services lazily.
 */
interface DocumentReferences
{
    /**
     * Why document $id may NOT stand in $field (its descriptor: `accept`, …) for the session's
     * user, or null. German, the second half of an editor message: «Bild» <refusal>. Asked only
     * for an id the editor's save CHANGES — a reference already stored is the store's business
     * (a renderer skips what it cannot show), so a later change in the store never blocks a save
     * of another block.
     */
    public function refusal(int $id, array $field): ?string;

    /**
     * Where the editor's picker opens: a folder id or a partition key ('' = every partition the
     * user may read). May create the store's place for content on first use.
     */
    public function pickerRoot(): string;
}
