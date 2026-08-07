<?php

return [

    // Navigation
    'nav_item' => 'Analytiques',

    // Éditeur de rôles CP — libellé du groupe de permissions
    'permission_group' => 'Privacy Analytics',

    // Sélecteur de plage de dates
    'last_24_hours' => 'Dernières 24 heures',
    'last_7_days'   => '7 derniers jours',
    'last_30_days'  => '30 derniers jours',
    'custom_range'  => 'Période personnalisée',
    'date_to'       => 'au',

    // Boutons d'en-tête
    'refresh_data' => 'Actualiser',
    'export_data'         => 'Exporter',
    'export_gdpr_warning' => "Cet export contient des adresses IP en clair et d'autres données personnelles. Continuer ?",
    'settings'     => 'Paramètres',

    // Panneau de paramètres
    'analytics_settings'    => 'Paramètres des analytiques',
    'geolocation_stats'     => 'Statistiques de géolocalisation',
    'total_lookups'         => 'Requêtes totales :',
    'success_rate'          => 'Taux de succès :',
    'unique_ips'            => 'IPs uniques :',
    'last_lookup'           => 'Dernière requête :',
    'never'                 => 'Jamais',
    'resetting'             => 'Réinitialisation…',
    'reset_stats'           => 'Réinitialiser les statistiques',
    'current_configuration' => 'Configuration actuelle',
    'geo_provider'          => 'Fournisseur :',
    'cache_duration'        => 'Durée du cache :',
    'rate_limit'            => 'Limite de requêtes :',
    'processing'            => 'Traitement :',
    'dashboard_refresh'     => 'Actualisation :',
    'minutes'               => 'minutes',
    'every_x_minutes'       => 'Toutes les :count minutes',
    'requests_per_minute'   => 'requêtes/minute',
    'seconds'               => 'secondes',

    // Statistiques générales
    'total_visits'     => 'Visites totales',
    'unique_visitors'  => 'Visiteurs uniques',
    'engagement'       => 'Engagement',
    'avg_time_on_site' => 'temps moyen sur le site',
    'bounce_rate'      => 'Taux de rebond',

    // Comparaisons
    'vs_previous_period' => 'vs période précédente',

    // Fréquence des visites
    'visit_frequency'      => 'Fréquence des visites',
    'new_visitors'         => 'Nouveaux visiteurs',
    'returning_visitors'   => 'Visiteurs récurrents',
    'pages_per_session'    => 'Pages/Session',
    'avg_session_duration' => 'Durée moy. de session',

    // Graphiques
    'page_views_over_time' => 'Pages vues dans le temps',
    'total_views'          => 'Vues totales',
    'unique_views'         => 'Vues uniques',

    // Pays
    'top_countries'    => 'Principaux pays',
    'country'          => 'Pays',
    'visits'           => 'Visites',
    'percent_of_total' => '% du total',

    // Stats techniques
    'device_types'  => 'Types d\'appareils',
    'browser_usage' => 'Navigateurs',

    // Performance des pages
    'page_performance' => 'Performance des pages',
    'page_url'         => 'URL de la page',
    'views'            => 'Vues',
    'avg_time'         => 'Temps moy.',
    'exit_rate'        => 'Taux de sortie',
    'loading_data'     => 'Chargement…',

    // Flux utilisateurs
    'user_flow'          => 'Flux utilisateurs',
    'top_entry_pages'    => 'Pages d\'entrée principales',
    'entry_points'       => 'Points d\'entrée',
    'most_engaged_pages' => 'Pages les plus engageantes',
    'highest_engagement' => 'Engagement le plus élevé',
    'top_exit_pages'     => 'Pages de sortie principales',
    'exit_points'        => 'Points de sortie',

    // Sources de trafic (ReferrerWidget)
    'traffic_sources' => 'Sources de trafic',
    'domain'          => 'Domaine',
    'source_direct'   => 'Direct',
    'source_search'   => 'Recherche',
    'source_social'   => 'Réseaux sociaux',
    'source_referral' => 'Référent',

    // Plateformes (PlatformWidget)
    'platforms' => 'Plateformes / OS',

    // Villes (CityWidget)
    'top_cities' => 'Principales villes',
    'city'       => 'Ville',

    // Heatmap (HeatmapWidget)
    'activity_heatmap' => 'Carte d\'activité',
    'low_activity'     => 'Faible',
    'high_activity'    => 'Élevée',

    // Temps réel (RealTimeWidget)
    'realtime_visitors' => 'Visiteurs en temps réel',
    'active_sessions'   => 'Sessions actives',
    'active_visitors'   => 'Visiteurs actifs',
    'page_views'        => 'Pages vues',
    'sessions'          => 'sessions',
    'last_x_min'        => ':count dernières min',
    'updates_every_30s' => 'Mis à jour toutes les 30 secondes',

    // Nouveau vs récurrent (NewReturningTrendWidget)
    'new_vs_returning_trend' => 'Nouveaux vs Récurrents',

    // Profondeur de session (SessionDepthWidget)
    'session_depth' => 'Profondeur de session',
    'pages_abbr'    => 'p.',

    // Panneau de paramètres du bandeau de consentement
    'essential_analytics'              => 'Analytiques essentielles',
    'essential_analytics_description'  => 'Suivi de base des visites et monitoring des performances',
    'geolocation_tracking'             => 'Suivi de géolocalisation',
    'geolocation_tracking_description' => 'Localiser les visiteurs pour de meilleures informations',

];
