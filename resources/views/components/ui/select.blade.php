<select {{ $attributes->merge(['class' => 'block w-full rounded-md border-0 bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-inset focus:ring-slate-900']) }}>
    {{ $slot }}
</select>
