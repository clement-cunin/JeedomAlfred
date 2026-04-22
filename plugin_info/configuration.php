<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

// JS i18n strings — always via json_encode to survive apostrophes in translations
$_js_i18n = [
    'mcp_autodetected'   => __('JeedomMCP settings auto-detected.', __FILE__),
    'enter_api_key'      => __('Enter the API key first.', __FILE__),
    'loading'            => __('Loading…', __FILE__),
    'error'              => __('Error', __FILE__),
    'server_name'        => __('Name', __FILE__),
    'server_slug'        => __('Slug', __FILE__),
    'server_url'         => __('URL', __FILE__),
    'server_auth_header' => __('Auth header', __FILE__),
    'server_auth_value'  => __('Auth value', __FILE__),
    'server_prefix'      => __('Prefix tools', __FILE__),
    'server_enabled'     => __('Enabled', __FILE__),
    'server_test'        => __('Test', __FILE__),
    'server_remove'      => __('Remove', __FILE__),
    'add_server'         => __('Add MCP server', __FILE__),
    'test_ok'            => __('OK — %d tool(s) found', __FILE__),
    'conflict_warning'   => __('Tool name conflicts detected — first declared server wins. Conflicting names: %s. Enable tool prefix on one of the servers to resolve.', __FILE__),
    'no_servers'         => __('No MCP servers configured. Alfred will work as a plain LLM without tools.', __FILE__),
];

$_providers = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI (GPT)',
    'gemini'    => 'Google (Gemini)',
];

