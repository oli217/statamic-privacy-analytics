import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    KEYS,
    generateUuid,
    getOrCreateVisitorId,
    getOrCreateSessionId,
    isNewVisitor,
    todayString,
    currentHourString,
    isNewDayVisit,
    isNewHourVisit,
    isNewPageVisit,
    recordVisit,
    hasConsent,
    buildBeaconParams,
    sendBeacon,
    init,
} from './tracker.js';

// jsdom fournit localStorage/sessionStorage — vidés avant chaque test
beforeEach(() => {
    localStorage.clear();
    sessionStorage.clear();
});

// ── 1. generateUuid ───────────────────────────────────────────────────────────

describe('generateUuid', () => {
    it('retourne une chaîne au format UUID v4', () => {
        expect(generateUuid()).toMatch(
            /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/
        );
    });

    it('génère des valeurs uniques à chaque appel', () => {
        expect(generateUuid()).not.toBe(generateUuid());
    });
});

// ── 2. Identifiants visiteur ──────────────────────────────────────────────────

describe('isNewVisitor', () => {
    it('retourne true si aucun visitor_id en localStorage', () => {
        expect(isNewVisitor()).toBe(true);
    });

    it('retourne false si visitor_id existe déjà', () => {
        localStorage.setItem(KEYS.vid, 'existing-id');
        expect(isNewVisitor()).toBe(false);
    });
});

describe('getOrCreateVisitorId', () => {
    it('crée un UUID et le persiste en localStorage', () => {
        const id = getOrCreateVisitorId();
        expect(id).toMatch(/^[0-9a-f]{8}-/);
        expect(localStorage.getItem(KEYS.vid)).toBe(id);
    });

    it('retourne le visitor_id existant sans en créer un nouveau', () => {
        localStorage.setItem(KEYS.vid, 'mon-id');
        expect(getOrCreateVisitorId()).toBe('mon-id');
    });

    it('retourne la même valeur sur appels successifs', () => {
        expect(getOrCreateVisitorId()).toBe(getOrCreateVisitorId());
    });
});

describe('getOrCreateSessionId', () => {
    it('crée un UUID et le persiste en sessionStorage', () => {
        const id = getOrCreateSessionId();
        expect(id).toMatch(/^[0-9a-f]{8}-/);
        expect(sessionStorage.getItem(KEYS.sid)).toBe(id);
    });

    it('retourne le session_id existant', () => {
        sessionStorage.setItem(KEYS.sid, 'session-abc');
        expect(getOrCreateSessionId()).toBe('session-abc');
    });
});

// ── 3. Helpers date/heure ─────────────────────────────────────────────────────

describe('todayString', () => {
    afterEach(() => vi.useRealTimers());

    it('retourne la date locale au format YYYY-MM-DD', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T10:30:00'));
        expect(todayString()).toBe('2024-06-15');
    });
});

describe('currentHourString', () => {
    afterEach(() => vi.useRealTimers());

    it('retourne la date+heure locale au format YYYY-MM-DD HH', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T09:45:00'));
        expect(currentHourString()).toBe('2024-06-15 09');
    });
});

// ── 4. Flags de visite ────────────────────────────────────────────────────────

describe('isNewDayVisit', () => {
    afterEach(() => vi.useRealTimers());

    it('retourne true si aucune date enregistrée', () => {
        expect(isNewDayVisit()).toBe(true);
    });

    it('retourne false si la dernière visite était aujourd\'hui', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T10:30:00'));
        localStorage.setItem(KEYS.ld, '2024-06-15');
        expect(isNewDayVisit()).toBe(false);
    });

    it('retourne true si la dernière visite était hier', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T10:30:00'));
        localStorage.setItem(KEYS.ld, '2024-06-14');
        expect(isNewDayVisit()).toBe(true);
    });
});

describe('isNewHourVisit', () => {
    afterEach(() => vi.useRealTimers());

    it('retourne true si aucune heure enregistrée', () => {
        expect(isNewHourVisit()).toBe(true);
    });

    it('retourne false si la dernière visite est dans la même heure', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T10:45:00'));
        localStorage.setItem(KEYS.lh, '2024-06-15 10');
        expect(isNewHourVisit()).toBe(false);
    });

    it('retourne true si la dernière visite était à l\'heure précédente', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T11:05:00'));
        localStorage.setItem(KEYS.lh, '2024-06-15 10');
        expect(isNewHourVisit()).toBe(true);
    });
});

describe('isNewPageVisit', () => {
    it('retourne true si la page n\'a jamais été visitée', () => {
        expect(isNewPageVisit('/contact')).toBe(true);
    });

    it('retourne false si la page est dans visited_pages', () => {
        sessionStorage.setItem(KEYS.vp, JSON.stringify(['/contact', '/about']));
        expect(isNewPageVisit('/contact')).toBe(false);
    });

    it('retourne true pour une page non encore visitée', () => {
        sessionStorage.setItem(KEYS.vp, JSON.stringify(['/contact']));
        expect(isNewPageVisit('/about')).toBe(true);
    });

    it('retourne true si le JSON stocké est corrompu', () => {
        sessionStorage.setItem(KEYS.vp, 'invalide{json');
        expect(isNewPageVisit('/page')).toBe(true);
    });
});

// ── 5. recordVisit ────────────────────────────────────────────────────────────

