<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <script>
        // Apply the remembered theme before the stylesheet, so the page never flashes the other one.
        try {
            const theme = localStorage.getItem('theme');
            if (theme === 'light' || theme === 'dark') {
                document.documentElement.dataset.theme = theme;
            }
        } catch (e) {}
    </script>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    <script src="{{ asset('js/theme.js') }}?v={{ filemtime(public_path('js/theme.js')) }}" defer></script>
    @stack('scripts')
</head>
<body>
<header class="topbar">
    <div class="container topbar__inner">
        <a class="brand" href="{{ route('ranking') }}" aria-label="Clean Air Finder">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M12 2l8 5v10l-8 5-8-5V7z"/><path d="M4 7l8 5 8-5M12 12v10"/></svg>
            <span>Clean Air Finder</span>
        </a>
        <nav class="topbar__nav" aria-label="Main">
            <a href="{{ url('/docs/api') }}">API docs</a>
            <a href="{{ route('metrics') }}" @if (request()->routeIs('metrics')) aria-current="page" @endif>Metrics</a>
        </nav>
        <button type="button" class="icon-button" id="theme-toggle" aria-label="Switch to dark theme">
            <svg class="icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg>
            <svg class="icon-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
    </div>
</header>
@yield('content')
</body>
</html>