// Auto-detect JeedomMCP settings (used for add button default URL)
$_mcpAutoUrl    = network::getNetworkAccess('internal', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';
$_mcpAutoApiKey = config::byKey('mcpApiKey', 'jeedomMCP');
?>

<form class="form-horizontal">
    <fieldset>

        <!-- ================================================================ -->
        <!-- LLM Provider -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-robot"></i> {{AI provider}}</legend>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8" style="margin-bottom:8px">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_test_llm">
                    <i class="fas fa-plug"></i> {{Test LLM connection}}
                </button>
                <span id="alfred_test_llm_result" style="margin-left:10px;font-size:13px"></span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Provider}}</label>
            <div class="col-sm-4">
                <select id="alfred_provider" class="configKey form-control" data-l1key="provider">
                    <?php foreach ($_providers as $_pid => $_plabel): ?>
                    <option value="<?php echo $_pid; ?>"><?php echo $_plabel; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <span class="help-block col-sm-4">{{Select the AI provider to use.}}</span>
        </div>

        <!-- Anthropic -->
        <div class="alfred-provider-section" data-provider="anthropic">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Anthropic API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="anthropic_api_key"
                           placeholder="sk-ant-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="anthropic_model">
                        <?php $_saved = config::byKey('anthropic_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- OpenAI -->
        <div class="alfred-provider-section" data-provider="openai">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{OpenAI API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="openai_api_key"
                           placeholder="sk-..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="openai_model">
                        <?php $_saved = config::byKey('openai_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Gemini -->
        <div class="alfred-provider-section" data-provider="gemini">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Gemini API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="gemini_api_key"
                           placeholder="AIza..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="gemini_model">
                        <?php $_saved = config::byKey('gemini_model', 'alfred'); ?>
                        <option value="<?php echo htmlspecialchars($_saved); ?>"><?php echo htmlspecialchars($_saved ?: '—'); ?></option>
                    </select>
                </div>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- MCP Servers -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-plug"></i> {{MCP servers}}</legend>

        <!-- Conflict warning banner -->
        <div id="alfred-mcp-conflict-banner" class="alert alert-warning" style="display:none;margin:0 15px 10px">
            <i class="fas fa-exclamation-triangle"></i>
            <span id="alfred-mcp-conflict-text"></span>
        </div>

        <!-- Hidden field — Jeedom reads/writes this as the config value -->
        <input type="hidden" class="configKey" data-l1key="mcp_servers" id="alfred_mcp_servers_json" />

        <!-- Server list -->
        <div id="alfred-mcp-server-list" style="margin:0 15px 10px">
            <!-- Rows rendered by JavaScript -->
        </div>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_add_server">
                    <i class="fas fa-plus"></i> {{Add MCP server}}
                </button>
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_autodetect_mcp" style="margin-left:8px">
                    <i class="fas fa-magic"></i> {{Auto-detect JeedomMCP}}
                </button>
            </div>
        </div>

        <!-- ================================================================ -->
        <!-- Agent settings -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-cogs"></i> {{Settings}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{System prompt}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="system_prompt" rows="4"
                          style="font-family: monospace; font-size: 12px;"></textarea>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Max agent iterations}}</label>
            <div class="col-sm-2">
                <input type="number" class="configKey form-control" data-l1key="max_iterations"
                       min="1" max="30" placeholder="10" />
            </div>
            <span class="help-block col-sm-6">{{Maximum number of tool-call iterations before the agent stops.}}</span>
        </div>

    </fieldset>
</form>

<style>
.alfred-mcp-server-card {
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px 12px;
    margin-bottom: 8px;
    background: #fafafa;
}
.alfred-mcp-server-card.disabled-server {
    opacity: 0.6;
}
.alfred-mcp-server-row {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: flex-end;
}
.alfred-mcp-server-row .alfred-field {
    display: flex;
    flex-direction: column;
}
.alfred-mcp-server-row .alfred-field label {
    font-size: 11px;
    color: #888;
    margin-bottom: 2px;
    font-weight: normal;
}
.alfred-mcp-field-name   { flex: 1 1 120px; min-width: 100px; }
.alfred-mcp-field-slug   { flex: 0 1 90px;  min-width: 70px; }
.alfred-mcp-field-url    { flex: 3 1 220px; min-width: 180px; }
.alfred-mcp-field-auth-h { flex: 1 1 110px; min-width: 90px; }
.alfred-mcp-field-auth-v { flex: 2 1 150px; min-width: 120px; }
.alfred-mcp-field-checks { flex: 0 0 auto; display: flex; flex-direction: column; gap: 2px; }
.alfred-mcp-field-actions { flex: 0 0 auto; display: flex; gap: 4px; align-items: flex-end; }
.alfred-mcp-test-result  { font-size: 12px; margin-top: 4px; min-height: 18px; }
</style>

<script>
var _alfredMcpAutoUrl    = <?php echo json_encode($_mcpAutoUrl); ?>;
var _alfredMcpAutoApiKey = <?php echo json_encode($_mcpAutoApiKey); ?>;
var _alfredI18n          = <?php echo json_encode($_js_i18n); ?>;

// ============================================================
// MCP Server list manager
// ============================================================

var _alfredMcpServers = [];          // in-memory list
var _alfredMcpToolCache = {};        // index => [tool names] (populated by Test)

function alfredMcpSerialize() {
    var json = JSON.stringify(_alfredMcpServers);
    $('#alfred_mcp_servers_json').val(json);
}

function alfredMcpDeserialize() {
    var raw = $('#alfred_mcp_servers_json').val();
    if (!raw) return [];
    try { return JSON.parse(raw); } catch(e) { return []; }
}

function alfredMcpRender() {
    var $list = $('#alfred-mcp-server-list');
    $list.empty();

    if (_alfredMcpServers.length === 0) {
        $list.append('<p class="text-muted" style="margin:8px 0 4px">' + _alfredI18n.no_servers + '</p>');
    }

    $.each(_alfredMcpServers, function(i, srv) {
        $list.append(alfredMcpBuildRow(i, srv));
    });

    alfredMcpCheckConflicts();
}

function alfredMcpBuildRow(i, srv) {
    var $card = $('<div class="alfred-mcp-server-card">');
    if (!srv.enabled) $card.addClass('disabled-server');

    var $row = $('<div class="alfred-mcp-server-row">');

    // Name
    var $name = $('<div class="alfred-field alfred-mcp-field-name">');
    $name.append($('<label>').text(_alfredI18n.server_name));
    $name.append($('<input type="text" class="form-control input-sm">').val(srv.name || '').on('input', function() {
        _alfredMcpServers[i].name = $(this).val();
        alfredMcpSerialize();
    }));
    $row.append($name);

    // Slug
    var $slug = $('<div class="alfred-field alfred-mcp-field-slug">');
    $slug.append($('<label>').text(_alfredI18n.server_slug));
    $slug.append($('<input type="text" class="form-control input-sm" placeholder="e.g. jeedom">').val(srv.slug || '').on('input', function() {
        _alfredMcpServers[i].slug = $(this).val();
        alfredMcpSerialize();
    }));
    $row.append($slug);

    // URL
    var $url = $('<div class="alfred-field alfred-mcp-field-url">');
    $url.append($('<label>').text(_alfredI18n.server_url));
    $url.append($('<input type="text" class="form-control input-sm" placeholder="http://...">').val(srv.url || '').on('input', function() {
        _alfredMcpServers[i].url = $(this).val();
        alfredMcpSerialize();
        delete _alfredMcpToolCache[i];
        alfredMcpCheckConflicts();
    }));
    $row.append($url);

    // Auth header
    var $authH = $('<div class="alfred-field alfred-mcp-field-auth-h">');
    $authH.append($('<label>').text(_alfredI18n.server_auth_header));
    $authH.append($('<input type="text" class="form-control input-sm" placeholder="X-API-Key">').val(srv.auth_header || '').on('input', function() {
        _alfredMcpServers[i].auth_header = $(this).val();
        alfredMcpSerialize();
    }));
    $row.append($authH);

    // Auth value (password field)
    var $authV = $('<div class="alfred-field alfred-mcp-field-auth-v">');
    $authV.append($('<label>').text(_alfredI18n.server_auth_value));
    $authV.append($('<input type="password" class="form-control input-sm" autocomplete="new-password">').val(srv.auth_value || '').on('input', function() {
        _alfredMcpServers[i].auth_value = $(this).val();
        alfredMcpSerialize();
    }));
    $row.append($authV);

    // Checkboxes (prefix + enabled)
    var $checks = $('<div class="alfred-field alfred-mcp-field-checks">');
    var $prefixLabel = $('<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px">');
    var $prefixCb = $('<input type="checkbox">').prop('checked', !!srv.prefix_tools).on('change', function() {
        _alfredMcpServers[i].prefix_tools = $(this).is(':checked');
        alfredMcpSerialize();
        alfredMcpCheckConflicts();
    });
    $prefixLabel.append($prefixCb).append(_alfredI18n.server_prefix);
    $checks.append($prefixLabel);

    var $enabledLabel = $('<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:12px">');
    var $enabledCb = $('<input type="checkbox">').prop('checked', srv.enabled !== false).on('change', function() {
        _alfredMcpServers[i].enabled = $(this).is(':checked');
        $card.toggleClass('disabled-server', !_alfredMcpServers[i].enabled);
        alfredMcpSerialize();
        alfredMcpCheckConflicts();
    });
    $enabledLabel.append($enabledCb).append(_alfredI18n.server_enabled);
    $checks.append($enabledLabel);
    $row.append($checks);

    // Action buttons + test result
    var $actions = $('<div class="alfred-field alfred-mcp-field-actions">');
    var $testResult = $('<div class="alfred-mcp-test-result">');

    var $btnUp = $('<button type="button" class="btn btn-default btn-xs" title="Move up"><i class="fas fa-arrow-up"></i></button>');
    $btnUp.prop('disabled', i === 0).on('click', function() { alfredMcpMove(i, -1); });

    var $btnDown = $('<button type="button" class="btn btn-default btn-xs" title="Move down"><i class="fas fa-arrow-down"></i></button>');
    $btnDown.prop('disabled', i === _alfredMcpServers.length - 1).on('click', function() { alfredMcpMove(i, 1); });

    var $btnTest = $('<button type="button" class="btn btn-default btn-xs">').text(_alfredI18n.server_test).prepend($('<i class="fas fa-plug" style="margin-right:4px">'));
    $btnTest.on('click', function() { alfredMcpTest(i, $testResult); });

    var $btnRemove = $('<button type="button" class="btn btn-danger btn-xs">').html('<i class="fas fa-trash"></i>');
    $btnRemove.on('click', function() { alfredMcpRemove(i); });

    $actions.append($btnUp).append($btnDown).append($btnTest).append($btnRemove);
    $row.append($actions);

    $card.append($row).append($testResult);

    // Restore cached test result if available
    if (_alfredMcpToolCache[i] !== undefined) {
        var n = _alfredMcpToolCache[i].length;
        $testResult.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + _alfredI18n.test_ok.replace('%d', n) + '</span>');
    }

    return $card;
}

