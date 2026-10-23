<?php
/**
 * «Senden als» — the sender identity a staff member signs a mail with (owner decision
 * 2026-10-23). A shared building block (Rule 9): every send action that queues mail a person
 * triggers (today the booking print's «Alle per E-Mail senden …»; later offer, order
 * confirmation, newsletter) renders it from {@see \Z77\Shared\Mail\SenderIdentities::viewModel()}:
 *
 *   <?= $this->partial('partials/senderIdentityField', $senders->viewModel(), 'Z77\\Shared') ?>
 *
 *   - several identities → a select of «Name <email>», the default (the first) preselected;
 *   - exactly one → shown read-only, the index as a hidden field;
 *   - none → the hint «Erfasse zuerst eine E-Mail an deinem User» (with a link to the user
 *     administration when the user may open it) and NO field — the caller renders no send button.
 *
 * The field carries only the INDEX; the server re-checks it with
 * `SenderIdentities::choose()` (Rule 7). Plain markup, no script.
 *
 * @var array<int, string> $identities index => label
 * @var int    $selected
 * @var string $missing   the hint when there is no identity
 * @var string $editUrl   the user administration, '' when not reachable
 * @var string $field     the POST field name
 */
$identities = (array) ($identities ?? []);
$selected   = (int) ($selected ?? 0);
$field      = (string) ($field ?? 'sender');
?>
<?php if ($identities === []): ?>
<div class="be-modal__alert be-modal__alert--error" role="status" data-sender-missing>
    <?= e((string) ($missing ?? '')) ?>
    <?php if (!empty($editUrl)): ?> <a href="<?= e($editUrl) ?>">Zu den Benutzern</a> (dein User › Bearbeiten › «Absender für E-Mails»).<?php endif; ?>
</div>
<?php elseif (count($identities) === 1): ?>
<div class="be-form__field" data-sender-identity>
    <label>Senden als</label>
    <span><?= e((string) reset($identities)) ?></span>
    <input type="hidden" name="<?= e($field) ?>" value="<?= e((string) array_key_first($identities)) ?>">
</div>
<?php else: ?>
<div class="be-form__field" data-z77-field-wrapper data-sender-identity>
    <label for="sender-identity">Senden als</label>
    <select id="sender-identity" name="<?= e($field) ?>">
        <?php foreach ($identities as $index => $label): ?>
        <option value="<?= e((string) $index) ?>"<?= $index === $selected ? ' selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
</div>
<?php endif; ?>
