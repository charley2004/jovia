<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Support\Countries;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    private static function digits(?string $s): string
    {
        return preg_replace('/\D/', '', (string) $s);
    }

    // Match phones by their last 9 digits so 0803… and +234 803… are the same number
    private static function findByPhone(string $phone): ?User
    {
        $tail = substr(self::digits($phone), -9);

        return strlen($tail) === 9 ? User::where('phone', 'like', '%'.$tail)->first() : null;
    }

    private static function newReferralCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
        } while (User::where('referral_code', $code)->exists());

        return $code;
    }

    public function signup(Request $request)
    {
        $data = $this->check($request, [
            'firstName' => 'required|string|max:80',
            'lastName' => 'required|string|max:80',
            'email' => 'required|email|max:191',
            'phone' => ['required', 'string', function ($attr, $value, $fail) {
                $n = strlen(self::digits($value));
                if ($n < 9 || $n > 15) {
                    $fail('Enter a valid phone number.');
                }
            }],
            'country' => ['required', Rule::in(Countries::AFRICA)],
            'password' => 'required|string|min:8|max:128',
            'referral' => 'nullable|string|max:16',
            'terms' => 'accepted',
        ], [
            'firstName.required' => 'Enter your first name.',
            'lastName.required' => 'Enter your last name.',
            'email.*' => 'Enter a valid email address.',
            'phone.required' => 'Enter a valid phone number.',
            'country.*' => 'Select your country.',
            'password.*' => 'Password must be at least 8 characters.',
            'terms.accepted' => 'You need to accept the terms to continue.',
        ]);

        $email = strtolower(trim($data['email']));
        if (User::where('email', $email)->exists()) {
            abort(409, 'An account with this email already exists. Sign in instead.');
        }
        if (self::findByPhone($data['phone'])) {
            abort(409, 'An account with this phone number already exists.');
        }
        $referredBy = null;
        if (! empty($data['referral'])) {
            $referrer = User::where('referral_code', strtoupper(trim($data['referral'])))->first();
            if (! $referrer) {
                abort(400, 'That referral code was not found. Check it or leave it blank.');
            }
            $referredBy = $referrer->id;
        }

        $user = User::create([
            'first_name' => trim($data['firstName']),
            'last_name' => trim($data['lastName']),
            'email' => $email,
            'phone' => self::digits($data['phone']),
            'country' => $data['country'],
            'password' => $data['password'],
            'referral_code' => self::newReferralCode(),
            'referred_by_id' => $referredBy,
        ]);
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json(['user' => $user->toApi()], 201);
    }

    public function login(Request $request)
    {
        $data = $this->check($request, [
            'identifier' => 'required|string|min:3',
            'password' => 'required|string',
        ], [
            'identifier.*' => 'Enter your email or phone number.',
            'password.*' => 'Enter your password.',
        ]);
        $id = trim($data['identifier']);
        $user = str_contains($id, '@') ? User::where('email', strtolower($id))->first() : self::findByPhone($id);
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            abort(401, 'Incorrect email/phone or password.');
        }
        if ($user->status === 'SUSPENDED') {
            abort(403, 'This account is suspended. Contact support.');
        }
        Auth::guard('web')->login($user, true);
        $request->session()->regenerate();

        return response()->json(['user' => $user->toApi()]);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()->toApi()]);
    }

    public function forgot(Request $request)
    {
        $data = $this->check($request, ['identifier' => 'required|string|min:3'], ['identifier.*' => 'Enter your email or phone number.']);
        $id = trim($data['identifier']);
        $user = str_contains($id, '@') ? User::where('email', strtolower($id))->first() : self::findByPhone($id);

        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return response()->json(['emailed' => false, 'message' => 'Online reset by email is not switched on yet. Contact support with the email or phone number on your account and we will reset your password.']);
        }
        if ($user) {
            $token = Str::random(64);
            PasswordResetRequest::create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addHour()]);
            $link = rtrim(config('app.url'), '/').'/reset-password.html?token='.$token;
            Mail::raw("Hi {$user->first_name},\n\nUse this link to set a new password (valid for 1 hour):\n{$link}\n\nIf you didn't ask for this, ignore this email.",
                fn ($m) => $m->to($user->email)->subject('Reset your Jovia Network password'));
        }

        // Same answer either way, so the form can't be used to find accounts
        return response()->json(['emailed' => true, 'message' => 'If an account matches, we have emailed a reset link. It expires in 1 hour.']);
    }

    public function reset(Request $request)
    {
        $data = $this->check($request, [
            'token' => 'required|string|min:32',
            'password' => 'required|string|min:8|max:128',
        ], ['password.*' => 'Password must be at least 8 characters.']);
        $reset = PasswordResetRequest::where('token_hash', hash('sha256', $data['token']))->first();
        if (! $reset || $reset->used_at || $reset->expires_at->isPast()) {
            abort(400, 'This reset link is invalid or has expired. Request a new one.');
        }
        User::whereKey($reset->user_id)->first()->update(['password' => $data['password']]);
        $reset->update(['used_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
