<?php
/**
 * Generic notification-mail BODY for a public form — renders whatever the
 * {@see \Z77\Shared\Forms\FormDefinition} declares, so a project needs no own
 * body template (see docs/03-development/public-form-bauplan.md). Referenced
 * from emailConfig as ['emails/publicForm', 'Z77\\Shared'].
 *
 * Rendered by the EmailService inside the shared emails/layout; the plain-text
 * alternative is derived from this HTML, which is why every value line is a
 * `<tr data-str="new-line">` (HtmlToText contract, docs/topics/mail.md).
 *
 * @var \Z77\Shared\Forms\PublicForm $form
 */
$isLong = static fn (array $spec): bool => $spec['type'] === 'textarea';
?>
<table>
    <?php foreach ($form->fields() as $name => $spec): ?>
        <?php if ($isLong($spec)) { continue; } ?>
    <tr data-str="new-line">
        <td><?= e($spec['label']) ?></td>
        <td><?php
            if ($spec['type'] === 'checkbox') {
                echo e(t($form->isChecked($name) ? 'form.value.yes' : 'form.value.no'));
            } else {
                echo e($form->display($name));
            }
        ?></td>
    </tr>
    <?php endforeach; ?>
</table>

<?php foreach ($form->fields() as $name => $spec): ?>
    <?php if (!$isLong($spec) || $form->get($name) === '') { continue; } ?>
<p><strong><?= e($spec['label']) ?></strong></p>
<p><?= nl2br(e($form->get($name))) ?></p>
<?php endforeach; ?>
