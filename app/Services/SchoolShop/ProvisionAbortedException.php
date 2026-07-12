<?php

namespace App\Services\SchoolShop;

/**
 * Bricht die Shop-Anlage ab und transportiert das bis dahin entstandene
 * Schritt-Protokoll in die UI.
 */
class ProvisionAbortedException extends \RuntimeException
{
    /** @param  list<array{step: string, ok: bool, detail: string}>  $log */
    public function __construct(public readonly array $log, \Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
