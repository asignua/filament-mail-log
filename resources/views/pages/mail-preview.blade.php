<x-filament-panels::page>
    @php($html = $this->html())

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="grid gap-1 text-sm font-medium">
            <span>{{ __('filament-mail-log::filament-mail-log.preview.message') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="preview">
                    @foreach ($this->previewOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>

        <label class="grid gap-1 text-sm font-medium">
            <span>{{ __('filament-mail-log::filament-mail-log.preview.language') }}</span>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="locale">
                    @foreach ($this->localeOptions() as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </label>
    </div>

    @if ($html !== null)
        <x-filament-mail-log::mail-frame :html="$html" :title="__('filament-mail-log::filament-mail-log.preview.title')" height="75vh" />
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('filament-mail-log::filament-mail-log.preview.unavailable') }}</p>
    @endif
</x-filament-panels::page>
