<?php

/*
|--------------------------------------------------------------------------
| Pesan Validasi (Bahasa Indonesia)
|--------------------------------------------------------------------------
|
| Hanya aturan yang dipakai aplikasi ini. Aturan lain jatuh ke bahasa
| cadangan (APP_FALLBACK_LOCALE).
|
*/

return [

    'array'            => ':Attribute harus berupa daftar.',
    'before_or_equal'  => ':Attribute tidak boleh melewati :date.',
    'confirmed'        => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Password salah.',
    'date'             => ':Attribute bukan tanggal yang valid.',
    'different'        => ':Attribute harus berbeda dari :other.',
    'email'            => ':Attribute harus berupa alamat email yang valid.',
    'exists'           => ':Attribute yang dipilih tidak ditemukan.',
    'in'               => ':Attribute yang dipilih tidak valid.',
    'integer'          => ':Attribute harus berupa angka bulat.',
    'max'              => [
        'array'   => ':Attribute maksimal berisi :max item.',
        'numeric' => ':Attribute maksimal :max.',
        'string'  => ':Attribute maksimal :max karakter.',
    ],
    'min'              => [
        'array'   => ':Attribute minimal berisi :min item.',
        'numeric' => ':Attribute minimal :min.',
        'string'  => ':Attribute minimal :min karakter.',
    ],
    'password'         => [
        'letters'       => ':Attribute harus mengandung minimal satu huruf.',
        'mixed'         => ':Attribute harus mengandung huruf besar dan huruf kecil.',
        'numbers'       => ':Attribute harus mengandung minimal satu angka.',
        'symbols'       => ':Attribute harus mengandung minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor di internet. Gunakan password lain.',
    ],
    'regex'            => 'Format :attribute tidak valid.',
    'required'         => ':Attribute wajib diisi.',
    'required_if'      => ':Attribute wajib diisi jika :other adalah :value.',
    'string'           => ':Attribute harus berupa teks.',
    'unique'           => ':Attribute sudah terdaftar.',
    'uuid'             => ':Attribute tidak valid.',

    /*
    |--------------------------------------------------------------------------
    | Nama Atribut
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'diagnosis_prb' => [
            'max' => 'Satu data PRB hanya untuk satu diagnosis. Tambahkan diagnosis lain lewat tombol Tambah.',
        ],
        'fktp_asal' => [
            'in' => 'FKTP harus salah satu dari 8 FKTP penelitian.',
        ],
        'diagnosis_prb.*' => [
            'in' => 'Diagnosis harus salah satu dari 9 diagnosis PRB.',
        ],
        'no_whatsapp' => [
            'regex' => 'Format No. WhatsApp tidak valid. Contoh: 081234567890.',
        ],
    ],

    'attributes' => [
        'alasan_pasif'                 => 'alasan pasif',
        'catatan'                      => 'catatan',
        'diagnosis_prb'                => 'diagnosis PRB',
        'diagnosis_prb.*'              => 'diagnosis PRB',
        'email'                        => 'email',
        'fktp_asal'                    => 'FKTP asal',
        'ids'                          => 'pasien yang dipilih',
        'ids.*'                        => 'pasien yang dipilih',
        'no_whatsapp'                  => 'No. WhatsApp',
        'noka'                         => 'NOKA',
        'password'                     => 'password baru',
        'password_lama'                => 'password lama',
        'riwayat_id'                   => 'riwayat',
        'status'                       => 'status',
        'tanggal_jadwal_berikutnya'    => 'tanggal jadwal',
        'tanggal_mulai_prb'            => 'tanggal mulai PRB',
        'tanggal_pengambilan_terakhir' => 'tanggal pengambilan terakhir',
        'tanggal_realisasi'            => 'tanggal pengambilan',
        'tindakan'                     => 'hasil tindakan',
    ],

    // Nilai yang tampil di pesan (mis. ":date" untuk before_or_equal:today).
    'values' => [
        'tanggal_pengambilan_terakhir' => ['today' => 'hari ini'],
        'tanggal_realisasi'            => ['today' => 'hari ini'],
    ],

];
