<?php
// Never served as plain text: this file returns a PHP array, so even if a
// misconfigured server sends .php through as text, there's no key in the response.
//
// Same Yuki API key / administration as OHE/Reporting, per the yuki-closing-calendar
// skill decision — this app makes its own SOAP calls and shares the 1,000 calls/day cap.
//
// Fill these in directly. getenv() is not reliable on shared hosting that doesn't expose
// custom PHP environment variables (one.com included, apparently) — it silently returns
// false rather than erroring, which makes a missing value look like a broken login
// instead of a config problem. Hardcoding here matches how OHE/Reporting is set up.
return [
    'yuki_api_key'           => '15e3621c-e73a-46c8-8d41-1ca39a43f112',
    'yuki_administration_id' => 'df088343-9e14-4135-aae7-5e63df1fb79c',
    'password'               => '@OsakaEurope2026!*!',
    'debug'                  => false,
    'timezone'               => 'Europe/Brussels',
];
