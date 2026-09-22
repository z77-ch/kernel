<?php
namespace Z77\Persistence\Exception;

/**
 * Thrown by the OUTERMOST `TransactionInterface::run()` when it cannot commit
 * because a nested unit of work failed and the outer code swallowed that
 * exception (ADR-039 decision 10: an exception anywhere rolls back the
 * whole). Everything the transaction wrote is rolled back at this point.
 */
class TransactionRolledBackException extends \RuntimeException
{
}
