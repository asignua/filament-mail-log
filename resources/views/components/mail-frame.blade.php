@props(['html', 'title' => '', 'height' => '65vh', 'remoteImages' => false])

{{--
    A mail body is untrusted (it can carry text typed by an anonymous visitor), so it never goes into the
    panel DOM. `sandbox=""` (EMPTY: no allow-scripts, no allow-same-origin, no allow-forms, no
    allow-top-navigation) plus the CSP meta tag that SafeFrame prepends. Blade escapes the attribute value.
--}}
<iframe
    title="{{ $title }}"
    srcdoc="{{ \Asignua\FilamentMailLog\Support\SafeFrame::document($html, (bool) $remoteImages) }}"
    sandbox=""
    referrerpolicy="no-referrer"
    loading="lazy"
    {{-- Inline on purpose: stock Filament CSS has no utility classes, so `w-full` & co. would leave a 300px frame. --}}
    {{ $attributes->merge(['class' => 'fi-mail-log-frame'])->merge(['style' => 'display: block; box-sizing: border-box; width: 100%; min-height: 24rem; height: '.$height.'; border: 1px solid rgb(128 128 128 / .25); border-radius: .75rem; background-color: #fff']) }}
></iframe>
