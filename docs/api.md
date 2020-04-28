# api

(未完善，待续...)

## 参数说明

```yaml
controller:
    code: 001001
    class: Authorization
    name: 授权管理
    desc: []
actions:
    updatePersonnels_post:
        name: 更新角色人员
        desc: []
        prototype: ''
        rule_action: updatePersonnels  # 指定从哪个动作获取参数
        request: [POST, admin/authorizations/role/{id}/update-personnels]
        url_params: []
        body_params: []
```

**rule_action 的特别说明**

- 因为出现了同一个 url 多个 method，导致真实的动作未知，可通过 rule_action 指定
- 比如 一个控制器中有 GET createPersonnels 又有 POST storePersonnels，
- 为了简化授权，只要 createPersonnels ， 再从 createPersonnels 判断 isMethod('POST') 跳转到 storePersonnels
- 考虑是否有必要，可能会 remove it!
