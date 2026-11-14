<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Judul & pratinjau link (WhatsApp dll. tidak menjalankan JavaScript) --}}
    <title inertia>Dashboard PRB</title>
    <meta name="description" content="Dashboard PRB - Monitoring Kepatuhan Pengambilan Obat Pasien Program Rujuk Balik">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Dashboard PRB">
    <meta property="og:title" content="Dashboard PRB">
    <meta property="og:description" content="Monitoring Kepatuhan Pengambilan Obat Pasien Program Rujuk Balik">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="bg-slate-50 text-slate-900 font-sans">
    @inertia
</body>
</html>
