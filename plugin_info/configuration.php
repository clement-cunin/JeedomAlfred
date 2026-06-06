<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

include_file('core', 'alfredMigration', 'class', 'alfred');
alfredMigration::runPending();
include_file('core', 'alfred', 'class', 'alfred');
include_file('core', 'alfredPush', 'class', 'alfred');
alfredPush::ensureVapidKeys();
$_phones        = alfred::getPhones();
$_vapidPublicKey = alfredPush::getPublicKey();
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
    'chain_empty'        => __('No provider configured. Add at least one.', __FILE__),
    'add_memory'         => __('Add memory', __FILE__),
    'memory_label_ph'    => __('e.g. vacation-july', __FILE__),
    'memory_content_ph'  => __('Memory content…', __FILE__),
];

$_providers = [
    'mistral' => 'Mistral AI',
    'gemini'  => 'Google (Gemini)',
    'ollama'  => 'Ollama (local)',
];

// Provider chain (object format: [{id, type, api_key/base_url, model, enabled}, ...])
$_chainRaw     = config::byKey('provider_chain', 'alfred');
$_chainDecoded = is_array($_chainRaw) ? $_chainRaw : (json_decode((string)$_chainRaw, true) ?: []);
// Migrate old slug-array format in-place
if (!empty($_chainDecoded) && is_string($_chainDecoded[0])) {
    $tmp = [];
    foreach ($_chainDecoded as $slug) {
        $entry = ['id' => alfred::generateSessionId(), 'type' => $slug, 'enabled' => true];
        if ($slug === 'ollama') {
            $entry['base_url'] = (string)config::byKey('ollama_base_url', 'alfred') ?: 'http://localhost:11434';
        } else {
            $entry['api_key'] = (string)config::byKey($slug . '_api_key', 'alfred');
        }
        $entry['model'] = (string)config::byKey($slug . '_model', 'alfred');
        $tmp[] = $entry;
    }
    $_chainDecoded = $tmp;
    config::save('provider_chain', json_encode($_chainDecoded), 'alfred');
}
// Bootstrap default chain if still empty
if (empty($_chainDecoded)) {
    $slug = (string)config::byKey('provider', 'alfred') ?: 'mistral';
    $_chainDecoded = [[
        'id'      => alfred::generateSessionId(),
        'type'    => $slug,
        'api_key' => (string)config::byKey($slug . '_api_key', 'alfred'),
        'model'   => (string)config::byKey($slug . '_model', 'alfred'),
        'enabled' => true,
    ]];
    config::save('provider_chain', json_encode($_chainDecoded), 'alfred');
}
$_chainJson = json_encode($_chainDecoded) ?: '[]';

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

        <input type="text" id="alfred_provider_chain_json" class="configKey" data-l1key="provider_chain" style="display:none" tabindex="-1" aria-hidden="true" />

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <div id="alfred-chain-list"></div>
            </div>
        </div>
        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_chain_add">
                    <i class="fas fa-plus"></i> {{Add provider}}
                </button>
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

        <!-- ================================================================ -->
        <!-- Daily Journal -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-book"></i> {{Daily journal}}</legend>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Enable daily journal}}</label>
            <div class="col-sm-8" style="padding-top:7px">
                <input type="checkbox" class="configKey" data-l1key="journal_daily_enabled" />
                <span class="help-block" style="display:inline;margin-left:8px">
                    {{Each night, summarize the day's conversations into a per-user memory entry.}}
                </span>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Journal prompt}}</label>
            <div class="col-sm-6">
                <textarea class="configKey form-control" data-l1key="journal_daily_prompt" rows="5"
                          style="font-family: monospace; font-size: 12px;"
                          placeholder="{{Leave empty to use the default prompt.}}"></textarea>
            </div>
            <span class="help-block col-sm-2">{{LLM instruction for generating the journal entry. Leave empty for the default.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Journal expiry (days)}}</label>
            <div class="col-sm-2">
                <input type="number" class="configKey form-control" data-l1key="journal_daily_expiry_days"
                       min="0" max="365" placeholder="10" />
            </div>
            <span class="help-block col-sm-6">{{Days before the journal memory entry expires. 0 = never.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{Force run}}</label>
            <div class="col-sm-3">
                <input type="date" id="alfred_journal_run_date" class="form-control" />
            </div>
            <div class="col-sm-5" style="padding-top:4px">
                <button type="button" class="btn btn-warning btn-sm" id="bt_alfred_run_journal">
                    <i class="fas fa-play"></i> {{Run journal}}
                </button>
                <span id="alfred_journal_run_spinner" style="display:none;margin-left:8px">
                    <i class="fas fa-spinner fa-spin"></i>
                </span>
            </div>
        </div>
        <div id="alfred_journal_run_results" class="col-sm-offset-1 col-sm-10" style="display:none;margin-bottom:12px"></div>

        <!-- ================================================================ -->
        <!-- Phones -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-mobile-alt"></i> {{Phones}}</legend>

        <div class="form-group">
            <div class="col-sm-offset-1 col-sm-10">
                <?php if (empty($_phones)): ?>
                <p class="text-muted" style="margin:6px 0">
                    {{No phones registered yet. Open the Alfred PWA on a device and click «&nbsp;Activer les notifications&nbsp;» to register it.}}
                </p>
                <?php else: ?>
                <table class="table table-bordered table-condensed" id="alfred-phones-table">
                    <thead>
                        <tr>
                            <th>{{Name}}</th>
                            <th style="width:90px">{{Status}}</th>
                            <th style="width:110px">{{Subscriptions}}</th>
                            <th style="width:50px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($_phones as $phone): ?>
                        <tr id="alfred-phone-row-<?php echo (int)$phone->getId(); ?>">
                            <td><?php echo htmlspecialchars($phone->getName()); ?></td>
                            <td>
                                <?php if ($phone->getIsEnable()): ?>
                                <span class="label label-success">{{Enabled}}</span>
                                <?php else: ?>
                                <span class="label label-default">{{Disabled}}</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo count(alfredPush::listSubscriptions($phone->getId())); ?></td>
                            <td>
                                <button type="button" class="btn btn-xs btn-danger alfred-phone-delete"
                                        data-id="<?php echo (int)$phone->getId(); ?>"
                                        title="{{Delete}}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{VAPID public key}}</label>
            <div class="col-sm-5" style="padding-top:7px;word-break:break-all">
                <code style="font-size:11px"><?php echo htmlspecialchars($_vapidPublicKey); ?></code>
            </div>
            <div class="col-sm-3">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_regen_vapid">
                    <i class="fas fa-sync-alt"></i> {{Regenerate VAPID keys}}
                </button>
                <span id="alfred_regen_vapid_result" style="margin-left:8px;font-size:12px"></span>
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
.alfred-mcp-error-detail {
    background: #f8f8f8;
    border: 1px solid #ddd;
    border-radius: 3px;
    padding: 8px 10px;
    margin-top: 8px;
    max-height: 200px;
    overflow-y: auto;
    font-size: 11px;
    font-family: monospace;
    word-break: break-word;
    white-space: pre-wrap;
    line-height: 1.4;
}
.alfred-mcp-server-card.disabled-provider {
    opacity: 0.6;
}
.alfred-chain-test-result {
    font-size: 12px;
    margin-left: 6px;
    line-height: 30px;
}
</style>

<script>
var _alfredMcpAutoUrl      = <?php echo json_encode($_mcpAutoUrl); ?>;
var _alfredMcpAutoApiKey   = <?php echo json_encode($_mcpAutoApiKey); ?>;
var _alfredMcpServersJson  = <?php echo json_encode($_mcpServersJson); ?>;
var _alfredI18n            = <?php echo json_encode($_js_i18n); ?>;
var _alfredSchemaTarget    = <?php echo (int) $_schemaTargetVersion; ?>;
var _alfredChainJson       = <?php echo json_encode($_chainJson); ?>;
var _alfredProviderLabels  = <?php echo json_encode($_providers); ?>;

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
                var errMsg = data.result || data.state;
                var $err = $('<div style="color:#a94442"><i class="fas fa-times"></i> ' + errMsg + '</div>');
                $result.html($err);
            }
        },
        error: function(jqXHR) {
            var errText = jqXHR.responseText || 'Unknown error';
            var $container = $('<div>');
            $container.append($('<div style="color:#a94442"><i class="fas fa-times"></i> Error</div>'));
            $container.append($('<div class="alfred-mcp-error-detail">' + errText + '</div>'));
            $result.html($container);
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

// ============================================================
// Provider chain manager — card-based, one card per provider entry
// ============================================================

var _alfredChain = [];

function alfredChainSerialize() {
    $('#alfred_provider_chain_json').val(JSON.stringify(_alfredChain));
}

function alfredChainRender() {
    var $list = $('#alfred-chain-list');
    $list.empty();

    if (_alfredChain.length === 0) {
        $list.append('<p class="text-muted" style="margin:8px 0 4px">' + _alfredI18n.chain_empty + '</p>');
    }

    $.each(_alfredChain, function (i, entry) {
        var $entry = alfredChainBuildCard(i, entry);
        $list.append($entry);
        // Auto-load models in background if credential is already set
        var cred = (entry.type === 'ollama') ? (entry.base_url || '') : (entry.api_key || '');
        if (cred) {
            alfredChainLoadModels($entry.find('.alfred-mcp-server-card'));
        }
    });
}

function alfredChainBuildCard(i, entry) {
    var type     = entry.type    || 'mistral';
    var isOllama = (type === 'ollama');
    var credVal  = isOllama ? (entry.base_url || '') : (entry.api_key || '');
    var model    = entry.model   || '';
    var enabled  = (entry.enabled !== false);

    var $card = $('<div class="alfred-mcp-server-card">');
    if (!enabled) $card.addClass('disabled-provider');

    // Row 1: provider type + credential
    var $typeWrap = $('<div class="alfred-field" style="flex:0 0 auto;width:155px">');
    $typeWrap.append($('<label>').text('{{Provider}}'));
    var $typeSelect = $('<select class="form-control input-sm alfred-chain-type">');
    $.each(_alfredProviderLabels, function (slug, label) {
        $typeSelect.append($('<option>').val(slug).text(label));
    });
    $typeSelect.val(type);
    $typeSelect.on('change', (function (idx) {
        return function () {
            var t = $(this).val();
            _alfredChain[idx].type = t;
            delete _alfredChain[idx].api_key;
            delete _alfredChain[idx].base_url;
            delete _alfredChain[idx].model;
            alfredChainUpdateCredField($(this).closest('.alfred-mcp-server-card'), t);
            alfredChainSerialize();
        };
    })(i));
    $typeWrap.append($typeSelect);

    var $credWrap = $('<div class="alfred-field" style="flex:2">');
    $credWrap.append($('<label class="alfred-chain-cred-label">'));
    var $credInput = $('<input class="form-control input-sm alfred-chain-cred" autocomplete="new-password">');
    $credInput.val(credVal);
    $credInput.on('input', (function (idx) {
        return function () {
            var t = _alfredChain[idx].type;
            if (t === 'ollama') { _alfredChain[idx].base_url = $(this).val(); delete _alfredChain[idx].api_key; }
            else                { _alfredChain[idx].api_key  = $(this).val(); delete _alfredChain[idx].base_url; }
            alfredChainSerialize();
        };
    })(i));
    $credWrap.append($credInput);
    $card.append($('<div class="alfred-mcp-line">').append($typeWrap).append($credWrap));
    alfredChainUpdateCredField($card, type);

    // Row 2: model
    var $modelWrap = $('<div class="alfred-field" style="flex:1">');
    $modelWrap.append($('<label>').text('{{Model}}'));
    var $modelSelect = $('<select class="form-control input-sm alfred-chain-model">');
    $modelSelect.append($('<option>').val(model).text(model || '—'));
    $modelSelect.val(model);
    $modelSelect.on('change', (function (idx) {
        return function () { _alfredChain[idx].model = $(this).val(); alfredChainSerialize(); };
    })(i));
    $modelWrap.append($modelSelect);
    $card.append($('<div class="alfred-mcp-line">').append($modelWrap));

    // Row 3: enabled + test
    var $enabledCb = $('<input type="checkbox">').prop('checked', enabled);
    $enabledCb.on('change', (function (idx) {
        return function () {
            _alfredChain[idx].enabled = $(this).is(':checked');
            $(this).closest('.alfred-mcp-server-card').toggleClass('disabled-provider', !_alfredChain[idx].enabled);
            alfredChainSerialize();
        };
    })(i));
    var $enabledLabel = $('<label class="alfred-mcp-check-label">').append($enabledCb).append(' {{Enabled}}');

    var $testResult = $('<span class="alfred-chain-test-result">');
    var $testBtn    = $('<button type="button" class="btn btn-default btn-sm">').html('<i class="fas fa-plug"></i> {{Test}}');
    $testBtn.on('click', (function (idx, $r) {
        return function () {
            var t    = _alfredChain[idx].type;
            var cred = (t === 'ollama') ? (_alfredChain[idx].base_url || '') : (_alfredChain[idx].api_key || '');
            alfredChainTest(t, cred, _alfredChain[idx].model || '', $r);
        };
    })(i, $testResult));

    $card.append($('<div class="alfred-mcp-line alfred-mcp-check-line">').append($enabledLabel).append($testBtn).append($testResult));

    // Side actions
    var $side = $('<div class="alfred-mcp-side-actions">');
    if (_alfredChain.length > 1) {
        var $btnUp = $('<button type="button" class="btn btn-default btn-xs" title="Move up"><i class="fas fa-arrow-up"></i></button>');
        $btnUp.prop('disabled', i === 0).on('click', (function (idx) { return function () { alfredChainMove(idx, -1); }; })(i));
        var $btnDown = $('<button type="button" class="btn btn-default btn-xs" title="Move down"><i class="fas fa-arrow-down"></i></button>');
        $btnDown.prop('disabled', i === _alfredChain.length - 1).on('click', (function (idx) { return function () { alfredChainMove(idx, 1); }; })(i));
        $side.append($btnUp).append($btnDown);
    }
    var $btnRemove = $('<button type="button" class="btn btn-danger btn-xs" title="Remove"><i class="fas fa-trash"></i></button>');
    $btnRemove.on('click', (function (idx) { return function () { alfredChainRemove(idx); }; })(i));
    $side.append($btnRemove);

    return $('<div class="alfred-mcp-entry">').append($card).append($side);
}

function alfredChainUpdateCredField($card, type) {
    var $label = $card.find('.alfred-chain-cred-label');
    var $input = $card.find('.alfred-chain-cred');
    if (type === 'ollama') {
        $label.text('{{Ollama URL}}');
        $input.attr('type', 'text').attr('placeholder', 'http://192.168.1.X:11434');
    } else {
        $label.text('{{API key}}');
        $input.attr('type', 'password').attr('placeholder', '...');
    }
}

function alfredChainMove(i, dir) {
    var j = i + dir;
    if (j < 0 || j >= _alfredChain.length) return;
    var tmp = _alfredChain[i]; _alfredChain[i] = _alfredChain[j]; _alfredChain[j] = tmp;
    alfredChainSerialize();
    alfredChainRender();
}

function alfredChainRemove(i) {
    _alfredChain.splice(i, 1);
    alfredChainSerialize();
    alfredChainRender();
}

function alfredChainTest(type, cred, model, $result) {
    if (!cred) {
        var msg = (type === 'ollama') ? '{{Enter the Ollama URL first.}}' : _alfredI18n.enter_api_key;
        $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + msg + '</span>');
        return;
    }
    $result.html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'testLLM', provider: type, api_key: cred, model: model },
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
        }
    });
}

