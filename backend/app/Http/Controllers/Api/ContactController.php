<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

// The Contact page form. Open to visitors and members; signed-in members are linked to their account.
class ContactController extends Controller
{
    public function store(Request $request)
    {
        // Bots fill every field, people never see this one
        if (filled($request->input('website'))) {
            return response()->json(['ok' => true], 201);
        }
        $data = $this->check($request, [
            'name' => 'required|string|min:2|max:120',
            'email' => 'required|email|max:191',
            'phone' => 'nullable|string|max:32',
            'topic' => ['required', Rule::in(array_keys(ContactMessage::TOPICS))],
            'message' => 'required|string|min:10|max:3000',
        ], [
            'name.*' => 'Enter your name.',
            'email.*' => 'Enter a valid email address so we can reply.',
            'topic.*' => 'Choose what your message is about.',
            'message.min' => 'Tell us a little more (at least 10 characters).',
            'message.*' => 'Enter your message.',
        ]);

        ContactMessage::create([
            'user_id' => Auth::guard('web')->id(),
            'name' => trim($data['name']),
            'email' => strtolower(trim($data['email'])),
            'phone' => isset($data['phone']) ? trim($data['phone']) ?: null : null,
            'topic' => $data['topic'],
            'message' => trim($data['message']),
            'ip' => $request->ip(),
        ]);

        return response()->json(['ok' => true], 201);
    }
}
