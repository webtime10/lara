<?php

return [

/*
| Языки, по которым строго проверяется готовность AI-поля (если в запросе не передан свой список).
*/
    'generation' => [
        'expected_languages' => ['ru', 'en', 'he', 'ar'],
        // Полный прогон: несколько AI-полей × языки × (OpenAI+OpenAI+Gemini) легко > 5 мин.
        'timeout_seconds' => (int) env('AI_GENERATION_TIMEOUT', 3600),
    ],

];