$('#bt_alfred_chain_add').on('click', function () {
    var type  = <?php echo json_encode(array_key_first($_providers)); ?>;
    var entry = { id: (Math.random().toString(36).slice(2) + Date.now().toString(36)), type: type, model: '', enabled: true };
    if (type === 'ollama') { entry.base_url = ''; } else { entry.api_key = ''; }
    _alfredChain.push(entry);
    alfredChainSerialize();
    alfredChainRender();
});

// Initialize chain from PHP-injected value
(function () {
    try { _alfredChain = JSON.parse(_alfredChainJson); } catch (e) { _alfredChain = []; }
    // Handle old slug-array format in JS
    if (_alfredChain.length > 0 && typeof _alfredChain[0] === 'string') {
        _alfredChain = _alfredChain.map(function (slug) {
            return { id: '', type: slug, api_key: '', model: '', enabled: true };
        });
    }
    alfredChainSerialize();
    alfredChainRender();
})();

// Initialize MCP server list
(function () {
    try { _alfredMcpServers = JSON.parse(_alfredMcpServersJson); } catch (e) { _alfredMcpServers = []; }
    alfredMcpSerialize(true);
    alfredMcpRender();
})();

// ---- Model background-loading for chain cards ----
// Models are loaded silently (no DOM change before response) so the select stays
// open when the user clicks it. Load triggers: page render + credential blur.

