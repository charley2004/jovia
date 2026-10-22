<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

abstract class Controller
{
    // Validate and return the data; the exception handler turns the first
    // failure into {"error": "..."} with status 400, which the dashboard shows.
    protected function check(Request $request, array $rules, array $messages = []): array
    {
        return Validator::make($request->all(), $rules, $messages)->validate();
    }
}
