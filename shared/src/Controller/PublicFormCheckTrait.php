<?php

namespace Z77\Shared\Controller;

use Z77\Core\DI,
    Z77\Core\Http\Response\JsonResponse,
    Z77\Shared\Forms\FormDefinition,
    Z77\Shared\Forms\PublicForm,
    Z77\Shared\Forms\PublicFormValidator
;

/**
 * Per-field validation endpoint for a public form (see
 * docs/03-development/public-form-bauplan.md). The form's JS POSTs
 * {field, value} on blur and this answers {valid, message} — the SAME validator
 * the full submit runs, on one field. No validation logic ever reaches the
 * client; the JS only mirrors the verdict into the DOM.
 *
 * Usage in a controller:
 *
 *   protected function checkAction(): JsonResponse
 *   {
 *       return $this->blurCheck(new ContactFormDefinition());
 *   }
 *
 * CSRF: none in-action — AccessGuard validates fetch POSTs against the
 * X-CSRF-Token header (CONTACT-CHECK-001, docs/topics/security.md).
 */
trait PublicFormCheckTrait
{
    protected function blurCheck(FormDefinition $definition): JsonResponse
    {
        $request = DI::getRequest();

        if ($request->isReadMethod()) {
            return $this->json(['valid' => false, 'message' => ''], 405);
        }

        // Checkable fields come from the declaration — an unknown name is a
        // client bug (or a probe) and is answered without running anything.
        $field = (string) $request->getPostParameter('field');
        if (!$definition->hasField($field)) {
            return $this->json(['valid' => false, 'message' => ''], 400);
        }

        $form      = PublicForm::fromPost($definition, [$field => $request->getPostParameter('value')]);
        $validator = new PublicFormValidator($form);
        $valid     = $validator->isValid([$field]);

        return $this->json([
            'valid'   => $valid,
            'message' => $valid ? '' : $validator->getFieldError($field),
        ]);
    }
}
