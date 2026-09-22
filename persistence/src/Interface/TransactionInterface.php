<?php
namespace Z77\Persistence\Interface;

/**
 * The transaction port (ADR-039 decision 10) — the one thing the shared
 * repository API deliberately does not carry (ARCH-A003). Obtained through
 * `UnifiedEntityManager::getTransaction(EntityClass::class)`, so the backend
 * stays a property of `#[Entity]`; only the Doctrine driver fulfils it, the
 * File driver refuses it with an exception instead of pretending.
 *
 * Consumers type against this interface, never against Doctrine.
 */
interface TransactionInterface
{
    /**
     * Runs the unit of work atomically: commit on return, roll back and
     * rethrow on any exception. A call inside an open transaction JOINS it —
     * no inner commit, and an exception anywhere rolls back the whole, even
     * one the outer code catches (the outermost call then throws
     * `TransactionRolledBackException` instead of committing).
     *
     * @template T
     * @param callable(): T $unitOfWork
     * @return T what the unit of work returned
     */
    public function run(callable $unitOfWork): mixed;

    /** Whether a unit of work opened through this port is running right now. */
    public function isOpen(): bool;
}
