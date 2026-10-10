<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'BeautyBook')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/shell.js'])
</head>
<body class="{{ $ui['isApp'] ? 'bb-app' : 'bb-public' }}">
    <a class="bb-skip-link" href="#main-content">Đến nội dung chính</a>
    @include('layouts.chrome')
    <main id="main-content" class="bb-main">
        @if (session('status'))
            <div class="flash" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="bb-error-summary" role="alert"><strong>Vui lòng kiểm tra thông tin</strong><ul>@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @yield('content')
    </main>
    @unless($ui['isApp'])
        <footer class="bb-footer"><a class="brand" href="{{ route('home') }}">BeautyBook</a><p>Khám phá địa điểm. Chọn dịch vụ. Đặt lịch theo cách của bạn.</p><a href="{{ route('marketplace.index') }}">Khám phá dịch vụ</a><span>© {{ now()->year }} BeautyBook</span></footer>
    @endunless
</body>
</html>
