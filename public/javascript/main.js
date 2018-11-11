(function($) {
    $(function() {
        // 导航底部滑块
        function setSlider($el) {
            var left = $el.position().left,
                width = $el.innerWidth();
            $('.slider').css('left', left + (width - 78) / 2);

            return left + (width - 78) / 2
        }
        var $menuActive = $('.menu').find('.active');

        var sliderLft = setSlider($menuActive);
        $('.collapse').find('.menu a').hover(function() {
            setSlider($(this))
        }, function() {
            $('.slider').css('left', sliderLft)
        });

        // 侧边栏
        $('.aside').on('click', 'a', function(){
            $(this).parent().toggleClass('open');
        })

        // 全选
        $('.checkbox-all').on('change', function(){
            var $items = $(this).parents('table').find('.checkbox');

            if(this.checked) {
                $items.each(function(){
                    this.checked = true;
                })
            } else {
                $items.each(function(){
                    this.checked = false;
                })
            }
        });

        // 单个选择
        $('.checkbox').on('change', function(){
            var $el = $(this).parents('table').find('.checkbox-all');

            if(!this.checked) {
                $el[0].checked = false;
            }
        });

        // 发送接口
        var validKey = function(el){
            var obj = {},
                flag = false;

            // 处理数组参数
            if(el === '#request_params') {
                flag = true;
            }

            $(el).find('.checkbox:checked').each(function(){
                var $tr = $(this).parents('tr'),
                    key = $tr.find('.key').val(),
                    value = $tr.find('.value').val();


                if(flag && value.indexOf(',') > -1) {
                    obj[key] = value.split(',');
                } else {
                    obj[key] = value
                }
            });

            return obj;
        }

        $.beautyOfCode && $.beautyOfCode.init();
        $('body').on('click', '#send', function(){
            if(!$(this).hasClass('disabled')) {
                var me = this,
                    $body = $('.dp-highlighter'),
                    uri = $('#host').val() + $('#uri').val();
                    mothod = $('#send_method').val(),
                    $status = $('#result_status');

                $(me).addClass('disabled');
                $body.html('<p class="requesting">Sending...</p>');
                $('#result_method').html(mothod);
                $('#result_uri').html(uri);

                $.ajax({
                    url: uri,
                    type: mothod,
                    dataType: 'json',
                    headers: validKey('#request_header'),
                    data: validKey('#request_params'),
                    success: function(json) {
                        if (json.data.token)
                        {
                            $.cookie('api_token', json.data.token);
                        }
                        
                        $body.html(formatJson(json));
                        $body.beautifyCode('javascript');
                        $(me).removeClass('disabled');
                        $status.html(200).attr('class', 'status font-green');
                    },
                    error: function(response) {
                        $body.html(formatJson(response));
                        $body.beautifyCode('javascript');
                        $(me).removeClass('disabled');
                        $status.html(response.status)

                        if(response.status == 401){
                            $status.attr('class', 'status font-orange');
                        } else {
                            $status.attr('class', 'status font-red');
                        }
                    }
                })
            }
        })

        // 点击copy
        if(typeof ClipboardJS !== 'undefined') {
            var showEm = function(e, type) {
                    var obj = {
                            success: 'green',
                            error: 'red'
                        },
                        $h3 = $(e.trigger).parents('.panel').find('.hd h3'),
                        $em = $('<em class="font-' + obj[type] + '">copy ' + type + '</em>');

                    $h3.append($em);
                    $em.animate({
                        opacity: 1
                    }, 1500, function(){
                        $(this).remove()
                    });

                    e.trigger.focus();
                },
                clipboard =  new ClipboardJS('.table .txt', {
                    text: function(e){
                        return $(e).val()
                    }
                });

            clipboard.on('success', function(e) {
                showEm(e, 'success');
            });

            clipboard.on('error', function(e) {
               showEm(e, 'error'); 
            });
        }

        // 参数输入方式切换
        var inpToTex = function(id) {
            var $tex = $('#' + id).parents('.panel').find('.edit-param'),
                option = validKey('#' + id),
                str = '';

            for(var key in option) {
                var value = option[key];
                
                if(Object.prototype.toString.call(value) === '[object Array]') {
                    // 数组
                    str += key + ': [';
                    for(var i = 0, len = value.length; i < len; i++) {
                        if(!isNaN(+value[i])) {
                            // 整型
                            str += value[i]
                        } else {
                            str += '"' + value[i] + '"'
                        }
                        if(i !== len - 1) {
                            str += ', '
                        }
                    }
                    str += '],<br/>';
                } else if(!isNaN(+value)){
                    // 整型
                    str += key + ': ' + value + ',<br/>';
                } else {
                    // 字符串
                    str += key + ': "' + value + '",<br/>';
                }    
            }

            var reStr = str.replace(/,<br\/>$/g, function(){
                return ''
            })
            $tex.html(reStr);
        }

        $('body').on('click', '.toggle', function(){
            var $el = $(this).parents('.panel').find('.tab-bd.active');
            $el.removeClass('active').siblings().addClass('active');

            if($(this).attr('_on') == 1){
                $(this).attr('_on', 0);
            } else {
                var id = $el.find('.table').attr('id');

                $(this).attr('_on', 1);
                inpToTex(id)
            }
        });

        // set input title
        $('.table').on('mouseover', '.txt', function(){
            $(this).attr('title', $(this).val());
        })

        // 头像下拉框
        var timerU;
        $('#user').hover(function(){
            clearTimeout(timerU);
            $(this).find('.drop-down').show();
        }, function(){
            var me = this;
            timerU = setTimeout(function() {
                $(me).find('.drop-down').hide();
            }, 300);
        })
    })
})(jQuery)


