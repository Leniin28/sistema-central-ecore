<?php

return [
    'cutoff' => [
        'commit' => '74ec44b227a4ac256a05dba781cdb99ada3802c2',
        'committed_at' => '2026-08-19T00:22:33Z',
    ],

    /*
    |--------------------------------------------------------------------------
    | Casos históricos autorizados para diagnóstico
    |--------------------------------------------------------------------------
    |
    | Los folios son deliberadamente explícitos. Esta herramienta no intenta
    | inferir relaciones comerciales mediante coincidencias difusas.
    |
    */
    'cases' => [
        'cot-20260704-0001' => [
            'canonical_quote' => 'COT-20260704-0001',
            'canonical_order' => 'OS-20260704-0001',
            'source_quotes' => [],
            'source_orders' => ['OS-20260706-0001'],
        ],
        'cot-20260728-0001' => [
            'canonical_quote' => 'COT-20260728-0001',
            'canonical_order' => 'OS-20260728-0001',
            'source_quotes' => [],
            'source_orders' => [],
        ],
        'cot-20260731-0001' => [
            'canonical_quote' => 'COT-20260731-0001',
            'canonical_order' => 'OS-20260731-0001',
            'source_quotes' => [],
            'source_orders' => [],
        ],
        'cot-20260808-0001' => [
            'canonical_quote' => 'COT-20260808-0001',
            'canonical_order' => 'OS-20260808-0001',
            'source_quotes' => [],
            'source_orders' => [],
        ],
        'cot-20260809-0001' => [
            'canonical_quote' => 'COT-20260809-0001',
            'canonical_order' => 'OS-20260809-0001',
            'source_quotes' => [],
            'source_orders' => [],
        ],
        'cot-20260820-0001-0002' => [
            'canonical_quote' => 'COT-20260820-0001',
            'canonical_order' => 'OS-20260820-0001',
            'source_quotes' => ['COT-20260820-0002'],
            'source_orders' => ['OS-20260820-0002', 'OS-20260820-0003'],
        ],
    ],
];
