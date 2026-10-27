<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    /**
     * Batas percobaan login gagal per kombinasi email + IP
     * sebelum dikunci sementara selama LOGIN_DECAY_DETIK.
     */
    private const LOGIN_MAKS_PERCOBAAN = 5;
    private const LOGIN_DECAY_DETIK    = 60;

    public function __construct(
        private AuditLogService $auditLogService
    ) {}

    /**
     * Tampilkan halaman login.
     * Jika sudah login, redirect ke dashboard.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * Proses login dengan rate limiting per email + IP.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $throttleKey = $this->throttleKey($request);

        if (RateLimiter::tooManyAttempts($throttleKey, self::LOGIN_MAKS_PERCOBAAN)) {
            $detik = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Terlalu banyak percobaan login. Coba lagi dalam {$detik} detik.",
            ]);
        }

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            RateLimiter::clear($throttleKey);
            $request->session()->regenerate();
            $this->auditLogService->login();

            return redirect()->intended(route('dashboard'));
        }

        RateLimiter::hit($throttleKey, self::LOGIN_DECAY_DETIK);

        return back()->withErrors([
            'email' => 'Email atau password salah.',
        ])->onlyInput('email');
    }

    /**
     * Proses logout.
     */
    public function logout(Request $request)
    {
        // Catat sebelum logout agar nama petugas masih tersedia
        $this->auditLogService->logout();

        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Tampilkan halaman ganti password.
     */
    public function showChangePassword()
    {
        return Inertia::render('Auth/ChangePassword');
    }

    /**
     * Simpan password baru setelah memverifikasi password lama.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'password_lama' => 'required|string|current_password',
            'password'      => ['required', 'confirmed', 'different:password_lama', Password::min(8)->letters()->numbers()],
        ], [
            'password_lama.current_password' => 'Password lama salah.',
            'password.different'             => 'Password baru harus berbeda dari password lama.',
            'password.confirmed'             => 'Konfirmasi password tidak cocok.',
        ]);

        $request->user()->update([
            'password' => Hash::make($validated['password']),
        ]);

        // Keluarkan sesi lain milik user ini (perangkat lain yang mungkin
        // masih login dengan password lama). Hanya berlaku untuk session
        // driver database; driver lain tidak menyimpan user_id per sesi.
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))
                ->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete();
        }

        $this->auditLogService->gantiPassword();

        return redirect()->route('password.edit')
            ->with('success', 'Password berhasil diperbarui.');
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->input('email')) . '|' . $request->ip());
    }
}
