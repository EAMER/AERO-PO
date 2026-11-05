<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

abstract class Controller
{
    use AuthorizesRequests;

    protected function complete(callable $action): RedirectResponse
    {
        try {
            $action();
        } catch (\InvalidArgumentException|\DomainException $e) {
            throw ValidationException::withMessages(['workflow' => $e->getMessage()]);
        }

        return back()->with('status', 'Action completed.');
    }
}
