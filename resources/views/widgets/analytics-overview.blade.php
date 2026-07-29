<div class="bg-white dark:bg-gray-850 rounded-xl ring ring-gray-200 dark:ring-x-0 dark:ring-b-0 dark:ring-gray-700/80 shadow-ui-md @container/widget min-h-54">
    <div class="flex h-full flex-col">

        <header class="flex items-center min-h-[49px] justify-between border-b border-gray-200 px-4.5 py-2 dark:border-gray-700">
            <a class="flex items-center gap-2 sm:gap-3" href="{{ $analyticsUrl }}">
                <svg class="size-5 shrink-0 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/>
                </svg>
                <span>Analytiques</span>
            </a>
            <a href="{{ $analyticsUrl }}"
               class="inline-flex items-center justify-center px-3 h-8 text-[0.8125rem] leading-tight font-medium rounded-lg border border-gray-300 bg-linear-to-b from-white to-gray-50 hover:to-gray-100 text-gray-900 shadow-ui-sm no-underline dark:from-gray-850 dark:to-gray-900 dark:border-gray-700/80 dark:text-gray-300 dark:shadow-ui-md">
                Voir tout
            </a>
        </header>

        <div class="flex flex-col gap-4 px-4.5 py-2">
            <p class="text-xs font-semibold uppercase tracking-widest text-gray-400">7 derniers jours</p>
            <div class="grid grid-cols-3 gap-3">
                <div class="bg-gray-50 border border-gray-100 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-gray-900 leading-tight">{{ number_format($todayVisits) }}</div>
                    <div class="text-xs text-gray-400 mt-1.5 font-medium">Aujourd'hui</div>
                </div>
                <div class="bg-gray-50 border border-gray-100 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-gray-900 leading-tight">{{ number_format($totalVisits7d) }}</div>
                    <div class="text-xs text-gray-400 mt-1.5 font-medium">Pages vues</div>
                </div>
                <div class="bg-gray-50 border border-gray-100 rounded-lg p-4 text-center">
                    <div class="text-3xl font-bold text-gray-900 leading-tight">{{ number_format($uniqueVisitors7d) }}</div>
                    <div class="text-xs text-gray-400 mt-1.5 font-medium">Visiteurs uniques</div>
                </div>
            </div>
        </div>

    </div>
</div>
