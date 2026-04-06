<?php

return [

/*
| Языки, по которым строго проверяется готовность AI-поля (если в запросе не передан свой список).
*/
    'generation' => [
        'expected_languages' => ['ru', 'en', 'he', 'ar'],
        'timeout_seconds' => 300,
    ],

];
