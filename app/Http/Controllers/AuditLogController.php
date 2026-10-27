<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AuditLogController extends Controller
{
    public function index(Request $request)
    {
        $filterModul  = $request->input('filter_modul', '');
        $filterAksi   = $request->input('filter_aksi', '');
        $search       = $request->input('search', '');
        $perPage      = 50;

        $query = AuditLog::query()->orderBy('created_at', 'desc');

        if ($filterModul) {
            $query->where('modul', $filterModul);
        }

        if ($filterAksi) {
            $query->where('aksi', $filterAksi);
        }

        if ($search) {
            $query->where(function ($q) use ($search) {
                $like = '%' . strtolower($search) . '%';
                $q->whereRaw('LOWER(deskripsi) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(entitas_label) LIKE ?', [$like])
                  ->orWhereRaw('LOWER(petugas) LIKE ?', [$like]);
            });
        }

        $logs = $query->paginate($perPage)->withQueryString();

        return Inertia::render('AuditLog/Index', [
            'logs'         => $logs,
            'filters'      => [
                'filter_modul' => $filterModul,
                'filter_aksi'  => $filterAksi,
                'search'       => $search,
            ],
            'modulOptions' => array_map(
                fn($key, $label) => ['value' => $key, 'label' => $label],
                array_keys(AuditLog::MODUL_LABELS),
                AuditLog::MODUL_LABELS
            ),
            'aksiOptions'  => array_map(
                fn($key, $label) => ['value' => $key, 'label' => $label],
                array_keys(AuditLog::AKSI_LABELS),
                AuditLog::AKSI_LABELS
            ),
        ]);
    }
}
