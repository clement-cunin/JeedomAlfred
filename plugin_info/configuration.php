<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

include_file('core', 'alfredMigration', 'class', 'alfred');
$_schemaVersion       = alfredMigration::getVersion();
$_schemaTargetVersion = alfredMigration::getTargetVersion();

// JS i18n strings — always via json_encode to survive apostrophes in translations
$_js_i18n = [
    'mcp_autodetected'   => __('JeedomMCP settings auto-detected.', __FILE__),
    'enter_api_key'      => __('Enter the API key first.', __FILE__),
    'loading'            => __('Loading…', __FILE__),
    'error'              => __('Error', __FILE__),
    'up_to_date'         => __('Up to date', __FILE__),
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
    'add_memory'         => __('Add memory', __FILE__),
    'memory_label_ph'    => __('e.g. vacation-july', __FILE__),
    'memory_content_ph'  => __('Memory content…', __FILE__),
];

$_providers = [
    'mistral' => 'Mistral AI',
    'gemini'  => 'Google (Gemini)',
];

// Auto-detect JeedomMCP settings (used for add button default URL)
$_mcpAutoUrl    = network::getNetworkAccess('internal', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';
$_mcpAutoApiKey = config::byKey('mcpApiKey', 'jeedomMCP');
$_mcpRaw = config::byKey('mcp_servers', 'alfred');
// config::byKey auto-decodes JSON arrays — re-encode to get a plain string for JS injection
$_mcpServersJson = is_array($_mcpRaw) ? (json_encode($_mcpRaw) ?: '[]') : ($_mcpRaw ?: '[]');
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

        <!-- Mistral -->
        <div class="alfred-provider-section" data-provider="mistral">
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Mistral API key}}</label>
                <div class="col-sm-4">
                    <input type="password" class="configKey form-control alfred-api-key" data-l1key="mistral_api_key"
                           placeholder="..." autocomplete="new-password" />
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-4 control-label">{{Model}}</label>
                <div class="col-sm-4">
                    <select class="configKey form-control alfred-model-select" data-l1key="mistral_model">
                        <?php $_saved = config::byKey('mistral_model', 'alfred'); ?>
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

        <!-- Hidden field synced by JS. data-l1key ensures Jeedom's standard save also persists the value. -->
        <input type="hidden" id="alfred_mcp_servers_json" class="configKey" data-l1key="mcp_servers" />

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <!-- Conflict warning banner -->
                <div id="alfred-mcp-conflict-banner" class="alert alert-warning" style="display:none">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span id="alfred-mcp-conflict-text"></span>
                </div>
                <!-- Server list -->
                <div id="alfred-mcp-server-list"></div>
            </div>
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
        <!-- Database -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-database"></i> {{Database}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Schema version}}</label>
            <div class="col-sm-4" style="padding-top:7px">
                <?php if ($_schemaVersion >= $_schemaTargetVersion): ?>
                <span style="color:#3c763d">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $_schemaVersion; ?> / <?php echo $_schemaTargetVersion; ?> — {{Up to date}}
                </span>
                <?php else: ?>
                <span style="color:#8a6d3b">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo $_schemaVersion; ?> / <?php echo $_schemaTargetVersion; ?> — {{Outdated}}
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_repair_db">
                    <i class="fas fa-wrench"></i> {{Repair database}}
                </button>
                <span id="alfred_repair_db_result" style="margin-left:10px;font-size:13px"></span>
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

        <!-- ================================================================ -->
        <!-- Onboarding -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-user-plus"></i> {{Onboarding}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{First install prompt}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="first_install_prompt" rows="5"
                          style="font-family: monospace; font-size: 12px;"
                          placeholder="{{Injected when Alfred has never spoken to anyone yet.}}"></textarea>
            </div>
            <span class="help-block col-sm-2">{{Triggered when the memory is completely empty.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{New user prompt}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="new_user_prompt" rows="5"
                          style="font-family: monospace; font-size: 12px;"
                          placeholder="{{Injected the first time Alfred talks to a new user.}}"></textarea>
            </div>
            <span class="help-block col-sm-2">{{Triggered when this user has no personal memories yet.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Max agent iterations}}</label>
            <div class="col-sm-2">
                <input type="number" class="configKey form-control" data-l1key="max_iterations"
                       min="1" max="30" placeholder="10" />
            </div>
            <span class="help-block col-sm-6">{{Maximum number of tool-call iterations before the agent stops.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Show quota usage bar}}</label>
            <div class="col-sm-2" style="padding-top:7px">
                <input type="checkbox" class="configKey" data-l1key="show_quota_bar" />
            </div>
            <span class="help-block col-sm-6">{{Display a bar showing remaining LLM quota after each response (Mistral only for now).}}</span>
        </div>

        <!-- ================================================================ -->
        <!-- Memory -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-brain"></i> {{Memory}}</legend>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8" style="margin-bottom:8px">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_load_memories">
                    <i class="fas fa-sync-alt"></i> {{Load memories}}
                </button>
                <button type="button" class="btn btn-success btn-sm" id="bt_alfred_add_memory" style="margin-left:8px">
                    <i class="fas fa-plus"></i> {{Add memory}}
                </button>
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-1 col-sm-10">
                <table class="table table-bordered table-condensed" id="alfred_memory_table" style="display:none">
                    <thead>
                        <tr>
                            <th style="width:40px">{{ID}}</th>
                            <th style="width:120px">{{Scope}}</th>
                            <th style="width:150px">{{Label}}</th>
                            <th>{{Content}}</th>
                            <th style="width:140px">{{Date}}</th>
                            <th style="width:60px"></th>
                        </tr>
                    </thead>
                    <tbody id="alfred_memory_tbody"></tbody>
                </table>
                <div id="alfred_memory_empty" style="display:none;color:#888;font-style:italic">
                    {{No memories saved yet.}}
                </div>
            </div>
        </div>

    </fieldset>
</form>

<style>
.alfred-mcp-entry {
    display: flex;
    align-items: flex-start;
    gap: 4px;
    margin-bottom: 8px;
}
.alfred-mcp-server-card {
    flex: 1;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 10px 12px;
    background: #fafafa;
    min-width: 0;
}
.alfred-mcp-server-card.disabled-server {
    opacity: 0.6;
}
.alfred-mcp-side-actions {
    display: flex;
    flex-direction: column;
    gap: 3px;
    flex-shrink: 0;
    padding-top: 2px;
}
.alfred-mcp-line {
    display: flex;
    gap: 8px;
    align-items: flex-end;
    margin-bottom: 6px;
}
.alfred-mcp-line:last-child { margin-bottom: 0; }
.alfred-mcp-check-line { align-items: center; }
.alfred-field {
    display: flex;
    flex-direction: column;
    flex: 1;
    min-width: 0;
}
.alfred-field label {
    font-size: 11px;
    color: #888;
    margin-bottom: 2px;
    font-weight: normal;
}
.alfred-mcp-check-label {
    display: flex;
    align-items: center;
    gap: 6px;
    cursor: pointer;
    font-weight: normal;
    margin: 0;
    font-size: 13px;
}
.alfred-mcp-test-result {
    font-size: 12px;
    line-height: 30px;
    margin-left: 6px;
}
</style>

<script>
var _alfredMcpAutoUrl      = <?php echo json_encode($_mcpAutoUrl); ?>;
var _alfredMcpAutoApiKey   = <?php echo json_encode($_mcpAutoApiKey); ?>;
var _alfredMcpServersJson  = <?php echo json_encode($_mcpServersJson); ?>;
var _alfredI18n            = <?php echo json_encode($_js_i18n); ?>;
var _alfredSchemaTarget    = <?php echo (int) $_schemaTargetVersion; ?>;

$('#bt_alfred_repair_db').on('click', function () {
    var $btn    = $(this);
    var $result = $('#alfred_repair_db_result');

    $btn.prop('disabled', true);
    $result.html('<i class="fas fa-spinner fa-spin"></i>');

    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'runMigrations' },
        dataType: 'json',
        success: function (data) {
            if (data.state === 'ok') {
                var v = data.result;
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + v + ' / ' + _alfredSchemaTarget + ' — ' + _alfredI18n.up_to_date + '</span>');
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

// ============================================================
// MCP Server list manager
// ============================================================

var _alfredMcpServers = [];          // in-memory list
var _alfredMcpToolCache = {};        // index => [tool names] (populated by Test)
var _alfredMcpSaveTimer = null;

function alfredMcpSerialize(skipSave) {
    var json = JSON.stringify(_alfredMcpServers);
    $('#alfred_mcp_servers_json').val(json);
    // Jeedom's configKey mechanism skips input[type="hidden"] on save, so we persist
    // explicitly. Debounced to avoid an AJAX call on every keystroke.
    if (!skipSave) {
        clearTimeout(_alfredMcpSaveTimer);
        _alfredMcpSaveTimer = setTimeout(function() {
            $.ajax({
                type: 'POST',
                url: 'plugins/alfred/core/ajax/alfred.ajax.php',
                data: { action: 'saveMCPServers', mcp_servers: json },
                dataType: 'json'
            });
        }, 600);
    }
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

    // ---- Name ----
    var $nameWrap = $('<div class="alfred-field">');
    $nameWrap.append($('<label>').text(_alfredI18n.server_name));
    $nameWrap.append($('<input type="text" class="form-control input-sm">').val(srv.name || '').on('input', function() {
        _alfredMcpServers[i].name = $(this).val();
        alfredMcpSerialize();
    }));
    $card.append($('<div class="alfred-mcp-line">').append($nameWrap));

    // ---- URL ----
    var $urlWrap = $('<div class="alfred-field">');
    $urlWrap.append($('<label>').text(_alfredI18n.server_url));
    $urlWrap.append($('<input type="text" class="form-control input-sm" placeholder="http://...">').val(srv.url || '').on('input', function() {
        _alfredMcpServers[i].url = $(this).val();
        alfredMcpSerialize();
        delete _alfredMcpToolCache[i];
        alfredMcpCheckConflicts();
    }));
    $card.append($('<div class="alfred-mcp-line">').append($urlWrap));

    // ---- Auth header + value ----
    var $authHWrap = $('<div class="alfred-field" style="flex:1">');
    $authHWrap.append($('<label>').text(_alfredI18n.server_auth_header));
    $authHWrap.append($('<input type="text" class="form-control input-sm" placeholder="X-API-Key">').val(srv.auth_header || '').on('input', function() {
        _alfredMcpServers[i].auth_header = $(this).val();
        alfredMcpSerialize();
    }));

    var $authVWrap = $('<div class="alfred-field" style="flex:2">');
    $authVWrap.append($('<label>').text(_alfredI18n.server_auth_value));
    $authVWrap.append($('<input type="password" class="form-control input-sm" autocomplete="new-password">').val(srv.auth_value || '').on('input', function() {
        _alfredMcpServers[i].auth_value = $(this).val();
        alfredMcpSerialize();
    }));
    $card.append($('<div class="alfred-mcp-line">').append($authHWrap).append($authVWrap));

    // ---- Prefix tools + slug ----
    var $prefixCb = $('<input type="checkbox">').prop('checked', !!srv.prefix_tools).on('change', function() {
        _alfredMcpServers[i].prefix_tools = $(this).is(':checked');
        alfredMcpSerialize();
        alfredMcpCheckConflicts();
    });
    var $prefixLabel = $('<label class="alfred-mcp-check-label">').append($prefixCb).append(' ' + _alfredI18n.server_prefix);

    var $slugWrap = $('<div class="alfred-field" style="flex:0 0 auto;width:160px">');
    $slugWrap.append($('<label>').text(_alfredI18n.server_slug));
    $slugWrap.append($('<input type="text" class="form-control input-sm" placeholder="e.g. jeedom">').val(srv.slug || '').on('input', function() {
        _alfredMcpServers[i].slug = $(this).val();
        alfredMcpSerialize();
    }));
    $card.append($('<div class="alfred-mcp-line alfred-mcp-check-line">').append($prefixLabel).append($slugWrap));

    // ---- Enabled ----
    var $enabledCb = $('<input type="checkbox">').prop('checked', srv.enabled !== false).on('change', function() {
        _alfredMcpServers[i].enabled = $(this).is(':checked');
        $card.toggleClass('disabled-server', !_alfredMcpServers[i].enabled);
        alfredMcpSerialize();
        alfredMcpCheckConflicts();
    });
    var $enabledLabel = $('<label class="alfred-mcp-check-label">').append($enabledCb).append(' ' + _alfredI18n.server_enabled);
    $card.append($('<div class="alfred-mcp-line alfred-mcp-check-line">').append($enabledLabel));

    // ---- Test button + result ----
    var $testResult = $('<div class="alfred-mcp-test-result">');
    var $btnTest = $('<button type="button" class="btn btn-default btn-sm">').prepend($('<i class="fas fa-plug" style="margin-right:6px">')).append(_alfredI18n.server_test);
    $btnTest.on('click', function() { alfredMcpTest(i, $testResult); });
    $card.append($('<div class="alfred-mcp-line">').append($btnTest).append($testResult));

    if (_alfredMcpToolCache[i] !== undefined) {
        var n = _alfredMcpToolCache[i].length;
        $testResult.html('<span style="color:#3c763d"><i class="fas fa-check"></i> ' + _alfredI18n.test_ok.replace('%d', n) + '</span>');
    }

    // ---- Side actions (outside card): up/down only shown with 2+ servers ----
    var $side = $('<div class="alfred-mcp-side-actions">');
    if (_alfredMcpServers.length > 1) {
        var $btnUp = $('<button type="button" class="btn btn-default btn-xs" title="Move up"><i class="fas fa-arrow-up"></i></button>');
        $btnUp.prop('disabled', i === 0).on('click', function() { alfredMcpMove(i, -1); });

        var $btnDown = $('<button type="button" class="btn btn-default btn-xs" title="Move down"><i class="fas fa-arrow-down"></i></button>');
        $btnDown.prop('disabled', i === _alfredMcpServers.length - 1).on('click', function() { alfredMcpMove(i, 1); });

        $side.append($btnUp).append($btnDown);
    }
    var $btnRemove = $('<button type="button" class="btn btn-danger btn-xs" title="Remove"><i class="fas fa-trash"></i></button>');
    $btnRemove.on('click', function() { alfredMcpRemove(i); });
    $side.append($btnRemove);

    return $('<div class="alfred-mcp-entry">').append($card).append($side);
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

// Initialize MCP server list immediately from PHP-injected value — no polling needed.
// Relying on Jeedom's configKey AJAX load has a race condition: if the AJAX response
// arrives after the poll timeout (2s), the hidden field is empty when we first read it.
(function () {
    try { _alfredMcpServers = JSON.parse(_alfredMcpServersJson); } catch (e) { _alfredMcpServers = []; }
    alfredMcpSerialize(true);  // sync hidden field only, no save on init
    alfredMcpRender();
})();

// Wait until Jeedom has loaded config values, then show the correct provider section.
var _alfredPollCount = 0;
var _alfredProviderPoll = setInterval(function () {
    _alfredPollCount++;
    var hasKey = !!($('[data-l1key="mistral_api_key"]').val()
                  || $('[data-l1key="gemini_api_key"]').val());
    if (hasKey || _alfredPollCount >= 20) {
        clearInterval(_alfredProviderPoll);
        alfredShowProvider($('#alfred_provider').val());
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

// ---- Memory management ----

var _alfredMemories = [];

function alfredScopeOptions(currentScope) {
    var scopes = ['global'];
    _alfredMemories.forEach(function (m) {
        if (m.scope !== 'global' && scopes.indexOf(m.scope) === -1) {
            scopes.push(m.scope);
        }
    });
    var html = '';
    scopes.forEach(function (s) {
        var sel = s === currentScope ? ' selected' : '';
        html += '<option value="' + s + '"' + sel + '>' + s + '</option>';
    });
    return html;
}

function alfredMemoryRowView(m) {
    var scopeBadge = m.scope === 'global'
        ? '<span class="label label-primary">global</span>'
        : '<span class="label label-default">' + m.scope + '</span>';
    var date  = m.created_at ? m.created_at.substring(0, 16) : '';
    var label = m.label ? '<code>' + $('<span>').text(m.label).html() + '</code>' : '<em style="color:#aaa">—</em>';
    return '<td><small>#' + m.id + '</small></td>'
        + '<td class="alfred-mem-scope-cell">' + scopeBadge + '</td>'
        + '<td class="alfred-mem-label-cell">' + label + '</td>'
        + '<td class="alfred-mem-content-cell" style="word-break:break-word">' + $('<span>').text(m.content).html() + '</td>'
        + '<td><small>' + date + '</small></td>'
        + '<td style="white-space:nowrap">'
        + '<button type="button" class="btn btn-xs btn-default alfred-memory-edit" data-id="' + m.id + '" title="{{Edit}}"><i class="fas fa-pencil-alt"></i></button> '
        + '<button type="button" class="btn btn-xs btn-danger alfred-memory-delete" data-id="' + m.id + '" title="{{Delete}}"><i class="fas fa-trash"></i></button>'
        + '</td>';
}

function alfredMemoryRowEdit(m) {
    return '<td><small>#' + m.id + '</small></td>'
        + '<td><select class="form-control input-sm alfred-mem-scope-input" style="min-width:110px">' + alfredScopeOptions(m.scope) + '</select></td>'
        + '<td><input type="text" class="form-control input-sm alfred-mem-label-input" value="' + $('<span>').text(m.label || '').html() + '" style="font-size:12px;font-family:monospace"></td>'
        + '<td><textarea class="form-control alfred-mem-content-input" rows="2" style="font-size:12px">' + $('<span>').text(m.content).html() + '</textarea></td>'
        + '<td></td>'
        + '<td style="white-space:nowrap">'
        + '<button type="button" class="btn btn-xs btn-success alfred-memory-save" data-id="' + m.id + '" title="{{Save}}"><i class="fas fa-check"></i></button> '
        + '<button type="button" class="btn btn-xs btn-default alfred-memory-cancel" data-id="' + m.id + '" title="{{Cancel}}"><i class="fas fa-times"></i></button>'
        + '</td>';
}

function alfredRenderMemories(memories) {
    _alfredMemories = memories || [];
    var $tbody = $('#alfred_memory_tbody');
    var $table = $('#alfred_memory_table');
    var $empty = $('#alfred_memory_empty');
    $tbody.empty();
    if (_alfredMemories.length === 0) {
        $table.hide();
        $empty.show();
        return;
    }
    $empty.hide();
    $table.show();
    _alfredMemories.forEach(function (m) {
        $tbody.append($('<tr data-id="' + m.id + '">').html(alfredMemoryRowView(m)));
    });
}

$('#bt_alfred_load_memories').on('click', function () {
    var $btn = $(this);
    $btn.prop('disabled', true).find('i').addClass('fa-spin');
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'listMemories' },
        dataType: 'json',
        success: function (resp) {
            if (resp.state === 'ok') {
                alfredRenderMemories(resp.result);
            }
        },
        complete: function () {
            $btn.prop('disabled', false).find('i').removeClass('fa-spin');
        }
    });
});

$(document).on('click', '.alfred-memory-edit', function () {
    var id = $(this).data('id');
    var m  = _alfredMemories.filter(function (x) { return x.id == id; })[0];
    if (!m) return;
    $(this).closest('tr').html(alfredMemoryRowEdit(m));
});

$(document).on('click', '.alfred-memory-cancel', function () {
    var id = $(this).data('id');
    var m  = _alfredMemories.filter(function (x) { return x.id == id; })[0];
    if (!m) return;
    $(this).closest('tr').html(alfredMemoryRowView(m));
});

$(document).on('click', '.alfred-memory-save', function () {
    var $btn     = $(this);
    var id       = $btn.data('id');
    var $tr      = $btn.closest('tr');
    var label    = $tr.find('.alfred-mem-label-input').val().trim();
    var content  = $tr.find('.alfred-mem-content-input').val().trim();
    var scope    = $tr.find('.alfred-mem-scope-input').val();
    if (!label || !content) return;
    $btn.prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'updateMemory', id: id, label: label, content: content, scope: scope },
        dataType: 'json',
        success: function (resp) {
            if (resp.state === 'ok') {
                var m = _alfredMemories.filter(function (x) { return x.id == id; })[0];
                if (m) { m.label = label; m.content = content; m.scope = scope; }
                $tr.html(alfredMemoryRowView(m || { id: id, label: label, content: content, scope: scope, created_at: '' }));
            }
        },
        complete: function () { $btn.prop('disabled', false); }
    });
});

$(document).on('click', '.alfred-memory-delete', function () {
    var id  = $(this).data('id');
    var $tr = $(this).closest('tr');
    if (!confirm('{{Delete this memory?}}')) return;
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'deleteMemory', id: id },
        dataType: 'json',
        success: function (resp) {
            if (resp.state === 'ok') {
                _alfredMemories = _alfredMemories.filter(function (x) { return x.id != id; });
                $tr.remove();
                if ($('#alfred_memory_tbody tr').length === 0) {
                    $('#alfred_memory_table').hide();
                    $('#alfred_memory_empty').show();
                }
            }
        }
    });
});

