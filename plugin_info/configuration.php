<?php
if (!isConnect('admin')) {
    throw new Exception('{{401 - Unauthorized access}}');
}

include_file('core', 'alfredMigration', 'class', 'alfred');
$_schemaVersion       = alfredMigration::getVersion();
$_schemaTargetVersion = alfredMigration::getTargetVersion();

// Auto-detect JeedomMCP settings (used for button + placeholder)
$_mcpAutoUrl    = network::getNetworkAccess('internal', 'proto:ip:port:comp') . '/plugins/jeedomMCP/api/mcp.php';
$_mcpAutoApiKey = config::byKey('mcpApiKey', 'jeedomMCP');

// JS i18n strings — always via json_encode to survive apostrophes in translations
$_js_i18n = [
    'mcp_autodetected' => __('JeedomMCP settings auto-detected.', __FILE__),
    'enter_api_key'    => __('Enter the API key first.', __FILE__),
    'loading'          => __('Loading…', __FILE__),
    'error'            => __('Error', __FILE__),
    'up_to_date'       => __('Up to date', __FILE__),
];

$_providers = [
    'anthropic' => 'Anthropic (Claude)',
    'openai'    => 'OpenAI (GPT)',
    'gemini'    => 'Google (Gemini)',
];

$_models = [
    'anthropic' => [
        'claude-sonnet-4-6'        => 'Claude Sonnet 4.6',
        'claude-opus-4-6'          => 'Claude Opus 4.6',
        'claude-haiku-4-5-20251001' => 'Claude Haiku 4.5',
    ],
    'openai' => [
        'gpt-4o'      => 'GPT-4o',
        'gpt-4o-mini' => 'GPT-4o mini',
        'gpt-4-turbo' => 'GPT-4 Turbo',
    ],
    'gemini' => [
        'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
        'gemini-1.5-flash' => 'Gemini 1.5 Flash',
    ],
];
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
        <!-- JeedomMCP -->
        <!-- ================================================================ -->
        <legend><i class="fas fa-plug"></i> JeedomMCP</legend>

        <div class="form-group">
            <div class="col-sm-offset-4 col-sm-8" style="margin-bottom:8px">
                <button type="button" class="btn btn-default btn-sm" id="bt_alfred_autodetect_mcp">
                    <i class="fas fa-magic"></i> {{Auto-detect from JeedomMCP plugin}}
                </button>
            </div>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP URL}}</label>
            <div class="col-sm-5">
                <input type="text" class="configKey form-control" data-l1key="mcp_url"
                       placeholder="<?php echo htmlspecialchars($_mcpAutoUrl); ?>" />
            </div>
            <span class="help-block col-sm-3">{{Internal network URL recommended.}}</span>
        </div>

        <div class="form-group">
            <label class="col-sm-4 control-label">{{JeedomMCP API key}}</label>
            <div class="col-sm-4">
                <input type="password" class="configKey form-control" data-l1key="mcp_api_key"
                       autocomplete="new-password" />
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
            </div>
        </div>

        <div class="form-group">
            <div class="col-sm-offset-1 col-sm-10">
                <table class="table table-bordered table-condensed" id="alfred_memory_table" style="display:none">
                    <thead>
                        <tr>
                            <th style="width:50px">{{ID}}</th>
                            <th style="width:130px">{{Scope}}</th>
                            <th>{{Content}}</th>
                            <th style="width:160px">{{Date}}</th>
                            <th style="width:50px"></th>
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

<script>
var _alfredMcpAutoUrl    = <?php echo json_encode($_mcpAutoUrl); ?>;
var _alfredMcpAutoApiKey = <?php echo json_encode($_mcpAutoApiKey); ?>;
var _alfredI18n          = <?php echo json_encode($_js_i18n); ?>;
var _alfredSchemaTarget  = <?php echo (int) $_schemaTargetVersion; ?>;

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

$('#bt_alfred_autodetect_mcp').on('click', function () {
    $('[data-l1key="mcp_url"]').val(_alfredMcpAutoUrl);
    $('[data-l1key="mcp_api_key"]').val(_alfredMcpAutoApiKey);
    $.fn.showAlert({ message: _alfredI18n.mcp_autodetected, level: 'success' });
});