describe('recordVisit', () => {
    afterEach(() => vi.useRealTimers());

    it('enregistre la date et l\'heure locale de la visite', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2024-06-15T10:30:00'));

        recordVisit('/page');

        expect(localStorage.getItem(KEYS.ld)).toBe('2024-06-15');
        expect(localStorage.getItem(KEYS.lh)).toBe('2024-06-15 10');
    });

    it('ajoute la page à visited_pages', () => {
        recordVisit('/contact');
        expect(JSON.parse(sessionStorage.getItem(KEYS.vp))).toContain('/contact');
    });

    it('ne duplique pas une page déjà visitée', () => {
        recordVisit('/contact');
        recordVisit('/contact');
        const pages = JSON.parse(sessionStorage.getItem(KEYS.vp));
        expect(pages.filter((p) => p === '/contact')).toHaveLength(1);
    });

    it('limite visited_pages à 20 entrées', () => {
        for (let i = 1; i <= 22; i++) {
            recordVisit(`/page-${i}`);
        }
        const pages = JSON.parse(sessionStorage.getItem(KEYS.vp));
        expect(pages).toHaveLength(20);
        expect(pages).toContain('/page-22');  // la plus récente est présente
        expect(pages).not.toContain('/page-1'); // les plus anciennes sont éjectées
        expect(pages).not.toContain('/page-2');
    });
});

// ── 6. hasConsent ─────────────────────────────────────────────────────────────

describe('hasConsent', () => {
    it('retourne true si le consentement n\'est pas requis', () => {
        expect(hasConsent(false)).toBe(true);
    });

    it('retourne false si requis et aucune valeur stockée', () => {
        expect(hasConsent(true)).toBe(false);
    });

    it('retourne false si consentement refusé', () => {
        localStorage.setItem(KEYS.consent, 'declined');
        expect(hasConsent(true)).toBe(false);
    });

    it('retourne true si consentement accepté', () => {
        localStorage.setItem(KEYS.consent, 'accepted');
        expect(hasConsent(true)).toBe(true);
    });
});

// ── 7. buildBeaconParams ──────────────────────────────────────────────────────

describe('buildBeaconParams', () => {
    const base = {
        visitorId:   'visitor-uuid',
        sessionId:   'session-uuid',
        newVisitor:  true,
        newDay:      false,
        newHour:     true,
        newPage:     true,
        pageUrl:     '/contact',
        referrerUrl: '',
    };

    it('encode les UUIDs et les flags correctement', () => {
        const p = buildBeaconParams(base);
        expect(p.get('visitor_id')).toBe('visitor-uuid');
        expect(p.get('session_id')).toBe('session-uuid');
        expect(p.get('n')).toBe('1');
        expect(p.get('nd')).toBe('0');
        expect(p.get('nh')).toBe('1');
        expect(p.get('np')).toBe('1');
        expect(p.get('page_url')).toBe('/contact');
    });

    it('encode referrer_url vide si absent', () => {
        expect(buildBeaconParams(base).get('referrer_url')).toBe('');
    });

    it('encode referrer_url si fourni', () => {
        const p = buildBeaconParams({ ...base, referrerUrl: 'https://google.com/' });
        expect(p.get('referrer_url')).toBe('https://google.com/');
    });

    it('n=0 si newVisitor vaut false', () => {
        const p = buildBeaconParams({ ...base, newVisitor: false });
        expect(p.get('n')).toBe('0');
    });
});

// ── 8. sendBeacon ─────────────────────────────────────────────────────────────

describe('sendBeacon', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));
    });
    afterEach(() => vi.unstubAllGlobals());

    it('appelle fetch avec le bon endpoint et les paramètres en query string', () => {
        const params = new URLSearchParams({ visitor_id: 'abc', n: '1' });
        sendBeacon('/statamic-analytics/track', params);

        expect(fetch).toHaveBeenCalledOnce();
        const [url, options] = fetch.mock.calls[0];
        expect(url).toContain('/statamic-analytics/track?');
        expect(url).toContain('visitor_id=abc');
        expect(options.method).toBe('GET');
    });
});

// ── 9. init ───────────────────────────────────────────────────────────────────

describe('init', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));
    });
    afterEach(() => vi.unstubAllGlobals());

    it('n\'appelle pas fetch si consentement requis mais absent', () => {
        init({ endpoint: '/statamic-analytics/track', consentRequired: true });
        expect(fetch).not.toHaveBeenCalled();
    });

    it('appelle fetch si le consentement n\'est pas requis', () => {
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        expect(fetch).toHaveBeenCalledOnce();
    });

    it('appelle fetch si le consentement est accepté', () => {
        localStorage.setItem(KEYS.consent, 'accepted');
        init({ endpoint: '/statamic-analytics/track', consentRequired: true });
        expect(fetch).toHaveBeenCalledOnce();
    });

    it('marque n=1 (nouveau visiteur) au premier appel', () => {
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        const url = new URL(fetch.mock.calls[0][0], 'http://localhost');
        expect(url.searchParams.get('n')).toBe('1');
    });

    it('marque n=0 si visitor_id existe déjà', () => {
        localStorage.setItem(KEYS.vid, 'existing-visitor');
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        const url = new URL(fetch.mock.calls[0][0], 'http://localhost');
        expect(url.searchParams.get('n')).toBe('0');
    });

    it('persiste visitor_id en localStorage après la première visite', () => {
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        expect(localStorage.getItem(KEYS.vid)).toBeTruthy();
    });

    it('marque np=1 pour une page non encore visitée dans la session', () => {
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        const url = new URL(fetch.mock.calls[0][0], 'http://localhost');
        expect(url.searchParams.get('np')).toBe('1');
    });

    it('marque np=0 si la page a déjà été visitée dans la session', () => {
        // Simuler une page déjà visitée (window.location.pathname = '/' par défaut dans jsdom)
        sessionStorage.setItem(KEYS.vp, JSON.stringify(['/']));
        init({ endpoint: '/statamic-analytics/track', consentRequired: false });
        const url = new URL(fetch.mock.calls[0][0], 'http://localhost');
        expect(url.searchParams.get('np')).toBe('0');
    });
});