$('#bt_alfred_add_memory').on('click', function () {
    var $tbody = $('#alfred_memory_tbody');
    var $table = $('#alfred_memory_table');
    var $empty = $('#alfred_memory_empty');

    // Avoid duplicate new-memory rows
    if ($tbody.find('.alfred-memory-create-save').length > 0) {
        $tbody.find('.alfred-mem-label-input').first().focus();
        return;
    }

    $empty.hide();
    $table.show();
    $tbody.prepend($('<tr data-new="1">').html(alfredMemoryRowNew()));
    $tbody.find('[data-new] .alfred-mem-label-input').focus();
});

function alfredMemoryRowNew() {
    return '<td><small>—</small></td>'
        + '<td><select class="form-control input-sm alfred-mem-scope-input" style="min-width:110px">' + alfredScopeOptions('global') + '</select></td>'
        + '<td><input type="text" class="form-control input-sm alfred-mem-label-input" placeholder="' + _alfredI18n.memory_label_ph + '" style="font-size:12px;font-family:monospace"></td>'
        + '<td><textarea class="form-control alfred-mem-content-input" rows="2" style="font-size:12px" placeholder="' + _alfredI18n.memory_content_ph + '"></textarea></td>'
        + '<td></td>'
        + '<td style="white-space:nowrap">'
        + '<button class="btn btn-xs btn-success alfred-memory-create-save" title="{{Save}}"><i class="fas fa-check"></i></button> '
        + '<button class="btn btn-xs btn-default alfred-memory-create-cancel" title="{{Cancel}}"><i class="fas fa-times"></i></button>'
        + '</td>';
}

$(document).on('click', '.alfred-memory-create-save', function () {
    var $btn    = $(this);
    var $tr     = $btn.closest('tr');
    var label   = $tr.find('.alfred-mem-label-input').val().trim();
    var content = $tr.find('.alfred-mem-content-input').val().trim();
    var scope   = $tr.find('.alfred-mem-scope-input').val();
    if (!label || !content) return;
    $btn.prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'createMemory', label: label, content: content, scope: scope },
        dataType: 'json',
        success: function (resp) {
            if (resp.state === 'ok') {
                var m = resp.result;
                _alfredMemories.unshift(m);
                $tr.attr('data-id', m.id).removeAttr('data-new').html(alfredMemoryRowView(m));
            }
        },
        complete: function () { $btn.prop('disabled', false); }
    });
});

$(document).on('click', '.alfred-memory-create-cancel', function () {
    $(this).closest('tr').remove();
    if ($('#alfred_memory_tbody tr').length === 0) {
        $('#alfred_memory_table').hide();
        $('#alfred_memory_empty').show();
    }
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