var formatJson = function(json, options) {
    var reg = null,
        formatted = '',
        pad = 0,
        PADDING = '    '; // one can also use '\t' or a different number of spaces
    // optional settings
    options = options || {};
    // remove newline where '{' or '[' follows ':'
    options.newlineAfterColonIfBeforeBraceOrBracket = (options.newlineAfterColonIfBeforeBraceOrBracket === true) ? true : false;
    // use a space after a colon
    options.spaceAfterColon = (options.spaceAfterColon === false) ? false : true;
    
    // begin formatting...
    
    // make sure we start with the JSON as a string
    if (typeof json !== 'string') {
        json = JSON.stringify(json);
    }
    // parse and stringify in order to remove extra whitespace
    json = JSON.parse(json);
    json = JSON.stringify(json);
    
    // add newline before and after curly braces
    reg = /([\{\}])/g;
    json = json.replace(reg, '\r\n$1\r\n');
    
    // add newline before and after square brackets
    reg = /([\[\]])/g;
    json = json.replace(reg, '\r\n$1\r\n');
    
    // add newline after comma
    reg = /(\,)/g;
    json = json.replace(reg, '$1\r\n');
    
    // remove multiple newlines
    reg = /(\r\n\r\n)/g;
    json = json.replace(reg, '\r\n');
    
    // remove newlines before commas
    reg = /\r\n\,/g;
    json = json.replace(reg, ',');
    
    // optional formatting...
    if (!options.newlineAfterColonIfBeforeBraceOrBracket) {
        reg = /\:\r\n\{/g;
        json = json.replace(reg, ':{');
        reg = /\:\r\n\[/g;
        json = json.replace(reg, ':[');
    }
    if (options.spaceAfterColon) {
        reg = /\:/g;
        json = json.replace(reg, ': ');
    }
    
    $.each(json.split('\r\n'), function(index, node) {
        var i = 0,
            indent = 0,
            padding = '';
        
        if (node.match(/\{$/) || node.match(/\[$/)) {
            indent = 1;
        } else if (node.match(/\}/) || node.match(/\]/)) {
            if (pad !== 0) {
                pad -= 1;
            }
        } else {
            indent = 0;
        }
        
        for (i = 0; i < pad; i++) {
            padding += PADDING;
        }
        
        formatted += padding + node + '\r\n';
        pad += indent;
    });
    
    return formatted;
};






















