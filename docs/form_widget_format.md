# 表单控件格式

## 1. 每个控件返回的数据说明
- require        : 是否必填，默认为 true
- label          : label, 默认从多语言 validation.attributes 中取值
- widget_type    : 建议的控件类型，默认为 text
- widget_status  : 控件状态，默认为 normal, 可选 { normal, readonly, disabled, hidden }
- placeholder    : 默认为空
- help           : 控件附加的提示文本内容
- append_attr    : 其它附加属性
- options        : 数据，可能是 select options ，radio, checkbox ...

### 1.1 widget_type 取值示例
- text      : 普通文本框
- select    : 下拉选框，配合 append_attr 里的 max_choice 指定是单选还是多选
- radio     : 单选
- checkbox  : 多选
- password  : 密码文本框
- date      : 日期，Ex: 2018-11-11
- datetime  : 日期时间, Ex: 2018-11-11 18:20:30
- textarea  : 普通多行文本框
- editor    : 富文本编辑器

### 1.2 其它附加属性 的一些示例
```json
.
.
.
    'append_attr' : {
        'max_choice'     :  2,       // select, checkbox 时, = -1 时不限, =0 是不能选， =1 单选
    },
.
.
.
    'append_attr' : {
        'min_length'     :  3,       // 字符串时，验证最小长度
        'max_length'     :  96,      // 字符串时，验证最大长度
    },
.
.
.
    'append_attr' : {
        'resize'         :  true,        // 前端是否按最大尺寸缩放图片
        'preview_size'   :  '160*160',   // 上传后预览尺寸
        'crop'           :  true,        // 是否裁剪
        'crop_size'      :  '320*320',   // 裁剪为
        'min_size'       :  '320*320',   // 上传文件的最小尺寸
        'max_file_size'  :  '4048',      // 上传文件的最大体积，单位kb
    }
.
.
.
    'append_attr' : {
        'time_start'     :  '1980-1-1 0:0:0',
    }
.
.
.
```

## 2. create form json 结果示例
```json
{
    "data": {
        'id' : {
            'require'        : true,
            'label'          : 'ID',
            'placeholder'    : '',
            'widget_type'    : 'text',
            'widget_status'  : 'readonly',
            'help'           : '',
        }
        'password' : {
            'require'        :  true,
            'label'          :  '密码',
            'widget_type'    :  'password',
            'widget_status'  :  'normal',
            'placeholder'    :  '',
            'help'           :  '',
        }
    }
}
```



