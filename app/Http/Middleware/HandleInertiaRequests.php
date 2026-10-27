<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // Flash messages — tersedia di semua halaman via usePage().props.flash
            // success : konfirmasi aksi berhasil (hijau)
            // info    : informasi non-kritis, mis. duplikat realisasi (kuning/amber)
            'flash' => [
                'success' => $request->session()->get('success'),
                'info'    => $request->session()->get('info'),
            ],
            // Auth user — tersedia di semua halaman via usePage().props.auth
            'auth' => [
                'user' => $request->user()
                    ? [
                        'id'   => $request->user()->id,
                        'name' => $request->user()->name,
                        'email'=> $request->user()->email,
                    ]
                    : null,
            ],
        ];
    }
}
