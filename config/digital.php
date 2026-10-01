<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Digital product uploads
    |--------------------------------------------------------------------------
    |
    | Max size (in kilobytes) for a single uploaded digital file. The web
    | server / PHP limits (upload_max_filesize, post_max_size) must also be
    | raised to accommodate this value.
    |
    */
    'max_upload_kb' => (int) env('DIGITAL_UPLOAD_MAX_KB', 102400),

    /*
    | Allowed file extensions for digital products (e-books, documents, media).
    */
    'allowed_mimes' => [
        'pdf', 'epub', 'mobi',
        'zip',
        'doc', 'docx',
        'mp3', 'mp4', 'wav',
    ],

    /*
    | Defaults applied when a product does not override them.
    */
    'default_download_limit' => 5,

    'default_expiry_days' => 7,
];
