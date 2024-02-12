<?php declare(strict_types=1);

namespace UserProfile;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\UserSettingsFieldset::class => Form\UserSettingsFieldset::class,
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'userprofile' => [
        'config' => [
            // The string from the config.
            'userprofile_elements' => '',
            // Hidden parameters.
            // Contains the list of field names, element types, labels,
            // required, is_multiple and some other data for quicker check
            // and process.
            'userprofile_fields' => [],
            // Contains the list of fields to exclude for quicker process.
            'userprofile_exclude' => [
                'admin' => ['show' => [], 'edit' => []],
                'public' => ['show' => [], 'edit' => []],
            ],
        ],
        // Keep standard key to simplify process with TraitModule.
        'user_settings' => [
        ],
    ],
];
