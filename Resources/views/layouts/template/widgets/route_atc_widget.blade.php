@if(!empty($error))
  <div class="alert alert-warning mb-3">{{ $error }}</div>
  @php return; @endphp
@endif

<div class="card mb-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><strong>Route ATC Overview</strong></span>
    @if(!empty($dep) && !empty($arr))
      <span class="text-muted">{{ $dep }} ? {{ $arr }}</span>
    @endif
  </div>

  <div class="card-body">
    @if(!empty($route))
      <div class="mb-3 small text-muted text-truncate" title="{{ $route }}">
        <strong>Route:</strong> {{ $route }}
      </div>
    @endif

    <div class="row">
      {{-- Departure Block --}}
      <div class="col-lg-4 mb-3">
        <div class="card border">
          <div class="card-body">
            <h4 class="mt-0 header-title border-bottom">
              <i class="ph-fill ph-download align-middle fs-20 me-1"></i>
              <span class="badge bg-primary me-2">Departure</span>
              <span class="fw-semibold">{{ $dep ?? '----' }}</span>
            </h4>

            <div class="form-group form-bg-grey rounded mb-0">
              {{-- Flex container for horizontal badges --}}
              <div class="d-flex flex-wrap align-items-center">
                @php $stationOrder = ['ATIS','GROUND','TOWER','APPROACH']; @endphp
                @foreach($stationOrder as $label)
                  @php
                    $s = $depStations[$label] ?? null;
                    $html = ''; $plain = '';
                    if ($s) {
                      if ($s['online'] && $s['data']) {
                        $d = $s['data'];
                        $name = e($d['name'] ?? '');
                        $freq = e($d['frequency'] ?? '');
                        $cs   = e($d['callsign'] ?? '');
                        $upd  = e($d['last_updated'] ?? '');
                        $rate = e($d['rating'] ?? '');
                        $html = "<div><strong>{$label}</strong></div>
                                 <div>Callsign: {$cs}</div>
                                 <div>Frequency: {$freq}</div>
                                 <div>Controller: {$name}</div>
                                 <div>Rating: {$rate}</div>
                                 <div>Last update: {$upd}</div>";
                        $plain = "{$label} | {$cs} | {$freq} | {$name}";
                      } else {
                        $cs = e($s['callsign'] ?? ($dep.'_'.($label==='APPROACH'?'APP/DEP':$label)));
                        $html  = "<div><strong>{$label}</strong></div><div>{$cs} offline</div>";
                        $plain = "{$label}: {$cs} offline";
                      }
                    }
                  @endphp
                  <span
                    class="badge {{ ($s && $s['online']) ? 'bg-success' : 'bg-secondary' }} me-2 mb-2"
                    data-bs-toggle="tooltip"
                    data-bs-html="true"
                    data-bs-title="{!! $html !!}"
                    title="{{ $plain }}"
                  >
                    {{ $label }}
                  </span>
                @endforeach
              </div>
            </div>
          </div>
        </div>
      </div>

      {{-- En-route Block --}}
      <div class="col-lg-4 mb-3">
        <div class="card border">
          <div class="card-body">
            <h4 class="mt-0 header-title border-bottom">
              <i class="ph-fill ph-download align-middle fs-20 me-1"></i>
              <span class="badge bg-info me-2">En-route</span>
              <span class="fw-semibold">Area/Center (CTR/FSS)</span>
            </h4>

            <div class="form-group form-bg-grey rounded mb-0">
              {{-- Flex container for horizontal badges --}}
              <div class="d-flex flex-wrap align-items-center">
                @if(empty($enrouteCtr))
                  <div class="text-muted small">No en-route centers detected.</div>
                @else
                  @foreach($enrouteCtr as $ctr)
                    @php
                      $ctype = $ctr['type'] ?? 'CTR';
                      if ($ctr['online'] && $ctr['data']) {
                        $d = $ctr['data'];
                        $name = e($d['name'] ?? '');
                        $freq = e($d['frequency'] ?? '');
                        $cs   = e($d['callsign'] ?? ($ctr['code'].'_'.$ctype));
                        $upd  = e($d['last_updated'] ?? '');
                        $rate = e($d['rating'] ?? '');
                        $html  = "<div><strong>{$cs}</strong></div>
                                  <div>Service: {$ctype}</div>
                                  <div>Frequency: {$freq}</div>
                                  <div>Controller: {$name}</div>
                                  <div>Rating: {$rate}</div>
                                  <div>Last update: {$upd}</div>";
                        $plain = "{$cs} ({$ctype}) | {$freq} | {$name}";
                      } else {
                        $cs    = e($ctr['code'].'_'.$ctype);
                        $html  = "<div><strong>{$cs}</strong></div><div>Offline</div>";
                        $plain = "{$cs} Offline";
                      }
                    @endphp
                    <span
                      class="badge {{ $ctr['online'] ? 'bg-success' : 'bg-secondary' }} me-2 mb-2"
                      data-bs-toggle="tooltip"
                      data-bs-html="true"
                      data-bs-title="{!! $html !!}"
                      title="{{ $plain }}"
                    >
                      {{ $ctr['code'] }}
                    </span>
                  @endforeach
                @endif
              </div>
            </div>
          </div>
        </div>
      </div>

      {{-- Arrival Block --}}
      <div class="col-lg-4 mb-3">
        <div class="card border">
          <div class="card-body">
            <h4 class="mt-0 header-title border-bottom">
              <i class="ph-fill ph-download align-middle fs-20 me-1"></i>
              <span class="badge bg-primary me-2">Arrival</span>
              <span class="fw-semibold">{{ $arr ?? '----' }}</span>
            </h4>

            <div class="form-group form-bg-grey rounded mb-0">
              {{-- Flex container for horizontal badges --}}
              <div class="d-flex flex-wrap align-items-center">
                @php $stationOrder = ['ATIS','GROUND','TOWER','APPROACH']; @endphp
                @foreach($stationOrder as $label)
                  @php
                    $s = $arrStations[$label] ?? null;
                    $html = ''; $plain = '';
                    if ($s) {
                      if ($s['online'] && $s['data']) {
                        $d = $s['data'];
                        $name = e($d['name'] ?? '');
                        $freq = e($d['frequency'] ?? '');
                        $cs   = e($d['callsign'] ?? '');
                        $upd  = e($d['last_updated'] ?? '');
                        $rate = e($d['rating'] ?? '');
                        $html = "<div><strong>{$label}</strong></div>
                                 <div>Callsign: {$cs}</div>
                                 <div>Frequency: {$freq}</div>
                                 <div>Controller: {$name}</div>
                                 <div>Rating: {$rate}</div>
                                 <div>Last update: {$upd}</div>";
                        $plain = "{$label} | {$cs} | {$freq} | {$name}";
                      } else {
                        $cs = e($s['callsign'] ?? ($arr.'_'.($label==='APPROACH'?'APP/DEP':$label)));
                        $html  = "<div><strong>{$label}</strong></div><div>{$cs} offline</div>";
                        $plain = "{$label}: {$cs} offline";
                      }
                    }
                  @endphp
                  <span
                    class="badge {{ ($s && $s['online']) ? 'bg-success' : 'bg-secondary' }} me-2 mb-2"
                    data-bs-toggle="tooltip"
                    data-bs-html="true"
                    data-bs-title="{!! $html !!}"
                    title="{{ $plain }}"
                  >
                    {{ $label }}
                  </span>
                @endforeach
              </div>
            </div>

          </div>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Bootstrap tooltip init (works even if @stack('scripts') is not used) --}}
<script>
(function () {
  try {
    if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
      var els = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      els.forEach(function (el) {
        new bootstrap.Tooltip(el, { html: true, sanitize: false, container: 'body' });
      });
    }
  } catch (e) { /* ignore */ }
})();
</script>

@push('scripts')
<script>
(function () {
  try {
    if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {
      var els = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
      els.forEach(function (el) {
        new bootstrap.Tooltip(el, { html: true, sanitize: false, container: 'body' });
      });
    }
  } catch (e) { /* ignore */ }
})();
</script>
@endpush
