<?php
namespace UserProfile;

return [
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
    ],
    'form_elements' => [
        'invokables' => [
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
        'site_settings' => [
            'userprofile_field_1' => '',
            'userprofile_field_2' => '',
        ],
        'user_settings' => [
            'userprofile_field_1' => '',
            'userprofile_field_2' => '',
        ],
    ],
];
