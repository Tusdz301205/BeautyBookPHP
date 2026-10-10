<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="beautybook-base" content="{{ $beautybookBase ?? '/beautybook' }}">
    <title>BeautyBook</title>
    <link rel="icon" type="image/svg+xml" href="/beautybook-favicon.svg">
    @php
        $manifest = json_decode(file_get_contents(public_path('beautybook-build/manifest.json')), true);
        $entry = $manifest['resources/beautybook/src/main.jsx'];
    @endphp
    @foreach ($entry['css'] ?? [] as $css)
        <link rel="stylesheet" href="{{ asset('beautybook-build/'.$css) }}">
    @endforeach
    @foreach ($entry['imports'] ?? [] as $import)
        <link rel="modulepreload" href="{{ asset('beautybook-build/'.$manifest[$import]['file']) }}">
    @endforeach
</head>
<body>
    <div id="root"></div>
    <script type="module" src="{{ asset('beautybook-build/'.$entry['file']) }}"></script>
</body>
</html>