$(document).on('blur', '.alfred-chain-cred', function () {
    alfredChainLoadModels($(this).closest('.alfred-mcp-server-card'));
});

function alfredChainLoadModels($card) {
    var type    = $card.find('.alfred-chain-type').val();
    var cred    = $card.find('.alfred-chain-cred').val().trim();
    var $select = $card.find('.alfred-chain-model');

    if (!cred) return;

    // Silent load — keep existing options visible until the response arrives
    var currentVal = $select.val();
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'listModels', provider: type, api_key: cred },
        dataType: 'json',
        success: function (resp) {
            if (resp.state !== 'ok') return;
            var chosen = $select.val() || currentVal;
            $select.empty();
            resp.result.forEach(function (m) {
                $select.append($('<option>').val(m.id).text(m.name));
            });
            if (chosen && $select.find('option[value="' + chosen + '"]').length) {
                $select.val(chosen);
            } else {
                $select.prop('selectedIndex', 0);
                // Persist updated selection back to chain
                var idx = $select.closest('.alfred-mcp-entry').index();
                if (idx >= 0 && _alfredChain[idx]) {
                    _alfredChain[idx].model = $select.val();
                    alfredChainSerialize();
                }
            }
        }
    });
}

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

// ============================================================
// Phones management
// ============================================================

