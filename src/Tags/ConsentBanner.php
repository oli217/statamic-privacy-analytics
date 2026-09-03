<?php

namespace Oliweb\StatamicAnalytics\Tags;

// use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Log;
use Statamic\Facades\File;
use Statamic\Tags\Tags;

class ConsentBanner extends Tags
{
    protected static $handle = 'statamic_analytics';

    /**
     * The {{ statamic_analytics:consent_banner }} tag
     */
    public function consent_banner()
    {
        return $this->index();
    }

    /**
     * The {{ statamic_analytics }} tag
     */
    public function index()
    {
        try {
            // Get the template content directly
            $templatePath = $this->getTemplatePath();
            if (!File::exists($templatePath)) {
                throw new \Exception("Template not found at: {$templatePath}");
            }

            // Get the template content
            $content = File::get($templatePath);

            // Get the context data
            $context = array_merge($this->context->all(), [
                'config' => [
                    'statamic-analytics' => [
                        'tracking' => [
                            'consent' => [
                                'banner' => config('statamic-analytics.tracking.consent.banner')
                            ]
                        ]
                    ]
                ]
            ]);

            // Parse it with Antlers
            return \Statamic\Facades\Antlers::parse($content, $context);
        } catch (\Exception $e) {
            Log::error('Error rendering consent banner', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return '<!-- Error rendering consent banner: ' . $e->getMessage() . ' -->';
        }
    }

    protected function getTemplatePath()
    {
        $exportedComponentPath = resource_path('views/vendor/statamic-analytics/components/consent-banner.antlers.html');
        if (file_exists($exportedComponentPath)) {
            return $exportedComponentPath;
        } else {
            return __DIR__ . '/../../resources/views/components/consent-banner.antlers.html';
        }
    }

    /**
     * The {{ statamic_analytics:tracker }} tag.
     *
     * Rend un script de tracking JS uniquement si STATAMIC_STATIC_CACHING_STRATEGY=full.
     * Dans tous les autres cas, le middleware TrackPageVisit gère le tracking côté serveur.
     *
     * ⚠ SYNCHRONISATION : ce script inline est la version de production de
     * resources/js/tracker.js (lisible, commenté, couvert par Vitest).
     * Toute modification de la logique ici doit être répercutée dans tracker.js,
     * et vice-versa — les tests Vitest servent de spec exécutable.
     * Clés de stockage partagées : _anl_vid, _anl_sid, _anl_vp, _anl_ld, _anl_lh
     */
    public function tracker(): string
    {
        if (config('statamic.static_caching.strategy') !== 'full') {
            return '';
        }

        $consentRequired = config('statamic-analytics.tracking.consent.enabled', false) ? 'true' : 'false';
        $endpoint        = '/statamic-analytics/track';

        return <<<HTML
<script>
(function(){
var cr={$consentRequired},ep='{$endpoint}';
if(cr&&localStorage.getItem('analytics_consent')!=='accepted')return;
var vid=localStorage.getItem('_anl_vid'),isNew=!vid;
if(isNew){
  vid=(typeof crypto!=='undefined'&&crypto.randomUUID)?crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0;return(c==='x'?r:(r&0x3|0x8)).toString(16);});
  localStorage.setItem('_anl_vid',vid);
}
var sid=sessionStorage.getItem('_anl_sid');
if(!sid){
  sid=(typeof crypto!=='undefined'&&crypto.randomUUID)?crypto.randomUUID():'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,function(c){var r=Math.random()*16|0;return(c==='x'?r:(r&0x3|0x8)).toString(16);});
  sessionStorage.setItem('_anl_sid',sid);
}
var url=window.location.pathname;
var vp=[];try{vp=JSON.parse(sessionStorage.getItem('_anl_vp')||'[]');}catch(e){}
var np=vp.indexOf(url)===-1;
if(np){vp=vp.slice(-19);vp.push(url);sessionStorage.setItem('_anl_vp',JSON.stringify(vp));}
var now=new Date(),today=now.toISOString().slice(0,10),hour=today+' '+('0'+now.getHours()).slice(-2);
var ld=localStorage.getItem('_anl_ld'),lh=localStorage.getItem('_anl_lh');
var p=new URLSearchParams({page_url:url,referrer_url:document.referrer||'',visitor_id:vid,session_id:sid,n:isNew?'1':'0',nd:ld!==today?'1':'0',nh:lh!==hour?'1':'0',np:np?'1':'0'});
new Image().src=ep+'?'+p.toString();
localStorage.setItem('_anl_ld',today);
localStorage.setItem('_anl_lh',hour);
})();
</script>
HTML;
    }

    public function wildcard($method)
    {
        if ($method === 'consent_banner') {
            return $this->consent_banner();
        }

        return $this->index();
    }

    /**
     * The {{ AltCookies:AddonAssets }} tag.
     * Puts the Vite assets on the frontent
     * @return string|array
     */
    public function AddonAssets()
    {
//        $vite = (new Vite)->useHotfile( __DIR__ . '/../../resources/dist/hot')->useBuildDirectory('vendor/statamic-analytics/dist');
//        //$assets = sprintf('<script data-cfasync=“false” type="module" src="%s"></script>', $vite->asset('resources/js/consent-banner.js'));
//        $assets = sprintf('<link rel="stylesheet" href="%s" />', $vite->asset('resources/css/statamic-analytics.css'));
//        return $assets;
    }
}
