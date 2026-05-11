<?php

return [
    'pix_discount_rate' => 0.05,
    'paradise_threshold_cents' => 35000,
    'shipping_cents' => [
        'free' => 0,
        'express' => 1847,
    ],
    'postback_url' => '',
    'gateways' => [
        'paradise' => [
            'base_url' => getenv('PARADISE_API_URL') ?: 'https://multi.paradisepags.com',
            'secret_key' => getenv('PARADISE_API_KEY') ?: 'sk_3217149c7f1e10cad6d7fe9deb40a99f9eb993957f991997f09148fcc32cde59',
        ],
        'anubis' => [
            'base_url' => getenv('ANUBIS_API_URL') ?: 'https://api.anubispay.com.br',
            'public_key' => getenv('ANUBIS_PUBLIC_KEY') ?: 'pk_oxECzrj7ix1BxRTp2MPQgYmCjzNKC-HDLI5ktA2jT7s-UO-V',
            'secret_key' => getenv('ANUBIS_SECRET_KEY') ?: 'sk_Vpj-MloXqD52tdig6eh4hdSOcBJvmSmbdW2LLiVBZmfnh9DS',
        ],
    ],
];