$(document).on('click', '.alfred-phone-delete', function () {
    var id  = $(this).data('id');
    var $tr = $('#alfred-phone-row-' + id);
    if (!confirm('{{Delete this phone and all its push subscriptions?}}')) return;
    var $btn = $(this).prop('disabled', true);
    $.ajax({
        type:     'POST',
        url:      'plugins/alfred/core/ajax/alfred.ajax.php',
        data:     { action: 'deletePhone', eqLogic_id: id },
        dataType: 'json',
        success:  function (data) {
            if (data.state === 'ok') {
                $tr.fadeOut(200, function () { $(this).remove(); });
            } else {
                $btn.prop('disabled', false);
            }
        },
        error: function () { $btn.prop('disabled', false); }
    });
});

// ---- Daily journal: force run ----
(function () {
    var $dateInput = $('#alfred_journal_run_date');
    var yesterday  = new Date();
    yesterday.setDate(yesterday.getDate() - 1);
    $dateInput.val(yesterday.toISOString().slice(0, 10));
})();

$('#bt_alfred_run_journal').on('click', function () {
    var date    = $('#alfred_journal_run_date').val();
    var $btn     = $(this).prop('disabled', true);
    var $spinner = $('#alfred_journal_run_spinner').show();
    var $results = $('#alfred_journal_run_results').hide().empty();

    $.ajax({
        type:     'POST',
        url:      'plugins/alfred/core/ajax/alfred.ajax.php',
        data:     { action: 'runJournal', date: date },
        dataType: 'json',
        success:  function (data) {
            if (data.state !== 'ok') {
                $results.html('<div class="alert alert-danger">' + (data.result || 'Error') + '</div>').show();
                return;
            }
            var entries = data.result;
            if (!entries || entries.length === 0) {
                $results.html('<div class="alert alert-info">{{No conversations found for this day.}}</div>').show();
                return;
            }
            entries.forEach(function (e) {
                var uid  = 'jr_' + Math.random().toString(36).slice(2);
                var html = '<div class="panel panel-default" style="margin-bottom:8px">'
                    + '<div class="panel-heading" style="padding:8px 12px"><strong>' + e.login + '</strong>';
                if (e.skipped) {
                    html += ' <span class="label label-default">{{No conversations}}</span>';
                } else if (!e.result) {
                    html += ' <span class="label label-warning">{{LLM returned empty}}</span>';
                } else {
                    html += ' <span class="label label-success">{{Saved}}</span>';
                }
                html += '</div>';
                if (!e.skipped) {
                    html += '<div class="panel-body" style="padding:10px 12px">';
                    // Prompt
                    html += '<p><strong>{{Prompt}}</strong></p>'
                        + '<pre style="max-height:100px;overflow:auto;font-size:11px;background:#f9f9f9;padding:6px">' + $('<div>').text(e.prompt).html() + '</pre>';
                    // Transcript (collapsible)
                    html += '<p><a data-toggle="collapse" href="#' + uid + '_tr" style="font-size:12px">{{Show transcript}}</a></p>'
                        + '<div id="' + uid + '_tr" class="collapse">'
                        + '<pre style="max-height:200px;overflow:auto;font-size:11px;background:#f9f9f9;padding:6px">' + $('<div>').text(e.transcript).html() + '</pre>'
                        + '</div>';
                    // Result
                    if (e.result) {
                        html += '<p><strong>{{LLM summary}}</strong></p>'
                            + '<div style="background:#f0f7f0;border-left:3px solid #5cb85c;padding:8px 10px;font-size:13px;white-space:pre-wrap">' + $('<div>').text(e.result).html() + '</div>';
                    }
                    html += '</div>';
                }
                html += '</div>';
                $results.append(html);
            });
            $results.show();
        },
        error:    function (jqXHR) {
            $results.html('<div class="alert alert-danger">' + jqXHR.responseText + '</div>').show();
        },
        complete: function () {
            $btn.prop('disabled', false);
            $spinner.hide();
        }
    });
});

$('#bt_alfred_regen_vapid').on('click', function () {
    if (!confirm('{{Regenerate VAPID keys? All registered devices will need to re-enable notifications.}}')) return;
    var $btn    = $(this).prop('disabled', true);
    var $result = $('#alfred_regen_vapid_result');
    $result.html('<i class="fas fa-spinner fa-spin"></i>');
    $.ajax({
        type:     'POST',
        url:      'plugins/alfred/core/ajax/alfred.ajax.php',
        data:     { action: 'regenVapid' },
        dataType: 'json',
        success:  function (data) {
            if (data.state === 'ok') {
                $result.html('<span style="color:#3c763d"><i class="fas fa-check"></i> {{Done — reload page to see new key}}</span>');
            } else {
                $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + (data.result || 'Error') + '</span>');
            }
        },
        error:    function (jqXHR) {
            $result.html('<span style="color:#a94442"><i class="fas fa-times"></i> ' + jqXHR.responseText + '</span>');
        },
        complete: function () { $btn.prop('disabled', false); }
    });
});

</script>
