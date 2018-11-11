# 表单控件格式

## todo
- 数组 key = 整数时，被去掉了

## php
```php
<?php

$form = [
    'group_1' => [
        'position_ids' => [
            'require'       => true,
            'label'         => '岗位',
            'field_type'    => 'array',
            'widget_type'   => 'select',
            'value'         => [],
            'placeholder'   => '',
            'x'             => 'readonly', // readonly, disabled
            'tip'           => '',
            'append_attr' => [
                'max_choice'    => 2,   // = -1 时不限, =0 是不能选， =1 单选
            ],
            'data' => [
                'key'  => 'value',
                'key2' => 'value2',
            ]
        ],
        'password' => [
            'require'       => true,
            'label'         => '密码',
            'field_type'    => 'varchar',
            'widget_type'   => 'password',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
        ],
        'logo' => [
            'require'       => true,
            'label'         => 'Logo',
            'field_type'    => 'varchar',
            'widget_type'   => 'upload',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
            'append_attr'   => [
                'resize'        => true,        // 前端是否按最大尺寸缩放图片
                'preview_size'  => '160*160',   // 上传后预览尺寸
                'crop'          => true,        // 是否裁剪
                'crop_size'     => '320*320',   // 裁剪为
                'min_size'      => '320*320',   // 上传文件的最小尺寸
                'max_file_size' => '4048',      // 上传文件的最大体积，单位kb
            ]
        ],
        'gender' => [
            'require'       => true,
            'label'         => '性别',
            'field_type'    => 'integer',   // widget_type == radio 时，也有 field_type = varchar
            'widget_type'   => 'radio',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
            'data'          => [
                [1, '男'],
                [2, '女'],
            ]
        ],
    ],
    'group_2' => [
        'tags' => [
            'require'       => true,
            'label'         => '标签',
            'field_type'    => 'array',
            'widget_type'   => 'checkbox',
            'value'         => [],
            'placeholder'   => '',
            'tip'           => '',
            'data'          => [
                '1' => '红色',
                '2' => '蓝色',
                '5' => '绿色'
            ]
        ],
        'birthday' => [
            'require'       => true,
            'label'         => '生日',
            'field_type'    => 'date',
            'widget_type'   => 'date',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
        ],
        'born_time' => [
            'require'       => true,
            'label'         => '出生日间',
            'field_type'    => 'timestamp',
            'widget_type'   => 'datetime',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
            'append_attr'   => [
                'time_start'    => '1980-1-1 0:0:0',
            ]
        ],
        'remark' => [
            'require'       => true,
            'label'         => '备注',
            'field_type'    => 'text',
            'widget_type'   => 'textarea',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
        ],
        'content' => [
            'require'       => true,
            'label'         => '新闻内容',
            'field_type'    => 'text',
            'widget_type'   => 'editor',
            'value'         => '',
            'placeholder'   => '',
            'tip'           => '',
        ],
    ],
    'buttons' => [
        'post' => [
            'primary'       => true,
            'label'         => '提交',
            'widget_type'   => 'submit',
            'value'         => '提交',
        ],
        'reset' => [
            'primary'       => false,
            'label'         => '重置',
            'widget_type'   => 'reset',
            'value'         => '重置',
        ],
        'return' => [
            'primary'       => false,
            'label'         => '返回',
            'widget_type'   => 'button',
            'value'         => '',
        ],
    ],
];
```
