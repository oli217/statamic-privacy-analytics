<template>
    <div class="ea-container">

        <!-- Header with Controls -->
        <div class="ea-header">
            <div class="ea-controls">
                <select v-model="dateRange" @change="onDateRangeChange" class="ea-select">
                    <option value="24hours">{{ t('last_24_hours') }}</option>
                    <option value="7days">{{ t('last_7_days') }}</option>
                    <option value="30days">{{ t('last_30_days') }}</option>
                    <option value="custom">{{ t('custom_range') }}</option>
                </select>
                <div v-if="dateRange === 'custom'" class="ea-controls">
                    <input type="date" v-model="startDate" @change="fetchData" class="ea-input">
                    <span class="ea-text-lg">{{ t('date_to') }}</span>
                    <input type="date" v-model="endDate" @change="fetchData" class="ea-input">
                </div>
            </div>
            <div class="ea-controls">
                <button @click="fetchData" class="ea-btn ea-btn-primary">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                    </svg>
                    {{ t('refresh_data') }}
                </button>
                <button @click="exportData" class="ea-btn ea-btn-success">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    {{ t('export_data') }}
                </button>
                <button @click="showSettings = !showSettings" class="ea-btn">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                    </svg>
                    {{ t('settings') }}
                </button>
            </div>
        </div>

        <!-- Settings Panel -->
        <div v-if="showSettings" class="ea-card">
            <h3 class="ea-text-xl ea-mb-4">{{ t('analytics_settings') }}</h3>
            <div class="ea-grid ea-grid-cols-2 ea-gap-8">
                <div class="ea-space-y-4">
                    <h4 class="ea-text-lg ea-font-semibold">{{ t('geolocation_stats') }}</h4>
                    <div class="ea-space-y-2">
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('total_lookups') }}</span>
                            <span class="ea-font-medium">{{ geoStats.total_lookups.toLocaleString() }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('success_rate') }}</span>
                            <span class="ea-font-medium">{{ geoSuccessRate }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('unique_ips') }}</span>
                            <span class="ea-font-medium">{{ geoStats.unique_ips.length.toLocaleString() }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('last_lookup') }}</span>
                            <span class="ea-font-medium">{{ geoLastLookup }}</span>
                        </div>
                    </div>
                    <button @click="clearGeoCache" :disabled="clearingCache" class="ea-btn ea-btn-primary ea-mt-4">
                        {{ clearingCache ? t('clearing') : t('clear_geo_cache') }}
                    </button>
                </div>
                <div class="ea-space-y-4">
                    <h4 class="ea-text-lg ea-font-semibold">{{ t('current_configuration') }}</h4>
                    <div class="ea-space-y-2">
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('geo_provider') }}</span>
                            <span class="ea-font-medium">{{ config.geoProvider }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('cache_duration') }}</span>
                            <span class="ea-font-medium">{{ config.cacheDuration }} {{ t('minutes') }}</span>
                        </div>
                        <div v-if="config.geoProvider === 'ip-api'" class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('rate_limit') }}</span>
                            <span class="ea-font-medium">{{ config.rateLimit }} {{ t('requests_per_minute') }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('processing') }}</span>
                            <span class="ea-font-medium">{{ t('every_x_minutes', { count: config.processingFrequency }) }}</span>
                        </div>
                        <div class="ea-flex ea-justify-between">
                            <span class="ea-text-secondary">{{ t('dashboard_refresh') }}</span>
                            <span class="ea-font-medium">{{ config.refreshInterval }} {{ t('seconds') }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- MaxMind credentials warning -->
        <div v-if="config.geoWarning" class="ea-card" style="border-left: 4px solid #f59e0b; background: #fffbeb;">
            <p class="ea-font-semibold" style="color: #92400e;">⚠ Géolocalisation MaxMind non configurée</p>
            <p class="ea-text-secondary" style="margin-top: 0.5rem;">
                Le provider <code>maxmind</code> est actif mais les identifiants
                <code>MAXMIND_ACCOUNT_ID</code> / <code>MAXMIND_LICENSE_KEY</code> sont absents.
                Les widgets pays et villes resteront vides.
                <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" style="color: #2563eb;">
                    Créer un compte gratuit MaxMind
                </a>.
            </p>
            <p class="ea-text-secondary" style="margin-top: 0.25rem;">
                Pour désactiver proprement la géolocalisation et supprimer ce message,
                définissez <code>ANALYTICS_GEO_PROVIDER=disabled</code> dans votre <code>.env</code>
                (les widgets géographiques seront alors masqués).
            </p>
        </div>

        <!-- Quick Stats Overview -->
        <div class="ea-grid ea-grid-cols-4">
            <div class="ea-card">
                <h3 class="ea-font-semibold">{{ t('total_visits') }}</h3>
                <p class="ea-text-lg">{{ overview.totalVisits.toLocaleString() }}</p>
                <p :class="['ea-text-secondary', 'text-sm', comparisonClass(overview.comparisons.total_visits, true)]">
                    {{ comparisonText(overview.comparisons.total_visits) }}
                </p>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-semibold">{{ t('unique_visitors') }}</h3>
                <p class="ea-text-lg">{{ overview.uniqueVisitors.toLocaleString() }}</p>
                <p :class="['ea-text-secondary', 'text-sm', comparisonClass(overview.comparisons.unique_visitors, true)]">
                    {{ comparisonText(overview.comparisons.unique_visitors) }}
                </p>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-semibold">{{ t('engagement') }}</h3>
                <p class="ea-text-lg">{{ formatDuration(overview.avgTimeOnSite) }}</p>
                <p class="ea-text-secondary">{{ t('avg_time_on_site') }}</p>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-semibold">{{ t('bounce_rate') }}</h3>
                <p class="ea-text-lg">{{ formatPercent(overview.bounceRate) }}</p>
                <p :class="['ea-text-secondary', 'text-sm', comparisonClass(overview.comparisons.bounce_rate, false)]">
                    {{ comparisonText(overview.comparisons.bounce_rate) }}
                </p>
            </div>
        </div>

        <!-- Realtime widget -->
        <RealTimeWidget :totals="realtime.totals" :breakdowns="realtime.breakdowns" :t="t" />

        <!-- Visitor Engagement Metrics -->
        <div class="ea-grid ea-grid-cols-2">
            <div class="ea-card">
                <h3 class="ea-font-bold">{{ t('visit_frequency') }}</h3>
                <div class="ea-grid ea-grid-cols-2">
                    <div>
                        <p class="ea-text-secondary">{{ t('new_visitors') }}</p>
                        <p class="ea-text-lg">{{ engagement.newVisitors.toLocaleString() }}</p>
                    </div>
                    <div>
                        <p class="ea-text-secondary">{{ t('returning_visitors') }}</p>
                        <p class="ea-text-lg">{{ engagement.returningVisitors.toLocaleString() }}</p>
                    </div>
                    <div>
                        <p class="ea-text-secondary">{{ t('pages_per_session') }}</p>
                        <p class="ea-text-lg">{{ engagement.pagesPerSession.toFixed(1) }}</p>
                    </div>
                    <div>
                        <p class="ea-text-secondary">{{ t('avg_session_duration') }}</p>
                        <p class="ea-text-lg">{{ formatDuration(engagement.avgSessionDuration) }}</p>
                    </div>
                </div>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-bold">{{ t('page_views_over_time') }}</h3>
                <div class="ea-chart-wrapper">
                    <canvas ref="pageViewsChartEl"></canvas>
                </div>
            </div>
        </div>

        <!-- New vs Returning trend -->
        <NewReturningTrendWidget :data="newVsReturning" :t="t" />

        <!-- Geographic & Technical Insights -->
        <div class="ea-grid ea-grid-cols-1">
            <div v-if="!geoDisabled" class="ea-card">
                <h3 class="ea-font-bold">{{ t('top_countries') }}</h3>
                <div class="ea-chart-wrapper">
                    <canvas ref="countryChartEl"></canvas>
                </div>
                <div>
                    <table class="ea-table">
                        <thead>
                            <tr>
                                <th>{{ t('country') }}</th>
                                <th class="ea-text-right">{{ t('visits') }}</th>
                                <th class="ea-text-right">{{ t('percent_of_total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="country in countryStats" :key="country.dimension_value">
                                <td>{{ country.dimension_value }}</td>
                                <td class="ea-text-right">{{ country.total.toLocaleString() }}</td>
                                <td class="ea-text-right">{{ countryPercent(country.total) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-bold">{{ t('device_types') }}</h3>
                <div class="ea-chart-wrapper">
                    <canvas ref="deviceChartEl"></canvas>
                </div>
            </div>
            <div class="ea-card">
                <h3 class="ea-font-bold">{{ t('browser_usage') }}</h3>
                <div class="ea-chart-wrapper">
                    <canvas ref="browserChartEl"></canvas>
                </div>
            </div>
        </div>

        <!-- Cities -->
        <CityWidget v-if="!geoDisabled" :data="cityStats" :t="t" />

        <!-- Referrer sources -->
        <ReferrerWidget :data="referrerStats" :t="t" />

        <!-- Platforms -->
        <PlatformWidget :data="platformStats" :t="t" />

        <!-- Heatmap -->
        <HeatmapWidget :data="heatmapData" :t="t" />

        <!-- Session depth -->
        <SessionDepthWidget :data="sessionDepth" :t="t" />

        <!-- Page Performance -->
        <div class="ea-card">
            <h3 class="ea-font-bold">{{ t('page_performance') }}</h3>
            <div class="overflow-x-auto">
                <table class="ea-table">
                    <thead>
                        <tr>
                            <th>{{ t('page_url') }}</th>
                            <th>{{ t('views') }}</th>
                            <th>{{ t('unique_views') }}</th>
                            <th>{{ t('avg_time') }}</th>
                            <th>{{ t('bounce_rate') }}</th>
                            <th>{{ t('exit_rate') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="topPages.length === 0">
                            <td colspan="6" class="ea-text-center">{{ t('loading_data') }}</td>
                        </tr>
                        <tr v-for="page in topPages" :key="page.page_url">
                            <td class="max-w-md truncate">{{ page.page_url }}</td>
                            <td>{{ page.views.toLocaleString() }}</td>
                            <td>{{ page.unique_views.toLocaleString() }}</td>
                            <td>{{ formatDuration(page.avg_time) }}</td>
                            <td>{{ formatPercent(page.bounce_rate) }}</td>
                            <td>{{ formatPercent(page.exit_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- User Flow -->
        <div class="ea-card">
            <h3 class="ea-font-bold">{{ t('user_flow') }}</h3>
            <div class="ea-grid ea-grid-cols-3">
                <div>
                    <h4 class="ea-font-semibold">{{ t('top_entry_pages') }}</h4>
                    <p class="ea-text-muted">{{ t('entry_points') }}</p>
                    <div v-for="page in userFlow.entry_pages" :key="page.page_url" class="flex justify-between items-center">
                        <span class="truncate">{{ page.page_url }}</span>
                        <span class="text-gray-500">{{ page.count.toLocaleString() }}</span>
                    </div>
                </div>
                <div>
                    <h4 class="ea-font-semibold">{{ t('most_engaged_pages') }}</h4>
                    <p class="ea-text-muted">{{ t('highest_engagement') }}</p>
                    <div v-for="page in userFlow.engaged_pages" :key="page.url" class="flex justify-between items-center">
                        <span class="truncate">{{ page.url }}</span>
                        <span class="text-gray-500">{{ formatDuration(page.avg_time) }}</span>
                    </div>
                </div>
                <div>
                    <h4 class="ea-font-semibold">{{ t('top_exit_pages') }}</h4>
                    <p class="ea-text-muted">{{ t('exit_points') }}</p>
                    <div v-for="page in userFlow.exit_pages" :key="page.url" class="flex justify-between items-center">
                        <span class="truncate">{{ page.url }}</span>
                        <span class="text-gray-500">{{ formatPercent(page.exit_rate) }}</span>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue'
import { Chart } from 'chart.js/auto'
import RealTimeWidget from './widgets/RealTimeWidget.vue'
import NewReturningTrendWidget from './widgets/NewReturningTrendWidget.vue'
import CityWidget from './widgets/CityWidget.vue'
import ReferrerWidget from './widgets/ReferrerWidget.vue'
import PlatformWidget from './widgets/PlatformWidget.vue'
import HeatmapWidget from './widgets/HeatmapWidget.vue'
import SessionDepthWidget from './widgets/SessionDepthWidget.vue'

const props = defineProps({
    config: {
        type: Object,
        required: true,
    },
    translations: {
        type: Object,
        default: () => ({}),
    },
})

// ── i18n helper ───────────────────────────────────────────────────────────────

function t(key, replacements = {}) {
    let string = props.translations[key] ?? key
    for (const [placeholder, value] of Object.entries(replacements)) {
        string = string.replace(`:${placeholder}`, value)
    }
    return string
}

// UI state
const dateRange  = ref('7days')
const startDate  = ref('')
const endDate    = ref('')
const showSettings  = ref(false)
const clearingCache = ref(false)

// Data state
const overview = reactive({
    totalVisits:    0,
    uniqueVisitors: 0,
    avgTimeOnSite:  0,
    bounceRate:     0,
    comparisons: { total_visits: 0, unique_visitors: 0, bounce_rate: 0 },
})

const engagement = reactive({
    newVisitors:        0,
    returningVisitors:  0,
    pagesPerSession:    0,
    avgSessionDuration: 0,
})

const geoStats = reactive({
    total_lookups:      0,
    successful_lookups: 0,
    unique_ips:         [],
    last_lookup:        null,
})

const realtime = reactive({
    totals:     {},
    breakdowns: {},
})

const countryStats    = ref([])
const topPages        = ref([])
const userFlow        = reactive({ entry_pages: [], engaged_pages: [], exit_pages: [] })
const referrerStats   = ref(null)
const platformStats   = ref([])
const cityStats       = ref([])
const heatmapData     = ref([])
const newVsReturning  = ref([])
const sessionDepth    = ref([])

// Chart canvas refs
const pageViewsChartEl = ref(null)
const deviceChartEl    = ref(null)
const countryChartEl   = ref(null)
const browserChartEl   = ref(null)

// Chart instances
let pageViewsChart = null
let deviceChart    = null
let countryChart   = null
let browserChart   = null

// Timers
let refreshTimer  = null
let realtimeTimer = null

// ── Computed ──────────────────────────────────────────────────────────────────

const geoDisabled = computed(() => props.config.geoProvider === 'disabled')

const geoSuccessRate = computed(() => {
    if (!geoStats.total_lookups) return '0%'
    return ((geoStats.successful_lookups / geoStats.total_lookups) * 100).toFixed(1) + '%'
})

const geoLastLookup = computed(() =>
    geoStats.last_lookup ? new Date(geoStats.last_lookup).toLocaleString() : t('never')
)

// ── Helpers ───────────────────────────────────────────────────────────────────

function formatDuration(seconds) {
    if (!seconds || seconds < 0) return '0:00'
    const m = Math.floor(seconds / 60)
    const s = Math.floor(seconds % 60)
    return `${m}:${s.toString().padStart(2, '0')}`
}

function formatPercent(value) {
    return `${(value * 100).toFixed(1)}%`
}

function countryPercent(total) {
    return `${((total / (overview.totalVisits || 1)) * 100).toFixed(1)}%`
}

function comparisonClass(value, positiveGood) {
    const isPositive = value >= 0
    return (isPositive && positiveGood) || (!isPositive && !positiveGood)
        ? 'text-green-600'
        : 'text-red-600'
}

function comparisonText(value) {
    return `${value >= 0 ? '+' : ''}${value}% ${t('vs_previous_period')}`
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
}

// ── Charts ────────────────────────────────────────────────────────────────────

function initCharts() {
    pageViewsChart = new Chart(pageViewsChartEl.value, {
        type: 'line',
        data: {
            labels: [],
            datasets: [
                { label: t('total_views'),  data: [], borderColor: 'rgb(59, 130, 246)', tension: 0.1 },
                { label: t('unique_views'), data: [], borderColor: 'rgb(16, 185, 129)', tension: 0.1 },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { intersect: false, mode: 'index' },
        },
    })

    deviceChart = new Chart(deviceChartEl.value, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{ data: [], backgroundColor: ['rgb(59, 130, 246)', 'rgb(16, 185, 129)', 'rgb(251, 191, 36)'] }],
        },
        options: { responsive: true, maintainAspectRatio: false },
    })

    if (countryChartEl.value) {
        countryChart = new Chart(countryChartEl.value, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [{ label: t('visits'), data: [], backgroundColor: 'rgb(59, 130, 246)' }],
            },
            options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y' },
        })
    }

    browserChart = new Chart(browserChartEl.value, {
        type: 'doughnut',
        data: {
            labels: [],
            datasets: [{
                data: [],
                backgroundColor: [
                    'rgb(59, 130, 246)', 'rgb(16, 185, 129)', 'rgb(251, 191, 36)',
                    'rgb(236, 72, 153)', 'rgb(124, 58, 237)',
                ],
            }],
        },
        options: { responsive: true, maintainAspectRatio: false },
    })
}

// ── Data fetching ─────────────────────────────────────────────────────────────

async function fetchData() {
    try {
        const params = new URLSearchParams({ range: dateRange.value })
        if (dateRange.value === 'custom' && startDate.value && endDate.value) {
            params.append('start_date', startDate.value)
            params.append('end_date', endDate.value)
        }

        const response = await fetch(`${props.config.routes.data}?${params}`)
        if (!response.ok) throw new Error('Failed to fetch analytics data')

        const data = await response.json()
        updateState(data)
        await fetchGeoStats()
    } catch (error) {
        console.error('Error fetching analytics data:', error)
    }
}

async function fetchRealtime() {
    try {
        const response = await fetch(props.config.routes.realtime)
        if (!response.ok) throw new Error('Failed to fetch realtime data')
        const data = await response.json()
        realtime.totals     = data.totals     ?? {}
        realtime.breakdowns = data.breakdowns ?? {}
    } catch (error) {
        console.error('Error fetching realtime data:', error)
    }
}

function updateState(data) {
    if (data.overview) {
        overview.totalVisits    = data.overview.total_visits
        overview.uniqueVisitors = data.overview.unique_visitors
        overview.avgTimeOnSite  = data.overview.avg_time_on_site
        overview.bounceRate     = data.overview.bounce_rate
        if (data.overview.comparisons) {
            Object.assign(overview.comparisons, data.overview.comparisons)
        }
    }

    if (data.engagement) {
        engagement.newVisitors        = data.engagement.new_visitors
        engagement.returningVisitors  = data.engagement.returning_visitors
        engagement.pagesPerSession    = data.engagement.pages_per_session
        engagement.avgSessionDuration = data.engagement.avg_session_duration
    }

    if (data.top_pages)        topPages.value       = data.top_pages
    if (data.country_stats)    countryStats.value   = data.country_stats
    if (data.referrer_stats)   referrerStats.value  = data.referrer_stats
    if (data.platform_stats)   platformStats.value  = data.platform_stats
    if (data.city_stats)       cityStats.value      = data.city_stats
    if (data.heatmap_data)     heatmapData.value    = data.heatmap_data
    if (data.new_vs_returning) newVsReturning.value = data.new_vs_returning
    if (data.session_depth)    sessionDepth.value   = data.session_depth

    if (data.user_flow) {
        userFlow.entry_pages   = data.user_flow.entry_pages   ?? []
        userFlow.engaged_pages = data.user_flow.engaged_pages ?? []
        userFlow.exit_pages    = data.user_flow.exit_pages    ?? []
    }

    if (data.page_views) {
        pageViewsChart.data.labels           = data.page_views.map(i => i.date)
        pageViewsChart.data.datasets[0].data = data.page_views.map(i => i.total_views)
        pageViewsChart.data.datasets[1].data = data.page_views.map(i => i.unique_views)
        pageViewsChart.update()
    }
    if (data.device_stats) {
        deviceChart.data.labels              = data.device_stats.map(i => i.dimension_value)
        deviceChart.data.datasets[0].data    = data.device_stats.map(i => i.total)
        deviceChart.update()
    }
    if (data.country_stats && countryChart) {
        countryChart.data.labels             = data.country_stats.map(i => i.dimension_value)
        countryChart.data.datasets[0].data   = data.country_stats.map(i => i.total)
        countryChart.update()
    }
    if (data.browser_stats) {
        browserChart.data.labels             = data.browser_stats.map(i => i.dimension_value)
        browserChart.data.datasets[0].data   = data.browser_stats.map(i => i.total)
        browserChart.update()
    }
}

async function fetchGeoStats() {
    try {
        const response = await fetch(props.config.routes.geoStats)
        if (!response.ok) throw new Error('Failed to fetch geolocation stats')
        const data = await response.json()
        Object.assign(geoStats, data)
    } catch (error) {
        console.error('Error fetching geolocation stats:', error)
    }
}

// ── Actions ───────────────────────────────────────────────────────────────────

function onDateRangeChange() {
    if (dateRange.value !== 'custom') fetchData()
}

async function clearGeoCache() {
    clearingCache.value = true
    try {
        const response = await fetch(props.config.routes.clearCache, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken(),
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            },
        })
        if (!response.ok) throw new Error('Failed to clear cache')
        await fetchGeoStats()
    } catch (error) {
        console.error('Error clearing geolocation cache:', error)
    } finally {
        setTimeout(() => { clearingCache.value = false }, 2000)
    }
}

function exportData() {
    const params = new URLSearchParams({ range: dateRange.value })
    if (dateRange.value === 'custom' && startDate.value && endDate.value) {
        params.append('start_date', startDate.value)
        params.append('end_date', endDate.value)
    }
    window.location.href = `${props.config.routes.export}?${params}`
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(() => {
    initCharts()
    fetchData()
    fetchRealtime()
    refreshTimer  = setInterval(fetchData,     props.config.refreshInterval * 1000)
    realtimeTimer = setInterval(fetchRealtime, 30000)
})

onUnmounted(() => {
    if (refreshTimer)  clearInterval(refreshTimer)
    if (realtimeTimer) clearInterval(realtimeTimer)
    pageViewsChart?.destroy()
    deviceChart?.destroy()
    countryChart?.destroy()
    browserChart?.destroy()
})
</script>