function alfredMcpMove(i, dir) {
    var j = i + dir;
    if (j < 0 || j >= _alfredMcpServers.length) return;
    var tmp = _alfredMcpServers[i];
    _alfredMcpServers[i] = _alfredMcpServers[j];
    _alfredMcpServers[j] = tmp;
    // Swap tool cache too
    var tmpCache = _alfredMcpToolCache[i];
    _alfredMcpToolCache[i] = _alfredMcpToolCache[j];
    _alfredMcpToolCache[j] = tmpCache;
    alfredMcpSerialize();
    alfredMcpRender();
}

function alfredMcpRemove(i) {
    _alfredMcpServers.splice(i, 1);
    delete _alfredMcpToolCache[i];
    alfredMcpSerialize();
    alfredMcpRender();
}

function alfredMcpTest(i, $result) {
    var srv = _alfredMcpServers[i];
    if (!srv.url) {
        $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> URL required</span>');
        return;
    }
    $result.html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: {
            action:      'testMCP',
            url:         srv.url,
            auth_header: srv.auth_header || 'X-API-Key',
            auth_value:  srv.auth_value  || ''
        },
        dataType: 'json',
        success: function(data) {
            if (data.state === 'ok') {
                var n = data.result.count;
                _alfredMcpToolCache[i] = data.result.tools || [];
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + _alfredI18n.test_ok.replace('%d', n) + '</span>');
                alfredMcpCheckConflicts();
            } else {
                $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + (data.result || data.state) + '</span>');
            }
        },
        error: function(jqXHR) {
            $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + jqXHR.responseText + '</span>');
        }
    });
}

