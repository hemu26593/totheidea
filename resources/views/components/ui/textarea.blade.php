@props(['rows' => 4])

<textarea rows="{{ $rows }}"
          {{ $attributes->merge(['class' => 'block w-full rounded-md border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-inset ring-slate-300 placeholder:text-slate-400 focus:ring-2 focus:ring-inset focus:ring-slate-900 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500 disabled:ring-slate-200']) }}>{{ $slot }}</textarea>
