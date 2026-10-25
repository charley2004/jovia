<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

// Unsubscribe link in reminder emails (signed URL, so nobody can unsubscribe someone else).
class EmailPreferenceController extends Controller
{
    // Link clicked in the email
    public function unsubscribe(User $user)
    {
        $user->forceFill(['email_opt_out' => true])->save();

        return response()->view('emails.unsubscribed', ['email' => $user->email]);
    }

    // One-click unsubscribe sent by the mail app (List-Unsubscribe-Post)
    public function oneClick(Request $request, User $user)
    {
        $user->forceFill(['email_opt_out' => true])->save();

        return response()->noContent();
    }
}
