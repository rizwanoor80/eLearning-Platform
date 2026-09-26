<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(app()->getLocale(), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: #fbf8f7;
            }

            html.dark {
                background-color: #1a0810;
            }
        </style>

        <link rel="icon" href="/brand/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="icon" href="/brand/favicon-16.png" type="image/png" sizes="16x16">
        <link rel="apple-touch-icon" href="/brand/apple-touch-icon-180.png">
        <meta property="og:image" content="{{ asset('brand/og-image-1200x630.png') }}">

        @fonts

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ \App\Support\Facades\Settings::get('site_name', config('app.name', 'Laravel')) }}</title>
        </x-inertia::head>

        {{-- Admin-trusted analytics snippets (CP1 site settings: head_scripts), rendered raw by design. --}}
        {!! \App\Support\Facades\Settings::get('head_scripts') !!}
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
