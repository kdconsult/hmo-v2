<x-filament-panels::page>
    @php
        $record = $this->getRecord();
        $showWarning = $record->isEditable() && $record->items()->doesntExist();
    @endphp

    @if($showWarning)
        <div class="rounded-lg bg-warning-50 p-4 text-sm text-warning-600 ring-1 ring-warning-600/10 dark:bg-warning-400/10 dark:text-warning-500 dark:ring-warning-400/20">
            No items added — this document cannot be confirmed until at least one item is added.
        </div>
    @endif

    {{ $this->content }}

    @php $relatedDocs = $this->getRelatedDocuments(); @endphp
    @if(!empty($relatedDocs))
        <x-filament::section heading="Related Documents" :compact="true">
            <div class="grid gap-2 text-sm">
                @foreach($relatedDocs as $group)
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span class="font-medium text-gray-700 dark:text-gray-300">{{ $group['label'] }}:</span>
                        @forelse($group['items'] as $item)
                            <a href="{{ $item['url'] }}" class="text-primary-600 hover:underline dark:text-primary-400">{{ $item['number'] }}</a>
                            <x-filament::badge :color="$item['color']" size="sm">{{ $item['status'] }}</x-filament::badge>
                            @unless($loop->last)<span class="text-gray-400">·</span>@endunless
                        @empty
                            <span class="text-gray-400">None</span>
                        @endforelse
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