function alfredMcpCheckConflicts() {
    var seen = {};
    var conflicts = [];

    $.each(_alfredMcpServers, function(i, srv) {
        if (srv.enabled === false) return;
        var tools = _alfredMcpToolCache[i];
        if (!tools) return;
        $.each(tools, function(_, name) {
            var exposed = (srv.prefix_tools && srv.slug) ? srv.slug + '__' + name : name;
            if (seen[exposed]) {
                conflicts.push(exposed);
            } else {
                seen[exposed] = true;
            }
        });
    });

    var $banner = $('#alfred-mcp-conflict-banner');
    if (conflicts.length > 0) {
        var msg = _alfredI18n.conflict_warning.replace('%s', conflicts.join(', '));
        $('#alfred-mcp-conflict-text').text(msg);
        $banner.show();
    } else {
        $banner.hide();
    }
}

// ---- Add server ----
$('#bt_alfred_add_server').on('click', function() {
    _alfredMcpServers.push({
        name: '', slug: '', url: '',
        auth_header: 'X-API-Key', auth_value: '',
        prefix_tools: false, enabled: true
    });
    alfredMcpSerialize();
    alfredMcpRender();
});

// ---- Auto-detect JeedomMCP ----
$('#bt_alfred_autodetect_mcp').on('click', function() {
    _alfredMcpServers.push({
        name:         'JeedomMCP',
        slug:         'jeedom',
        url:          _alfredMcpAutoUrl,
        auth_header:  'X-API-Key',
        auth_value:   _alfredMcpAutoApiKey,
        prefix_tools: false,
        enabled:      true
    });
    alfredMcpSerialize();
    alfredMcpRender();
    $.fn.showAlert({ message: _alfredI18n.mcp_autodetected, level: 'success' });
});

