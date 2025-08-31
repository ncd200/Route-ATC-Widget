<?php

namespace App\Widgets;

use Arrilot\Widgets\AbstractWidget;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RouteAtcWidget extends AbstractWidget
{
    protected $config = [];

    /** Cache TTLs (seconds) */
    protected int $vatsimTtl   = 1200;  // 20 minutes
    protected int $simbriefTtl = 1200;  // 20 minutes

    public function run()
    {
        $user = Auth::user();
        if (!$user) {
            return view('widgets.route_atc_widget', [
                'error' => 'Please log in to view route ATC.',
            ]);
        }

        $simbriefId = $this->getSimbriefId($user, $this->config);
        if (!$simbriefId) {
            return view('widgets.route_atc_widget', [
                'error' => 'No SimBrief ID/username found in your profile.',
            ]);
        }

        // --- Fetch latest SimBrief OFP (XML) for this user ---
        $ofpXmlStr = Cache::remember("routeatc:simbrief:{$simbriefId}", $this->simbriefTtl, function () use ($simbriefId) {
            try {
                $resp = Http::timeout(12)->get('https://www.simbrief.com/api/xml.fetcher.php', [
                    'username' => $simbriefId,
                ]);
                return $resp->ok() ? $resp->body() : null;
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (!$ofpXmlStr) {
            return view('widgets.route_atc_widget', [
                'error' => 'Could not fetch SimBrief OFP for your account.',
            ]);
        }

        $ofp = @simplexml_load_string($ofpXmlStr);
        if (!$ofp) {
            return view('widgets.route_atc_widget', [
                'error' => 'Invalid SimBrief response.',
            ]);
        }

        // Extract core data
        $depIcao  = strtoupper(trim((string)($ofp->origin->icao_code ?? '')));
        $arrIcao  = strtoupper(trim((string)($ofp->destination->icao_code ?? '')));
        $routeStr = trim((string)($ofp->general->route ?? $ofp->general->atc_route ?? $ofp->general->route_text ?? ''));

        if (!$depIcao || !$arrIcao) {
            return view('widgets.route_atc_widget', [
                'error' => 'Could not read origin/destination from SimBrief.',
            ]);
        }

        // --- Fetch live VATSIM data (JSON) ---
        $vatsim = Cache::remember('routeatc:vatsim:v3', $this->vatsimTtl, function () {
            try {
                $resp = Http::timeout(12)->get('https://data.vatsim.net/v3/vatsim-data.json');
                return $resp->ok() ? $resp->json() : null;
            } catch (\Throwable $e) {
                return null;
            }
        });

        if (!$vatsim || !is_array($vatsim)) {
            return view('widgets.route_atc_widget', [
                'error' => 'Could not contact VATSIM live data.',
            ]);
        }

        $controllers = collect($vatsim['controllers'] ?? [])->filter(fn($c) => isset($c['callsign']));
        $atis       = collect($vatsim['atis'] ?? [])->filter(fn($a) => isset($a['callsign']));

        // Departure + Arrival stations (sector-aware + ATIS fallback)
        $depStations = $this->buildAirportStations($depIcao, $controllers, $atis);
        $arrStations = $this->buildAirportStations($arrIcao, $controllers, $atis);

        // En-route CTRs from SimBrief FIRs or a heuristic corridor
        $firCandidates = $this->extractFirsFromSimbrief($ofp);
        if (empty($firCandidates)) {
            $firCandidates = $this->guessFirCorridor($depIcao, $arrIcao, $routeStr);
        }
        $enrouteCtr = $this->mapCtrStatus($firCandidates, $controllers);

        return view('widgets.route_atc_widget', [
            'dep'         => $depIcao,
            'arr'         => $arrIcao,
            'route'       => $routeStr,
            'depStations' => $depStations,
            'arrStations' => $arrStations,
            'enrouteCtr'  => $enrouteCtr,
            'error'       => null,
        ]);
    }

    /**
     * Find SimBrief ID/username from multiple possible locations.
     * Priority: explicit config override > user columns > options array > custom fields.
     */
    protected function getSimbriefId($user, array $config = []): ?string
    {
        // 0) Explicit override via @widget(..., ['simbrief' => '...'])
        if (!empty($config['simbrief'])) {
            return trim($config['simbrief']);
        }

        // 1) Common direct columns
        foreach (['simbrief_id', 'simbrief_username', 'simbrief'] as $col) {
            if (!empty($user->{$col})) {
                return trim($user->{$col});
            }
        }

        // 2) Options/meta array
        $opts = is_array($user->options ?? null) ? $user->options : [];
        foreach (['simbrief_id', 'simbrief_username', 'simbrief'] as $k) {
            if (!empty($opts[$k])) {
                return trim($opts[$k]);
            }
        }

        // 3) Custom fields (phpVMS v7)
        try {
            $user->loadMissing('fields.field'); // ensure nested relation
            $map = collect($user->fields ?? [])
                ->mapWithKeys(function ($ufv) {
                    $slug = optional($ufv->field)->slug; // null-safe
                    return $slug ? [$slug => $ufv->value] : [];
                });
            foreach (['simbrief_id', 'simbrief_username', 'simbrief'] as $slug) {
                if ($map->has($slug) && !empty($map[$slug])) {
                    return trim($map[$slug]);
                }
            }
        } catch (\Throwable $e) {
            // ignore; fall through
        }

        return null;
    }

    /**
     * Build station badges for an airport with sector-aware matching.
     * Recognizes ATIS/AFIS/INFO from either the "atis" block or "controllers" block,
     * including sectorized variants like EHAM_W_ATIS, EDDM_A_AFIS, etc.
     */
    protected function buildAirportStations(string $icao, $controllers, $atis): array
    {
        $icao = strtoupper($icao);

        // Helper: find first controller whose callsign starts with "ICAO_" and
        // whose LAST token matches any of the given roles (sectorized allowed).
        $findRole = function (array $roles) use ($controllers, $icao) {
            $roles = array_map('strtoupper', $roles);
            foreach ($controllers as $c) {
                $cs = strtoupper($c['callsign'] ?? '');
                if (strpos($cs, "{$icao}_") !== 0) {
                    continue;
                }
                $tokens = explode('_', $cs);
                $last   = end($tokens);           // e.g. EDDF_N_GND -> GND
                if (in_array($last, $roles, true)) {
                    return $c;
                }
            }
            return null;
        };

        $stations = [
            'ATIS'     => ['online' => false, 'callsign' => "{$icao}_ATIS", 'source' => 'atis', 'data' => null],
            'GROUND'   => ['online' => false, 'callsign' => null, 'source' => 'controllers', 'data' => null],
            'TOWER'    => ['online' => false, 'callsign' => null, 'source' => 'controllers', 'data' => null],
            'APPROACH' => ['online' => false, 'callsign' => null, 'source' => 'controllers', 'data' => null],
        ];

        // ATIS primary: in the atis array (accept exact or sectorized prefix)
        $atisHit = $atis->first(function ($a) use ($icao) {
            $cs = strtoupper($a['callsign'] ?? '');
            return (bool)preg_match("/^{$icao}_(?:[A-Z0-9]{1,3}_)?ATIS$/", $cs);
        });

        // ATIS fallback: sometimes appears in controllers as *_ATIS / *_AFIS / *_INFO
        if (!$atisHit) {
            $atisHit = $controllers->first(function ($c) use ($icao) {
                $cs = strtoupper($c['callsign'] ?? '');
                return (bool)preg_match("/^{$icao}_(?:[A-Z0-9]{1,3}_)?(ATIS|AFIS|INFO)$/", $cs);
            });
        }

        if ($atisHit) {
            $stations['ATIS']['online']   = true;
            $stations['ATIS']['data']     = $atisHit;
            $stations['ATIS']['callsign'] = $atisHit['callsign'];
        }

        // GROUND family: GND, GMC, DEL/CLD, RMP/APR/APN (regional variants)
        $gnd = $findRole(['GND','GMC','DEL','CLD','RMP','APR','APN']);
        if ($gnd) {
            $stations['GROUND']['online']   = true;
            $stations['GROUND']['data']     = $gnd;
            $stations['GROUND']['callsign'] = $gnd['callsign'];
        } else {
            $stations['GROUND']['callsign'] = "{$icao}_GND";
        }

        // TOWER
        $twr = $findRole(['TWR']);
        if ($twr) {
            $stations['TOWER']['online']   = true;
            $stations['TOWER']['data']     = $twr;
            $stations['TOWER']['callsign'] = $twr['callsign'];
        } else {
            $stations['TOWER']['callsign'] = "{$icao}_TWR";
        }

        // APPROACH family: APP, DEP, DIR, RAD, TMA
        $app = $findRole(['APP','DEP','DIR','RAD','TMA']);
        if ($app) {
            $stations['APPROACH']['online']   = true;
            $stations['APPROACH']['data']     = $app;
            $stations['APPROACH']['callsign'] = $app['callsign'];
        } else {
            $stations['APPROACH']['callsign'] = "{$icao}_APP";
        }

        return $stations;
    }

    /**
     * Extract FIR list from SimBrief XML (best-effort).
     * Returns array like ['EDGG','EHAA','LFFF'].
     */
    protected function extractFirsFromSimbrief(\SimpleXMLElement $ofp): array
    {
        $firs = [];

        // 1) <fir> nodes (if present)
        $nodes = @$ofp->xpath('//fir') ?: [];
        foreach ($nodes as $n) {
            $code = strtoupper(trim((string)($n->id ?? $n->code ?? $n)));
            if (preg_match('/^[A-Z]{4}$/', $code)) {
                $firs[$code] = true;
            }
        }

        // 2) Scan navlog text for 4-letter FIR-like tokens
        $navlogTxt = (string)($ofp->text->navlog ?? $ofp->navlog_text ?? '');
        if ($navlogTxt) {
            if (preg_match_all('/\b([A-Z]{4})\b/', $navlogTxt, $m)) {
                foreach ($m[1] as $code) {
                    if (preg_match('/^(E|L|B|U|K|C|M|T|O|V|W|Y|Z|R)[A-Z]{3}$/', $code)) {
                        $firs[$code] = true;
                    }
                }
            }
        }

        return array_keys($firs);
    }

    /**
     * Heuristic corridor if FIRs aren't available.
     */
    protected function guessFirCorridor(string $dep, string $arr, string $route): array
    {
        $dep2 = substr($dep, 0, 2);
        $arr2 = substr($arr, 0, 2);

        $eu = ['EGTT','EGPX','EHAA','EDGG','EDMM','EDWW','EDYY','EBBU','LFFF','LFMM','LFRR','LECM','LECB','LKAA','LOVV','LHCC','LQSB','LJLA','LIMM','LIRR','LGGG'];
        $uk = ['EGTT','EGPX'];
        $de = ['EDGG','EDMM','EDWW','EDYY'];
        $fr = ['LFFF','LFMM','LFRR'];
        $es = ['LECM','LECB'];
        $nl = ['EHAA'];
        $be = ['EBBU'];

        $candidates = [];

        if (in_array($dep[0], ['E','L']) || in_array($arr[0], ['E','L'])) {
            $candidates = array_merge($candidates, $eu);
            if ($dep2 === 'EG' || $arr2 === 'EG') $candidates = array_merge($candidates, $uk);
            if ($dep2 === 'ED' || $arr2 === 'ED') $candidates = array_merge($candidates, $de);
            if ($dep2 === 'LF' || $arr2 === 'LF') $candidates = array_merge($candidates, $fr);
            if ($dep2 === 'LE' || $arr2 === 'LE') $candidates = array_merge($candidates, $es);
            if ($dep2 === 'EH' || $arr2 === 'EH') $candidates = array_merge($candidates, $nl);
            if ($dep2 === 'EB' || $arr2 === 'EB') $candidates = array_merge($candidates, $be);
        }

        if (in_array($dep[0], ['M','T','K','C']) || in_array($arr[0], ['M','T','K','C'])) {
            $candidates = array_merge($candidates, [
                'KZMA','KZNY','KZBW','KZWY','KZJX','KZHU',
                'TTZP','MKJK','MTEG','TTPP',
                'MUHA','MUFH',
                'TZCZ','SKEC',
            ]);
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn($c) => preg_match('/^[A-Z]{4}$/', $c))));

        return $candidates;
    }

    /**
     * Map CTR/FSS online status for provided FIR codes.
     * Accepts sectorized callsigns like EDGG_W_CTR, EGGX_FSS, etc.
     * @return array [['code'=>'EDGG','online'=>true,'type'=>'CTR|FSS','data'=>controller|null], ...]
     */
    protected function mapCtrStatus(array $firCodes, $controllers): array
    {
        $index = [];
        foreach ($controllers as $c) {
            $cs = strtoupper($c['callsign'] ?? '');
            // Match: CODE_(optional sector)_CTR  or  CODE_(optional sector)_FSS
            if (preg_match('/^([A-Z]{4})_(?:[A-Z0-9]{1,4}_)?(CTR|FSS)$/', $cs, $m)) {
                $code = $m[1];
                $type = $m[2];
                // Prefer CTR over FSS if both exist for same FIR
                if (!isset($index[$code]) || ($type === 'CTR' && $index[$code]['type'] === 'FSS')) {
                    $index[$code] = ['data' => $c, 'type' => $type];
                }
            }
        }

        // If empty candidates, show up to 10 live CTR/FSS as a fallback
        if (empty($firCodes)) {
            $firCodes = array_slice(array_keys($index), 0, 10);
        }

        $out = [];
        foreach (array_unique($firCodes) as $code) {
            $has = isset($index[$code]);
            $out[] = [
                'code'   => $code,
                'online' => $has,
                'type'   => $has ? $index[$code]['type'] : null,
                'data'   => $has ? $index[$code]['data'] : null,
            ];
        }
        return $out;
    }
}
