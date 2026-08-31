<?php

namespace Z77\Shared\Jobs;

use Z77\Shared\Import\ImportApplyResult;
use Z77\Shared\Import\ImportServiceFactory;
use Z77\Shared\Import\ImportStaleException;

/**
 * Applies the CURRENT import plan as a background job (ADR-032 §14): a bulk
 * apply of thousands of records is exactly what an HTTP request must not do.
 * No payload — the plan store is the single source: source spec, decisions and
 * fingerprints were persisted by the backend screen, and both the screen-apply
 * and this job funnel through the same {@see \Z77\Shared\Import\ImportService},
 * so there is one apply semantics, not two.
 *
 * Stale state (target data or source file changed since plan time) fails the
 * run instead of writing — the screen recomputes and the developer re-queues.
 * maxAttempts is 1 by registration: retrying against moved data is never right.
 */
final class ImportApplyJob implements Job
{
    public function run(JobContext $context): JobResult
    {
        $service = ImportServiceFactory::fromDi();
        $store   = $service->getPlanStore();

        $state = $store->load();
        if ($state === null) {
            return JobResult::failed('No current import plan — compute one in the backend first.');
        }

        try {
            $source = ImportServiceFactory::sourceFromSpec($state['source'] ?? []);
            $result = $service->apply(
                $source,
                $state['decisions'] ?? [],
                $state['fingerprints'] ?? []
            );
        } catch (ImportStaleException $e) {
            return JobResult::failed($e->getMessage());
        }

        foreach ($result->getLines() as $line) {
            $context->log(sprintf(
                '%s %s — %s',
                $line['status'] === ImportApplyResult::APPLIED ? '✓' : '✗',
                $line['entry']->describe(),
                $line['message']
            ));
        }

        // The screen reads the protocol from the state; the plan itself stays
        // until the developer discards it (a fresh plan now shows the applied
        // records as skipped).
        $state['last_result'] = [
            'at'      => date(DATE_ATOM),
            'via'     => 'job',
            'applied' => $result->countApplied(),
            'failed'  => $result->countFailed(),
            'lines'   => array_map(
                static fn(array $line) => [
                    'entry'   => $line['entry']->describe(),
                    'status'  => $line['status'],
                    'message' => $line['message'],
                ],
                $result->getLines()
            ),
        ];
        $store->save($state);

        $summary = "{$result->countApplied()} applied, {$result->countFailed()} failed";
        return $result->countFailed() === 0
            ? JobResult::done($summary)
            : JobResult::failed($summary . ' — see the import screen for the protocol');
    }
}
