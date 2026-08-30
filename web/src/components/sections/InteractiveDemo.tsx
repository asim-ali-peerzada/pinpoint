import React, { useState } from 'react';
import { ArrowRight } from 'lucide-react';

type Tab = 'nplus1' | 'slowroute' | 'source';

interface CodeLine {
  n: string;
  text: string;
}

interface Diagnostic {
  file: string;
  code: CodeLine[];
  highlight: number;
  annotation: string;
  cta: string;
  route: string;
  metrics: [string, string][];
  finding: string[];
  findingTitle: string;
  findingValue: string;
  source: string;
}

const diagnostics: Record<Tab, Diagnostic> = {
  nplus1: {
    file: 'app/Http/Controllers/UserController.php',
    code: [
      { n: '1', text: '$users = User::all();' },
      { n: '2', text: '' },
      { n: '3', text: 'foreach ($users as $user) {' },
      { n: '4', text: "    echo $user->posts->count();" },
      { n: '5', text: '}' },
    ],
    highlight: 3,
    annotation: '14 relationship queries',
    cta: 'Detect issue →',
    route: '/api/users',
    metrics: [
      ['P95', '1.42s'],
      ['Queries', '42'],
    ],
    findingTitle: 'N+1 detected',
    finding: ['User → posts', '14 repeated queries'],
    findingValue: '',
    source: 'UserController.php:4',
  },
  slowroute: {
    file: 'config/pinpoint.php',
    code: [
      { n: '1', text: "'route_threshold_overrides' => [" },
      { n: '2', text: "    'exports.csv' => 5000," },
      { n: '3', text: '],' },
    ],
    highlight: 1,
    annotation: 'exports.csv · 5000ms threshold',
    cta: 'Inspect this call →',
    route: 'route: exports.csv',
    metrics: [
      ['P95', '3.0s'],
      ['Threshold', '5.0s'],
    ],
    findingTitle: 'Serving, no alert',
    finding: ['3000ms » 5000ms threshold', 'CRITICAL alert suppressed'],
    findingValue: '',
    source: 'config/pinpoint.php:2',
  },
  source: {
    file: 'app/Http/Controllers/OrderController.php',
    code: [
      { n: '82', text: 'public function index()' },
      { n: '83', text: '{' },
      { n: '84', text: '    $orders = Order::where(...)->get();' },
      { n: '85', text: '    return view("orders.index");' },
    ],
    highlight: 2,
    annotation: 'items relation · 14 duplicates',
    cta: 'Drill in →',
    route: 'api.orders.index',
    metrics: [
      ['P95', '1.42s'],
      ['Duplicates', '14×'],
    ],
    findingTitle: 'Persisted telemetry',
    finding: ['select * from items where order_id in (?)', 'executed 14×'],
    findingValue: '',
    source: 'OrderController.php:84',
  },
};

export default function InteractiveDemo() {
  const [tab, setTab] = useState<Tab>('nplus1');
  const [revealed, setRevealed] = useState(false);
  const d = diagnostics[tab];

  const switchTab = (t: Tab) => {
    setTab(t);
    setRevealed(false);
  };

  return (
    <div className="overflow-hidden rounded-lg border border-brand-border bg-brand-card">
      {/* Tabs */}
      <div className="flex flex-wrap gap-6 border-b border-white/[0.06] px-4 py-0">
        {(
          [
            ['nplus1', 'N+1 detection'],
            ['slowroute', 'Slow route'],
            ['source', 'Source navigation'],
          ] as [Tab, string][]
        ).map(([id, label]) => (
          <button
            key={id}
            onClick={() => switchTab(id)}
            className={`border-b-2 py-3 text-[13px] transition-colors ${
              tab === id
                ? 'border-brand-accent font-medium text-white'
                : 'border-transparent text-gray-500 hover:text-gray-300'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      <div className="grid md:grid-cols-2">
        {/* Code pane */}
        <div className="border-b border-white/[0.06] md:border-b-0 md:border-r">
          <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-2.5 text-xs">
            <span className="font-mono text-gray-400">{d.file}</span>
            <span className="text-gray-600">{d.code[d.highlight].n}</span>
          </div>
          <div className="p-4 font-mono text-[13px] leading-relaxed">
            {d.code.map((line, i) => (
              <div key={i}>
                {i === d.highlight ? (
                  <div className="relative rounded-sm bg-red-500/10 px-2 py-0.5">
                    <span className="mr-3 select-none text-gray-600">{line.n}</span>
                    <span className="text-rose-300">{line.text}</span>
                    <div className="mt-1">
                      <span className="text-[11px] text-gray-500">└─ </span>
                      <span className="text-[11px] text-red-300">{d.annotation}</span>
                    </div>
                  </div>
                ) : (
                  <div className="px-2 py-0.5">
                    <span className="mr-3 select-none text-gray-600">{line.n}</span>
                    <span className="text-gray-300">{line.text}</span>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>

        {/* Pinpoint pane */}
        <div className="flex flex-col">
          <div className="flex items-center justify-between border-b border-white/[0.06] px-4 py-2.5 text-xs">
            <span className="flex items-center gap-2 font-semibold uppercase tracking-wider text-gray-400">
              <span className="h-1.5 w-1.5 rounded-[3px] bg-brand-accent"></span>
              Pinpoint
            </span>
            <span className="font-mono text-gray-400">{d.route}</span>
          </div>

          {revealed ? (
            <div className="flex flex-1 flex-col p-4 font-mono text-[13px]">
              <div className="grid grid-cols-2 gap-4">
                {d.metrics.map(([label, value]) => (
                  <div key={label}>
                    <div className="text-[11px] uppercase tracking-wider text-gray-500">{label}</div>
                    <div className="mt-0.5 text-sm tabular-nums text-white">{value}</div>
                  </div>
                ))}
              </div>

              <div className="mt-4 rounded-md border border-red-500/25 bg-red-500/10 p-3">
                <div className="text-[11px] font-bold uppercase tracking-wider text-red-300">
                  {d.findingTitle}
                </div>
                {d.finding.map((f) => (
                  <div key={f} className="mt-1 text-gray-300">
                    {f}
                  </div>
                ))}
              </div>

              <div className="mt-4 flex items-center justify-between gap-3">
                <div>
                  <div className="text-[11px] uppercase tracking-wider text-gray-500">Source</div>
                  <div className="mt-0.5 text-white">{d.source}</div>
                </div>
                <button className="flex items-center gap-1 text-xs text-brand-accent hover:underline">
                  Open source <ArrowRight className="h-3 w-3" />
                </button>
              </div>
            </div>
          ) : (
            <div className="flex flex-1 flex-col items-center justify-center gap-3 p-6 text-center">
              <p className="text-sm text-gray-500">
                Run this request against Pinpoint to see the diagnosis.
              </p>
              <button
                onClick={() => setRevealed(true)}
                className="rounded-md border border-brand-border px-4 py-2 text-xs font-medium text-brand-accent transition-colors hover:border-brand-accent/40"
              >
                {d.cta}
              </button>
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
