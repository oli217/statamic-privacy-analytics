/**
 * Beacon tracker — mode static caching full de Statamic.
 *
 * Spec exécutable et version lisible du script inline de ConsentBanner::tracker()
 * (src/Tags/ConsentBanner.php). Toutes les fonctions sont exportées pour les tests Vitest.
 *
 * ⚠ SYNCHRONISATION : toute modification de la logique ici doit être répercutée
 * dans le script inline de ConsentBanner::tracker(), et vice-versa.
 *
 * Clés de stockage : identiques au script inline pour permettre une migration
 * transparente sans perte de session visiteur.
 *
 * Différences intentionnelles vs le script inline :
 *   - Date/heure en temps LOCAL (le script inline utilise UTC pour la date,
 *     getHours() local pour l'heure — incohérence corrigée ici)
 *   - fetch() au lieu de new Image() — promesse annulable, meilleure testabilité
 */

// Clés de stockage (compatibles avec le script inline ConsentBanner::tracker())
export const KEYS = {
    vid:     '_anl_vid',           // visitor_id  — localStorage, persistant cross-session
    sid:     '_anl_sid',           // session_id  — sessionStorage, par onglet
    vp:      '_anl_vp',            // visited pages — sessionStorage
    ld:      '_anl_ld',            // last visit date — localStorage
    lh:      '_anl_lh',            // last visit hour — localStorage
    consent: 'analytics_consent',  // partagé avec consent-banner.js
};

// ── UUID ──────────────────────────────────────────────────────────────────────

export function generateUuid() {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID();
    }
    // Fallback navigateurs anciens
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

// ── Identifiants visiteur / session ──────────────────────────────────────────

/** Retourne true si aucun visitor_id n'existe encore en localStorage. */
export function isNewVisitor() {
    return !localStorage.getItem(KEYS.vid);
}

/** Retourne le visitor_id existant ou en crée un nouveau. */
export function getOrCreateVisitorId() {
    let id = localStorage.getItem(KEYS.vid);
    if (!id) {
        id = generateUuid();
        localStorage.setItem(KEYS.vid, id);
    }
    return id;
}

/** Retourne le session_id existant ou en crée un nouveau. */
export function getOrCreateSessionId() {
    let id = sessionStorage.getItem(KEYS.sid);
    if (!id) {
        id = generateUuid();
        sessionStorage.setItem(KEYS.sid, id);
    }
    return id;
}

// ── Helpers date/heure (temps local) ─────────────────────────────────────────

export function todayString() {
    const d = new Date();
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

export function currentHourString() {
    const h = String(new Date().getHours()).padStart(2, '0');
    return `${todayString()} ${h}`;
}

// ── Flags de visite ───────────────────────────────────────────────────────────

export function isNewDayVisit() {
    return localStorage.getItem(KEYS.ld) !== todayString();
}

export function isNewHourVisit() {
    return localStorage.getItem(KEYS.lh) !== currentHourString();
}

export function isNewPageVisit(path) {
    try {
        return !JSON.parse(sessionStorage.getItem(KEYS.vp) || '[]').includes(path);
    } catch {
        return true;
    }
}

// ── Mise à jour de l'état ─────────────────────────────────────────────────────

export function recordVisit(path) {
    localStorage.setItem(KEYS.ld, todayString());
    localStorage.setItem(KEYS.lh, currentHourString());

    let pages = [];
    try { pages = JSON.parse(sessionStorage.getItem(KEYS.vp) || '[]'); } catch {}

    if (!pages.includes(path)) {
        // Garder 19 entrées max avant push → 20 au total (miroir du script inline)
        pages = pages.slice(-19);
        pages.push(path);
        sessionStorage.setItem(KEYS.vp, JSON.stringify(pages));
    }
}

// ── Consentement ──────────────────────────────────────────────────────────────

export function hasConsent(consentRequired) {
    if (!consentRequired) return true;
    return localStorage.getItem(KEYS.consent) === 'accepted';
}

// ── Beacon ────────────────────────────────────────────────────────────────────

export function buildBeaconParams({ visitorId, sessionId, newVisitor, newDay, newHour, newPage, pageUrl, referrerUrl }) {
    return new URLSearchParams({
        page_url:     pageUrl,
        referrer_url: referrerUrl || '',
        visitor_id:   visitorId,
        session_id:   sessionId,
        n:            newVisitor ? '1' : '0',
        nd:           newDay     ? '1' : '0',
        nh:           newHour    ? '1' : '0',
        np:           newPage    ? '1' : '0',
    });
}

/** Envoie le beacon via fetch (GET, fire-and-forget). */
export function sendBeacon(endpoint, params) {
    return fetch(`${endpoint}?${params.toString()}`, { method: 'GET' });
}

// ── Init ──────────────────────────────────────────────────────────────────────

/**
 * Point d'entrée principal.
 *
 * Ordre critique : calculer les flags AVANT d'appeler getOrCreateVisitorId(),
 * car isNewVisitor() vérifie l'absence de _anl_vid. Une fois l'ID créé, il
 * serait considéré comme visiteur existant.
 */
export function init({ endpoint, consentRequired = true } = {}) {
    if (!hasConsent(consentRequired)) return;

    const path = window.location.pathname;

    const newVisitor = isNewVisitor();
    const newDay     = isNewDayVisit();
    const newHour    = isNewHourVisit();
    const newPage    = isNewPageVisit(path);

    const visitorId = getOrCreateVisitorId();
    const sessionId = getOrCreateSessionId();

    recordVisit(path);

    sendBeacon(endpoint, buildBeaconParams({
        visitorId, sessionId,
        newVisitor, newDay, newHour, newPage,
        pageUrl: path,
        referrerUrl: document.referrer,
    }));
}
