<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
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
                background-color: oklch(0.985 0.002 260);
            }

            html.dark {
                background-color: oklch(0.16 0.005 260);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|jetbrains-mono:400,500,600|instrument-serif:400,400i|instrument-sans:400,500,600&display=swap" rel="stylesheet" />

        {{-- Reverb config injected at runtime so deployments can change keys/host
             without rebuilding the Vite bundle. useWebSocket reads this. --}}
        @php
            $reverbApp = config('reverb.apps.apps.0', []);
            $reverbOptions = $reverbApp['options'] ?? [];
        @endphp
        <meta name="reverb-config" content="{{ json_encode([
            'key' => $reverbApp['key'] ?? null,
            'host' => $reverbOptions['host'] ?? parse_url((string) config('app.url'), PHP_URL_HOST),
            'port' => (int) ($reverbOptions['port'] ?? 443),
            'scheme' => $reverbOptions['scheme'] ?? 'https',
        ]) }}">

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