// ---- LLM provider selector ----
function alfredShowProvider(provider) {
    $('.alfred-provider-section').hide();
    $('.alfred-provider-section[data-provider="' + provider + '"]').show();
}

// Wait until Jeedom has loaded config values, then render server list and provider
var _alfredPollCount = 0;
var _alfredProviderPoll = setInterval(function () {
    _alfredPollCount++;
    var hasKey = !!($('[data-l1key="anthropic_api_key"]').val()
                  || $('[data-l1key="openai_api_key"]').val()
                  || $('[data-l1key="gemini_api_key"]').val()
                  || $('[data-l1key="mcp_servers"]').val());
    if (hasKey || _alfredPollCount >= 20) {
        clearInterval(_alfredProviderPoll);
        alfredShowProvider($('#alfred_provider').val());
        // Load and render MCP server list
        _alfredMcpServers = alfredMcpDeserialize();
        alfredMcpRender();
    }
}, 100);

$('#alfred_provider').on('change', function () {
    alfredShowProvider($(this).val());
});

// ---- Lazy model loading ----
var _alfredModelsLoaded = {};

function alfredLoadModels($section) {
    var provider = $section.data('provider');
    if (_alfredModelsLoaded[provider]) return;
    _alfredModelsLoaded[provider] = true;

    var $select  = $section.find('.alfred-model-select');
    var apiKey   = $section.find('.alfred-api-key').val().trim();
    var savedVal = $select.val();

    if (!apiKey) return;

    $select.empty().append($('<option>').val('').text(_alfredI18n.loading).prop('disabled', true).prop('selected', true));
    $select.prop('disabled', true);

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'listModels', provider: provider, api_key: apiKey },
        dataType: 'json',
        success: function (resp) {
            $select.empty();
            if (resp.state !== 'ok') {
                $select.append($('<option>').val(savedVal).text(savedVal));
                $select.val(savedVal);
                return;
            }
            resp.result.forEach(function (m) {
                $select.append($('<option>').val(m.id).text(m.name));
            });
            if (savedVal && $select.find('option[value="' + savedVal + '"]').length) {
                $select.val(savedVal);
            } else {
                $select.prop('selectedIndex', 0);
            }
        },
        error: function () {
            $select.empty().append($('<option>').val(savedVal).text(savedVal));
            $select.val(savedVal);
        },
        complete: function () {
            $select.prop('disabled', false);
        }
    });
}

$(document).on('mousedown', '.alfred-model-select', function () {
    alfredLoadModels($(this).closest('.alfred-provider-section'));
});

$('#bt_alfred_test_llm').on('click', function () {
    var $btn      = $(this);
    var $result   = $('#alfred_test_llm_result');
    var provider  = $('#alfred_provider').val();
    var $section  = $('.alfred-provider-section[data-provider="' + provider + '"]');
    var apiKey    = $section.find('.alfred-api-key').val().trim();
    var model     = $section.find('.alfred-model-select').val();

    if (!apiKey) {
        $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + _alfredI18n.enter_api_key + '</span>');
        return;
    }

    $btn.prop('disabled', true);
    $result.html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'testLLM', provider: provider, api_key: apiKey, model: model },
        dataType: 'json',
        success: function (data) {
            if (data.state === 'ok') {
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + data.result.model + ' — OK</span>');
            } else {
                $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + (data.result || data.state) + '</span>');
            }
        },
        error: function (jqXHR) {
            $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + jqXHR.responseText + '</span>');
        },
        complete: function () {
            $btn.prop('disabled', false);
        }
    });
});
</script>
