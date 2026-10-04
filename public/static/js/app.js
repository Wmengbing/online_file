(function (window, document, $) {
    'use strict';

    var body = document.body;
    var appConfig = window.APP_CONFIG || {
        csrfToken: body.getAttribute('data-csrf-token') || '',
        loginUrl: body.getAttribute('data-login-url') || '',
        logoutUrl: body.getAttribute('data-logout-url') || ''
    };

    function isSameOrigin(url) {
        var target = document.createElement('a');
        target.href = url || window.location.href;
        return !target.host || target.host === window.location.host;
    }

    $.ajaxPrefilter(function (options, originalOptions, jqXHR) {
        var method = (options.type || 'GET').toUpperCase();
        if (isSameOrigin(options.url) && !/^(GET|HEAD|OPTIONS)$/.test(method)) {
            jqXHR.setRequestHeader('X-CSRF-Token', appConfig.csrfToken || '');
        }
    });

    function requestError(xhr, callback) {
        var response = xhr && xhr.responseJSON ? xhr.responseJSON : null;
        var message = response && response.msg ? response.msg : '请求失败，请稍后重试';

        if (xhr && xhr.status === 419) {
            message = '页面已过期，正在刷新';
            layer.msg(message, {icon: 0, time: 1200}, function () {
                window.location.reload();
            });
        } else {
            layer.msg(message, {icon: 2});
        }

        if (callback) {
            callback(response || {code: xhr && xhr.status ? xhr.status : 500, msg: message});
        }
    }

    window.ajaxPost = function (url, data, callback) {
        var isFormData = data instanceof FormData;
        $.ajax({
            url: url,
            type: 'POST',
            data: data,
            processData: !isFormData,
            contentType: isFormData ? false : 'application/x-www-form-urlencoded; charset=UTF-8',
            dataType: 'json',
            success: function (res) {
                if (res.code == 200) {
                    layer.msg(res.msg || '操作成功', {icon: 1}, function () {
                        if (callback) {
                            callback(res);
                        } else {
                            window.location.reload();
                        }
                    });
                } else {
                    layer.msg(res.msg || '操作失败', {icon: 2});
                    if (callback) callback(res);
                }
            },
            error: function (xhr) {
                requestError(xhr, callback);
            }
        });
    };

    function setSubmitting($button, submitting) {
        if (!$button.length) return;
        if (submitting) {
            $button.data('original-html', $button.html());
            $button.prop('disabled', true).addClass('disabled');
            $button.html('<i class="fa fa-circle-o-notch fa-spin"></i> 正在提交');
        } else {
            $button.prop('disabled', false).removeClass('disabled');
            if ($button.data('original-html')) {
                $button.html($button.data('original-html'));
            }
        }
    }

    $(document).on('submit', 'form.ajax-form', function (event) {
        event.preventDefault();
        var $form = $(this);
        var url = $form.attr('action') || window.location.href;
        var $submit = $form.find('[type=submit]').first();
        var hasFile = $form.find('input[type=file]').length > 0;
        var payload = hasFile ? new FormData($form[0]) : $form.serialize();

        setSubmitting($submit, true);
        window.ajaxPost(url, payload, function (res) {
            setSubmitting($submit, false);
            if (res.code == 200) {
                var redirect = $form.data('redirect') || res.url || '';
                if (redirect) window.location.href = redirect;
            }
        });
    });

    $(document).on('click', '#logoutLink', function (event) {
        event.preventDefault();
        window.ajaxPost($(this).data('url') || appConfig.logoutUrl, {}, function (res) {
            window.location.href = res.url || appConfig.loginUrl || '/login.html';
        });
    });

    function closeSidebar() {
        document.body.classList.remove('sidebar-open');
        $('#appMenuToggle').attr('aria-expanded', 'false');
    }

    $(document).on('click', '#appMenuToggle', function () {
        var isOpen = document.body.classList.toggle('sidebar-open');
        $(this).attr('aria-expanded', isOpen ? 'true' : 'false');
    });

    $(document).on('click', '#appSidebarOverlay', closeSidebar);
    $(document).on('keydown', function (event) {
        if (event.key === 'Escape') closeSidebar();
    });

    function markActiveNavigation() {
        var path = window.location.pathname.replace(/\.html$/, '').replace(/^\/index(?=\/|$)/, '') || '/';
        var best = null;
        var bestScore = -1;

        $('[data-nav-match]').each(function () {
            var $link = $(this);
            var matches = String($link.data('nav-match') || '').split(',');
            matches.forEach(function (candidate) {
                candidate = $.trim(candidate);
                var exact = candidate === '/';
                var matched = exact ? path === '/' : (path === candidate || path.indexOf(candidate + '/') === 0);
                if (matched && candidate.length > bestScore) {
                    best = $link;
                    bestScore = candidate.length;
                }
            });
        });

        if (best) best.closest('li').addClass('active');
    }

    function makeTablesResponsive() {
        $('.table').each(function () {
            if (!$(this).closest('.table-responsive, .file-table-wrap').length) {
                $(this).wrap('<div class="table-responsive app-table-responsive"></div>');
            }
        });
    }

    function initFileBrowser() {
        var $browser = $('.file-browser');
        if (!$browser.length) return;

        var moveUrl = $browser.data('move-url');
        var copyUrl = $browser.data('copy-url');
        var zipUrl = $browser.data('zip-url');
        var deleteUrl = $browser.data('delete-url');
        var sourceParent = $browser.data('source-parent') || 0;

        function selectedIds() {
            return $('.file-check:checked').map(function () {
                return $(this).val();
            }).get();
        }

        function selectedCanManage() {
            var canManage = true;
            $('.file-check:checked').each(function () {
                if ($(this).closest('tr').data('can-manage') != 1) {
                    canManage = false;
                    return false;
                }
            });
            return canManage;
        }

        function updateBatchBar() {
            var count = selectedIds().length;
            var total = $('.file-check').length;
            $('#selCount').text(count);
            $('#batchBar').toggleClass('is-visible', count > 0);
            $('.batch-manage-btn').toggle(count > 0 && selectedCanManage());
            $('.file-check').each(function () {
                $(this).closest('tr').toggleClass('is-selected', this.checked);
            });
            $('#checkAll').prop('checked', total > 0 && count === total);
            $('#checkAll').prop('indeterminate', count > 0 && count < total);
        }

        function requireSelection(requireManage) {
            var ids = selectedIds();
            if (!ids.length) {
                layer.msg('请先选择文件', {icon: 0});
                return null;
            }
            if (requireManage && !selectedCanManage()) {
                layer.msg('选中项中包含无权操作的文件', {icon: 0});
                return null;
            }
            return ids;
        }

        $(document).on('change', '#checkAll', function () {
            $('.file-check').prop('checked', this.checked);
            updateBatchBar();
        });

        $(document).on('change', '.file-check', updateBatchBar);

        $(document).on('click', '[data-clear-selection]', function () {
            $('.file-check').prop('checked', false);
            updateBatchBar();
        });

        $(document).on('click', '[data-batch-action]', function () {
            var action = $(this).data('batch-action');
            var ids = requireSelection(action === 'move' || action === 'delete');
            if (!ids) return;
            var query = ids.map(function (id) {
                return 'file_ids[]=' + encodeURIComponent(id);
            }).join('&');

            if (action === 'move') {
                window.location.href = moveUrl + (moveUrl.indexOf('?') >= 0 ? '&' : '?') + query + '&source_parent_id=' + encodeURIComponent(sourceParent);
            } else if (action === 'copy') {
                window.location.href = copyUrl + (copyUrl.indexOf('?') >= 0 ? '&' : '?') + query + '&source_parent_id=' + encodeURIComponent(sourceParent);
            } else if (action === 'zip') {
                window.location.href = zipUrl + (zipUrl.indexOf('?') >= 0 ? '&' : '?') + query;
            } else if (action === 'delete') {
                layer.confirm('确定将选中的 ' + ids.length + ' 项移入回收站吗？', {icon: 3}, function () {
                    window.ajaxPost(deleteUrl, {file_ids: ids});
                });
            }
        });

        $(document).on('click', '[data-delete-id]', function (event) {
            event.preventDefault();
            var id = $(this).data('delete-id');
            layer.confirm('确定将此项目移入回收站吗？', {icon: 3}, function () {
                window.ajaxPost(deleteUrl, {file_ids: [id]});
            });
        });

        $(document).on('click', '[data-sort-field]', function (event) {
            event.preventDefault();
            var field = $(this).data('sort-field');
            var currentField = String($browser.data('sort-by'));
            var currentOrder = String($browser.data('sort-order'));
            var nextOrder = field === currentField && currentOrder === 'asc' ? 'desc' : 'asc';
            var params = new URLSearchParams(window.location.search);
            params.set('sort_by', field);
            params.set('sort_order', nextOrder);
            params.delete('page');
            window.location.href = window.location.pathname + '?' + params.toString();
        });

        function applyView(mode) {
            mode = mode === 'grid' ? 'grid' : 'list';
            $browser.toggleClass('is-grid', mode === 'grid');
            $('[data-file-view]').removeClass('active').attr('aria-pressed', 'false');
            $('[data-file-view="' + mode + '"]').addClass('active').attr('aria-pressed', 'true');
            try {
                window.localStorage.setItem('fileViewMode', mode);
            } catch (ignore) {}
        }

        $(document).on('click', '[data-file-view]', function () {
            applyView($(this).data('file-view'));
        });

        var savedView = 'list';
        try {
            savedView = window.localStorage.getItem('fileViewMode') || 'list';
        } catch (ignore) {}
        applyView(savedView);

        $(document).on('dblclick', '.file-item', function (event) {
            if ($(event.target).closest('a, button, input, .dropdown-menu').length) return;
            var openUrl = $(this).data('open-url');
            if (openUrl) window.location.href = openUrl;
        });

        updateBatchBar();
    }

    function initFolderPicker() {
        var $picker = $('.transfer-folder-picker');
        if (!$picker.length) return;

        $(document).on('input', '[data-folder-filter]', function () {
            var keyword = $.trim($(this).val()).toLowerCase();
            var visible = 0;
            $('[data-folder-option]').each(function () {
                var $option = $(this);
                var matched = !keyword || String($option.data('folder-name') || '').toLowerCase().indexOf(keyword) >= 0;
                $option.toggle(matched);
                if (matched) visible++;
            });
            $('[data-folder-empty]').toggle(visible === 0);
        });

        $(document).on('change', '.transfer-folder-option input[type="radio"]', function () {
            $('.transfer-folder-option').removeClass('is-selected');
            $(this).closest('.transfer-folder-option').addClass('is-selected');
        });
    }

    $(function () {
        markActiveNavigation();
        makeTablesResponsive();
        initFileBrowser();
        initFolderPicker();
    });
})(window, document, window.jQuery);
