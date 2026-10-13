<?php

/*
| AI providers are OpenAI-compatible Chat Completions APIs. Model IDs change often, so they are
| configurable per organization; the "Load models" button reads the list the account actually has.
*/

return [

    'providers' => [
        'openai' => [
            'label' => 'OpenAI',
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'default_model' => env('OPENAI_DEFAULT_MODEL'), // pick from "Load models"; no hard-coded guess
            'embeddings' => true,
            'default_embedding_model' => 'text-embedding-3-small',
            'max_tokens_param' => 'max_completion_tokens',
        ],
        'deepseek' => [
            'label' => 'DeepSeek',
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
            'default_model' => env('DEEPSEEK_DEFAULT_MODEL', 'deepseek-v4-flash'),
            'embeddings' => false, // DeepSeek has no embeddings endpoint; retrieval falls back to OpenAI embeddings or lexical search
            'max_tokens_param' => 'max_tokens',
        ],
    ],

    'timeout' => (int) env('AI_TIMEOUT_SECONDS', 30),

    'kb' => [
        'chunk_chars' => 900,
        'chunk_overlap' => 150,
        'max_file_kb' => (int) env('KB_MAX_FILE_KB', 10240),
        'top_k' => 5,
        'max_vector_scan' => 8000, // chunks scored in PHP per query; move to a vector DB beyond this
    ],

    'whatsapp' => [
        'graph_url' => env('WHATSAPP_GRAPH_URL', 'https://graph.facebook.com'),
        'default_api_version' => env('WHATSAPP_API_VERSION', 'v24.0'),
        'reply_debounce_seconds' => 4,
    ],

    // Always-on opt-out words (in addition to the assistant's handoff keywords). Case-insensitive, whole message.
    'opt_out_keywords' => ['stop', 'unsubscribe', 'opt out', 'optout', 'do not message', 'band karo', 'mat bhejo', 'إيقاف', 'الغاء', 'بند کرو', 'بند کریں'],

    'languages' => [
        'en' => 'English', 'ur' => 'Urdu (اردو)', 'roman_ur' => 'Roman Urdu', 'ar' => 'Arabic (العربية)',
        'hi' => 'Hindi', 'pa' => 'Punjabi', 'ps' => 'Pashto', 'fa' => 'Persian', 'tr' => 'Turkish', 'fr' => 'French', 'es' => 'Spanish',
    ],

    'qualification_fields' => [
        'name' => 'Customer name', 'email' => 'Email', 'city' => 'City / area', 'product_interest' => 'Product or service of interest',
        'budget' => 'Budget', 'timeline' => 'Buying / start timeline', 'preferred_contact_time' => 'Best time to call',
    ],

    /*
    | Industry presets pre-fill the assistant. Every preset keeps the assistant scoped to the business
    | (WhatsApp Business terms bar general-purpose AI assistants; business customer support is allowed).
    */
    'industries' => [
        'general' => [
            'label' => 'Service business',
            'persona' => 'You are the WhatsApp assistant for {business}. You answer questions about {business}\'s services, prices and process, and help interested customers get a call back from the team.',
            'instructions' => "Be warm, brief and professional. Ask one question at a time.\nCollect the customer's name, what they need, and a good time to call.",
            'fields' => ['name', 'product_interest', 'preferred_contact_time'],
        ],
        'real_estate' => [
            'label' => 'Real estate',
            'persona' => 'You are the WhatsApp property assistant for {business}. You help buyers and investors with projects, plot/apartment sizes, payment plans and site visits.',
            'instructions' => "Never quote a price, availability or possession date that is not in the knowledge base.\nQualify: preferred project/location, property type and size, budget, cash or installments, timeline.\nOffer a site visit or a call from an advisor once the customer is interested.",
            'fields' => ['name', 'city', 'product_interest', 'budget', 'timeline'],
        ],
        'education' => [
            'label' => 'Educational institute',
            'persona' => 'You are the admissions assistant for {business}. You answer questions about programs, fees, schedules, eligibility and admission deadlines.',
            'instructions' => "Qualify: program of interest, current education level, preferred campus/online, intended start date.\nOffer a counselling call for detailed questions.",
            'fields' => ['name', 'product_interest', 'city', 'timeline'],
        ],
        'healthcare' => [
            'label' => 'Healthcare provider',
            'persona' => 'You are the appointment assistant for {business}. You share clinic timings, services, doctors\' availability and how to book.',
            'instructions' => "Do NOT give medical advice, diagnoses or medication guidance; for symptoms, offer an appointment.\nIf someone describes an emergency, tell them to contact local emergency services immediately and hand off.\nCollect name, service needed and preferred appointment time. Do not ask for detailed medical history on WhatsApp.",
            'fields' => ['name', 'product_interest', 'preferred_contact_time'],
        ],
        'ecommerce' => [
            'label' => 'eCommerce store',
            'persona' => 'You are the customer assistant for {business}. You help with products, sizes, stock, delivery charges and times, payment methods and returns.',
            'instructions' => "Only state stock, prices and delivery times found in the knowledge base.\nFor order-status or complaint questions, collect the order number and hand off to the team.",
            'fields' => ['name', 'city', 'product_interest'],
        ],
        'agency' => [
            'label' => 'Digital marketing agency',
            'persona' => 'You are the new-business assistant for {business}, a digital marketing agency. You explain services and packages and book discovery calls.',
            'instructions' => "Qualify: business type, services needed (ads, SEO, social, web), monthly budget, timeline.\nBook a discovery call for anything beyond the published packages.",
            'fields' => ['name', 'email', 'product_interest', 'budget', 'timeline'],
        ],
        'support' => [
            'label' => 'Customer support',
            'persona' => 'You are the support assistant for {business}. You resolve common questions using the help articles in the knowledge base.',
            'instructions' => "Give step-by-step answers from the knowledge base.\nIf the issue is account-specific or unresolved after one attempt, hand off to a human agent.",
            'fields' => ['name', 'email'],
        ],
        'services' => [
            'label' => 'Home / professional services',
            'persona' => 'You are the booking assistant for {business}. You explain services, coverage areas and pricing, and arrange a visit or quote.',
            'instructions' => "Qualify: service needed, location/area, preferred date and time.\nOnly quote prices listed in the knowledge base; otherwise offer a free quote call.",
            'fields' => ['name', 'city', 'product_interest', 'preferred_contact_time'],
        ],
    ],
];
