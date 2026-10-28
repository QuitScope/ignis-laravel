<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

it('reports a query exception without its bindings', function () {
    Log::spy();

    report(new QueryException('sqlite', 'select * from personnel where name = ?', ['Erika Geheim'], new PDOException('boom')));

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context) => $message === 'Database error'
        && $context['sql'] === 'select * from personnel where name = ?'
        && ! str_contains(json_encode($context), 'Erika'));
});
