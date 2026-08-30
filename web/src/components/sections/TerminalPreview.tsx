import React, { useState } from 'react';
import { ArrowRight, ChevronDown } from 'lucide-react';

type View = 'output' | 'source';

interface RouteRow {
  key: string;
  p95: string;
  avg: string;
  queries: number;
  mem: string;
  status: 'Critical' | 'Healthy';
  dupes: string;
  pattern: string | null;
  execs: string | null;
  source: string | null;
}

const routes: RouteRow[] = [
  {
    key: 'api.orders.index',
    p95: '1.42s',
    avg: '890ms',
    queries: 42,
    mem: '12.4 MB',
    status: 'Critical',
    dupes: '14×',
    pattern: 'select * from users where id = ?',
    execs: 'Executed 14×',
    source: 'OrderController.php:84',
  },
  {
    key: 'api.orders.show',
    p95: '210ms',
    avg: '140ms',
    queries: 8,
    mem: '6.1 MB',
    status: 'Healthy',
    dupes: '—',
    pattern: null,
    execs: null,
    source: null,
  },
];

export default function TerminalPreview() {
  const [view, setView] = useState<View>('output');
  const [open, setOpen] = useState<number | null>(null);

  return (
    <div className="mx-auto max-w-4xl overflow-hidden rounded-2xl border border-white/15 bg-[#08090C] shadow-[0_24px_70px_rgba(0,0,0,0.95)] ring-1 ring-white/5">
      {/* Header */}
      <div className="flex items-center justify-between border-b border-white/[0.08] bg-[#0C0E12] px-4 py-3">
        <div className="flex items-center gap-2.5 text-sm">
          <div className="flex items-center gap-1.5 mr-1">
            <div className="h-2.5 w-2.5 rounded-full bg-[#EC6A5E]/80"></div>
            <div className="h-2.5 w-2.5 rounded-full bg-[#F5BF4F]/80"></div>
            <div className="h-2.5 w-2.5 rounded-full bg-[#62C554]/80"></div>
          </div>
          <span className="font-semibold text-white">Pinpoint</span>
          <span className="text-gray-600">/</span>
          <span className="text-gray-400">Diagnostics</span>
        </div>
        <div className="flex items-center gap-4">
          {(['output', 'source'] as View[]).map((v) => (
            <button
              key={v}
              onClick={() => setView(v)}
              className={`border-b-2 pb-0.5 text-[13px] font-medium transition-colors ${
                view === v
                  ? 'border-brand-accent text-white'
                  : 'border-transparent text-gray-500 hover:text-gray-300'
              }`}
            >
              {v === 'output' ? 'Output' : 'Source'}
            </button>
          ))}
        </div>
      </div>

      {view === 'output' ? (
        <div className="p-4 sm:p-5">
          <div className="flex items-center gap-2 font-mono text-[13px] text-gray-300">
            <span className="text-brand-accent font-semibold">$</span>
            <span>php artisan pinpoint:report</span>
          </div>

          {/* Route table */}
          <div className="mt-4">
            <div className="sm:min-w-[520px]">
              <div className="hidden sm:grid sm:grid-cols-[minmax(0,2fr)_95px_95px_130px_75px] items-center gap-4 sm:gap-6 px-3 pb-2 text-[11px] font-semibold uppercase tracking-widest text-gray-500">
                <div className="text-left">Route</div>
                <div className="w-full text-right justify-self-end">P95</div>
                <div className="w-full text-right justify-self-end">Avg</div>
                <div className="w-full text-center justify-self-center">Status</div>
                <div className="w-full text-right justify-self-end">N+1</div>
              </div>
              <div className="divide-y divide-white/[0.05]">
                {routes.map((r, i) => {
                  const critical = r.status === 'Critical';
                  const expanded = open === i;
                  const hasDetails = Boolean(r.pattern);
                  return (
                    <React.Fragment key={r.key}>
                      <button
                        onClick={() => hasDetails && setOpen(expanded ? null : i)}
                        className={`block w-full rounded-md text-left transition-colors ${
                          hasDetails
                            ? expanded
                              ? 'bg-white/[0.03]'
                              : 'hover:bg-white/[0.02] cursor-pointer'
                            : 'cursor-default'
                        }`}
                      >
                        {/* Mobile: stacked, route name always fully visible */}
                        <div className="px-3 py-2.5 sm:hidden">
                          <div className="flex items-center justify-between gap-2">
                            <span className="break-all font-mono text-sm font-medium text-white">{r.key}</span>
                            <span className="flex shrink-0 items-center gap-1.5 font-mono text-xs">
                              <span className={`h-1.5 w-1.5 rounded-full ${critical ? 'bg-red-400' : 'bg-emerald-400'}`}></span>
                              <span className={critical ? 'font-medium text-red-300' : 'text-emerald-400'}>{r.status}</span>
                              {hasDetails && (
                                <ChevronDown
                                  className={`h-3.5 w-3.5 text-red-300 transition-transform duration-200 ${
                                    expanded ? 'rotate-180' : ''
                                  }`}
                                />
                              )}
                            </span>
                          </div>
                          <div className="mt-2 flex items-center justify-between text-xs text-gray-400">
                            <span className="tabular-nums">p95: <strong className="text-gray-200">{r.p95}</strong></span>
                            <span className="tabular-nums">avg: <strong className="text-gray-200">{r.avg}</strong></span>
                            <span>queries: <strong className="text-gray-200">{r.queries}</strong></span>
                            {r.dupes !== '—' && (
                              <span className="rounded bg-red-500/10 px-1.5 py-0.5 text-[11px] font-medium text-red-400">
                                {r.dupes} N+1
                              </span>
                            )}
                          </div>
                        </div>

                        {/* Desktop row */}
                        <div className="hidden sm:grid sm:grid-cols-[minmax(0,2fr)_95px_95px_130px_75px] items-center gap-4 sm:gap-6 px-3 py-2 text-xs">
                          <div className="truncate font-mono font-medium text-white text-left">{r.key}</div>
                          <div className="w-full text-right justify-self-end font-mono tabular-nums text-gray-300">{r.p95}</div>
                          <div className="w-full text-right justify-self-end font-mono tabular-nums text-gray-400">{r.avg}</div>
                          <div className="w-full text-center justify-self-center">
                            <span
                              className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium ${
                                critical
                                  ? 'bg-red-500/10 text-red-300 ring-1 ring-red-500/20'
                                  : 'bg-emerald-500/10 text-emerald-300 ring-1 ring-emerald-500/20'
                              }`}
                            >
                              <span className={`h-1.5 w-1.5 rounded-full ${critical ? 'bg-red-400' : 'bg-emerald-400'}`}></span>
                              {r.status}
                            </span>
                          </div>
                          <div className="w-full text-right justify-self-end font-mono">
                            {r.dupes !== '—' ? (
                              <span className="flex items-center justify-end gap-1 font-medium text-red-400">
                                {r.dupes}
                                {hasDetails && (
                                  <ChevronDown
                                    className={`h-3 w-3 transition-transform duration-200 ${
                                      expanded ? 'rotate-180' : ''
                                    }`}
                                  />
                                )}
                              </span>
                            ) : (
                              <span className="text-gray-600">—</span>
                            )}
                          </div>
                        </div>
                      </button>

                      {/* Expandable N+1 details drawer */}
                      {expanded && hasDetails && (
                        <div className="border-t border-white/[0.04] bg-black/40 px-4 py-3 text-xs">
                          <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <div className="space-y-1">
                              <p className="font-mono text-[11px] text-gray-400">
                                <span className="text-red-400 font-semibold">{r.execs}</span> duplicate queries detected:
                              </p>
                              <code className="block font-mono text-[11px] text-red-300/90">
                                {r.pattern}
                              </code>
                            </div>
                            {r.source && (
                              <a
                                href="#docs"
                                className="inline-flex items-center gap-1 text-[11px] text-brand-accent hover:underline shrink-0"
                              >
                                <span>{r.source}</span>
                                <ArrowRight className="h-3 w-3" />
                              </a>
                            )}
                          </div>
                        </div>
                      )}
                    </React.Fragment>
                  );
                })}
              </div>
            </div>
          </div>
        </div>
      ) : (
        /* Source view */
        <div className="p-4 sm:p-5 font-mono text-xs">
          <div className="flex items-center justify-between text-gray-400 border-b border-white/[0.06] pb-2">
            <span>app/Http/Controllers/OrderController.php:84</span>
            <span className="text-red-400 font-semibold">14× N+1 query trigger</span>
          </div>
          <pre className="mt-3 overflow-x-auto text-[12px] leading-relaxed text-gray-300">
            <code>
              <span className="text-gray-600">82: </span>public function index()<br />
              <span className="text-gray-600">83: </span>&#123;<br />
              <span className="text-gray-600">84: </span>    $orders = Order::all();<br />
              <span className="text-red-300 bg-red-500/10 px-1 py-0.5 rounded">
                <span className="text-gray-600">85: </span>    foreach ($orders as $order) &#123; $order-&gt;items-&gt;count(); &#125;
              </span><br />
              <span className="text-gray-600">86: </span>    return view('orders.index', compact('orders'));<br />
              <span className="text-gray-600">87: </span>&#125;
            </code>
          </pre>
        </div>
      )}
    </div>
  );
}
