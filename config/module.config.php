<?php
namespace GuestProfile;

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
    'guestprofile' => [
        'site_settings' => [
            'guestprofile_field_1' => '',
            'guestprofile_field_2' => '',
        ],
        'user_settings' => [
            'guestprofile_field_1' => '',
            'guestprofile_field_2' => '',
        ],
    ],
];