function alfredShowProvider(provider) {
    $('.alfred-provider-section').hide();
    $('.alfred-provider-section[data-provider="' + provider + '"]').show();
}

// Wait until Jeedom has loaded config values, then show the right provider section
var _alfredPollCount = 0;
var _alfredProviderPoll = setInterval(function () {
    _alfredPollCount++;
    var hasKey = !!($('[data-l1key="anthropic_api_key"]').val()
                  || $('[data-l1key="openai_api_key"]').val()
                  || $('[data-l1key="gemini_api_key"]').val());
    if (hasKey || _alfredPollCount >= 15) {
        clearInterval(_alfredProviderPoll);
        alfredShowProvider($('#alfred_provider').val());
    }
}, 100);

$('#alfred_provider').on('change', function () {
    alfredShowProvider($(this).val());
});

// ---- Lazy model loading ----
// Triggered on first mousedown on the select (before dropdown opens).
// Shows a loading placeholder, fetches, then restores saved value.

var _alfredModelsLoaded = {};

function alfredLoadModels($section) {
    var provider = $section.data('provider');
    if (_alfredModelsLoaded[provider]) return;
    _alfredModelsLoaded[provider] = true;

    var $select  = $section.find('.alfred-model-select');
    var apiKey   = $section.find('.alfred-api-key').val().trim();
    var savedVal = $select.val();

    if (!apiKey) return;

    // Show loading placeholder (prevents seeing stale options)
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
            // Restore saved selection, or pick first available
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
    var date = m.created_at ? m.created_at.substring(0, 16) : '';
    return '<td><small>#' + m.id + '</small></td>'
        + '<td class="alfred-mem-scope-cell">' + scopeBadge + '</td>'
        + '<td class="alfred-mem-content-cell" style="word-break:break-word">' + $('<span>').text(m.content).html() + '</td>'
        + '<td><small>' + date + '</small></td>'
        + '<td style="white-space:nowrap">'
        + '<button class="btn btn-xs btn-default alfred-memory-edit" data-id="' + m.id + '" title="{{Edit}}"><i class="fas fa-pencil-alt"></i></button> '
        + '<button class="btn btn-xs btn-danger alfred-memory-delete" data-id="' + m.id + '" title="{{Delete}}"><i class="fas fa-trash"></i></button>'
        + '</td>';
}

function alfredMemoryRowEdit(m) {
    return '<td><small>#' + m.id + '</small></td>'
        + '<td><select class="form-control input-sm alfred-mem-scope-input" style="min-width:110px">' + alfredScopeOptions(m.scope) + '</select></td>'
        + '<td><textarea class="form-control alfred-mem-content-input" rows="2" style="font-size:12px">' + $('<span>').text(m.content).html() + '</textarea></td>'
        + '<td></td>'
        + '<td style="white-space:nowrap">'
        + '<button class="btn btn-xs btn-success alfred-memory-save" data-id="' + m.id + '" title="{{Save}}"><i class="fas fa-check"></i></button> '
        + '<button class="btn btn-xs btn-default alfred-memory-cancel" data-id="' + m.id + '" title="{{Cancel}}"><i class="fas fa-times"></i></button>'
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
    var content  = $tr.find('.alfred-mem-content-input').val().trim();
    var scope    = $tr.find('.alfred-mem-scope-input').val();
    if (!content) return;
    $btn.prop('disabled', true);
    $.ajax({
        type: 'POST',
        url: 'plugins/alfred/core/ajax/alfred.ajax.php',
        data: { action: 'updateMemory', id: id, content: content, scope: scope },
        dataType: 'json',
        success: function (resp) {
            if (resp.state === 'ok') {
                var m = _alfredMemories.filter(function (x) { return x.id == id; })[0];
                if (m) { m.content = content; m.scope = scope; }
                $tr.html(alfredMemoryRowView(m || { id: id, content: content, scope: scope, created_at: '' }));
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
